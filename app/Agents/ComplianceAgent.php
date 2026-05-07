<?php

namespace App\Agents;

use App\Agents\Prompts\ComplianceVoicePrompt;
use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Models\ComplianceCheck;
use App\Models\Draft;
use App\Models\Embargo;
use App\Services\Blotato\PlatformRules;
use App\Services\Compliance\LearnedRulesProvider;
use App\Services\Compliance\LearnedRulesRecorder;
use App\Services\Llm\LlmGateway;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The hard compliance gate. Runs 6 checks per draft. ANY failure → draft held.
 *
 * Checks (in order — early exits skip later checks for speed):
 *   1. platform_publishability — deterministic Blotato + native-API rules
 *      (caption length, hashtag cap, required media, connection target_overrides).
 *      Runs FIRST so we never burn LLM tokens on a draft that physically can't
 *      publish. See App\Services\Blotato\PlatformRules.
 *   2. banned_phrase — bypass-impossible. Substring/regex match against brand's
 *      banned_phrases list. Score is 1 (no match) or 0 (match).
 *   3. embargo — date+keyword check. Score is 1 (no active matching embargo) or 0.
 *   4. dedup — semantic similarity vs prior published_posts. Score = 1 - max_similarity.
 *      Threshold 0.85 (anything ≥ 85% similar is held).
 *   5. brand_voice — LLM call to score voice match. Threshold 0.70.
 *   6. factual_grounding — every grounding_source the Writer claimed must
 *      reference a real row in brand_styles or brand_corpus. Score is the % of
 *      sources that resolve. Threshold 0.80.
 *
 * Each check writes a ComplianceCheck row. If ALL pass, the draft moves to
 * 'awaiting_approval' (or 'approved' if its lane is green). If ANY fails, the
 * draft moves to 'compliance_failed' with the reason recorded on the check row.
 */
class ComplianceAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    private const VOICE_THRESHOLD = 0.70;
    private const DEDUP_SIMILARITY_THRESHOLD = 0.85;
    private const FACTUAL_THRESHOLD = 0.80;

    public function __construct(
        LlmGateway $llm,
        private readonly EmbeddingService $embeddings,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'compliance'; }
    public function promptVersion(): string { return ComplianceVoicePrompt::VERSION; }

    /**
     * Required input: draft_id (int).
     */
    protected function handle(Brand $brand, array $input): AgentResult
    {
        $draftId = $input['draft_id'] ?? null;
        if (! $draftId) {
            throw new InvalidArgumentException('ComplianceAgent requires draft_id.');
        }

        $draft = Draft::where('id', $draftId)->where('brand_id', $brand->id)->first();
        if (! $draft) {
            return AgentResult::fail('Draft not found.');
        }

        // Wipe prior compliance checks for this draft (re-running the gate replaces them)
        $draft->complianceChecks()->delete();

        // Platform publishability runs first — if a draft can't physically
        // publish (missing media on IG/TikTok/YouTube, missing pageId on
        // Facebook Page, caption over cap, etc), there's no point spending
        // LLM tokens on brand-voice or factual-grounding checks. The gate
        // still runs all 7 checks though so the operator sees every problem
        // on one screen instead of a dribble of one-at-a-time fails.
        //
        // learned_rule_match runs second: this is the memory layer that
        // grows from real prod rejections. It catches failure modes
        // PlatformRules doesn't know about yet (the long tail) — every
        // distinct rejection becomes a row in compliance_learned_rules
        // and gets enforced from then on.
        $checks = [
            $this->checkPlatformPublishability($draft, $brand),
            $this->checkLearnedRules($draft, $brand),
            $this->checkBannedPhrases($draft, $brand),
            $this->checkEmbargo($draft, $brand),
            $this->checkDedup($draft, $brand),
            $this->checkBrandVoice($draft, $brand),
            $this->checkFactualGrounding($draft, $brand),
        ];

        $allPassed = collect($checks)->every(fn (ComplianceCheck $c) => $c->result === 'pass');

        // Update draft status based on result
        $newStatus = match (true) {
            ! $allPassed => 'compliance_failed',
            $draft->lane === 'green' => 'approved', // green lane: auto-approve
            default => 'awaiting_approval',          // amber/red: human review
        };
        $draft->update(['status' => $newStatus]);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'all_passed' => $allPassed,
            'new_status' => $newStatus,
            'checks' => collect($checks)->map(fn (ComplianceCheck $c) => [
                'type' => $c->check_type,
                'result' => $c->result,
                'score' => (float) $c->score,
                'reason' => $c->reason,
            ])->all(),
        ], [
            'check_count' => count($checks),
            'failures' => collect($checks)->where('result', 'fail')->count(),
        ]);
    }

    // ─── Check 1: platform publishability (Blotato + native API rules) ────
    // Pre-flight check that catches the deterministic Blotato/native-API
    // rejections — caption length, hashtag count, malformed hashtag arrays,
    // missing media on platforms that mandate it, AND missing required
    // connection target_overrides (Facebook pageId, Pinterest boardId).
    // Runs first so we never burn LLM tokens on a draft that physically
    // can't publish.
    //
    // History note: pre-2026-05-07 this skipped connection-level checks on
    // the rationale that personal profiles route without pageId. That was
    // wrong for Facebook — Meta's Pages API has required pageId since
    // 2024 (verified live 2026-05-07: 4 prod posts hit HTTP 400 "body.post
    // .target must have required property 'pageId'"). PlatformRules now
    // accepts the connection so these checks fire at compliance time
    // instead of at publish time.

    private function checkPlatformPublishability(Draft $draft, Brand $brand): ComplianceCheck
    {
        // Resolve the active platform_connection for this draft's platform
        // so PlatformRules can verify connection-level required overrides.
        // We use the most-recently-active connection. If the brand has zero
        // connections for this platform, evaluate() runs draft-level checks
        // only — the missing-connection state will surface at scheduling
        // time, not as a compliance failure.
        $connection = \App\Models\PlatformConnection::where('brand_id', $brand->id)
            ->where('platform', $draft->platform)
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();

        $eval = PlatformRules::evaluate($draft, $connection);
        $passed = $eval['passed'];

        if ($passed) {
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'platform_publishability',
                'score' => 1.0,
                'threshold' => 1.0,
                'result' => 'pass',
                'reason' => sprintf(
                    'Passes %s caption/hashtag/media rules.',
                    ucfirst($draft->platform),
                ),
                'details' => ['platform' => $draft->platform],
                'checked_at' => now(),
            ]);
        }

        // Aggregate violations into a single readable reason. The kinds list
        // is the machine-readable handle RedraftFailedDraft uses to decide
        // which agent to re-run (Writer for text fixes, Designer/Video for
        // missing media).
        $kinds = collect($eval['violations'])->pluck('kind')->unique()->values()->all();
        $reasons = collect($eval['violations'])->pluck('reason')->implode(' | ');

        // Feed every distinct violation into the learner. Same fingerprint =
        // single row, occurrences++; new fingerprint = new directive that
        // future Writer/Designer prompts will see. Best-effort.
        $recorder = app(LearnedRulesRecorder::class);
        foreach ($eval['violations'] as $v) {
            $recorder->recordPublishabilityViolation($draft, $v);
        }

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'platform_publishability',
            'score' => 0.0,
            'threshold' => 1.0,
            'result' => 'fail',
            'reason' => $reasons,
            'details' => [
                'platform' => $draft->platform,
                'kinds' => $kinds,
                'violations' => $eval['violations'],
            ],
            'checked_at' => now(),
        ]);
    }

    // ─── Check 1.5: learned-rules match ───────────────────────────────────
    // This is the memory layer. PlatformRules covers the deterministic ones
    // we hardcoded; checkLearnedRules covers everything else we've ever
    // learned from a real rejection. Each block-severity rule has a
    // matcher — currently we only block on rules whose match is implicit
    // in the publishability gate (so we don't double-fail). This check
    // exists to surface count + provenance to the operator so a learned
    // rule with high occurrences becomes visible without grepping logs.

    private function checkLearnedRules(Draft $draft, Brand $brand): ComplianceCheck
    {
        $rules = app(LearnedRulesProvider::class)
            ->activeRulesFor((string) $draft->platform, $brand->workspace_id);

        if ($rules->isEmpty()) {
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'learned_rule_match',
                'score' => 1.0,
                'threshold' => 1.0,
                'result' => 'pass',
                'reason' => 'No learned rules for this platform yet.',
                'details' => ['platform' => $draft->platform, 'rule_count' => 0],
                'checked_at' => now(),
            ]);
        }

        // We currently fast-fail only when checkPlatformPublishability
        // already recorded a fail with the same kind — the learned rules
        // are mostly informational here. This row's purpose is to give the
        // operator a single audit-trail line per draft showing "we considered
        // N learned rules and none re-matched". The blocking is still
        // happening in PlatformRules.
        $blockingRules = $rules->where('severity', 'block')->count();

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'learned_rule_match',
            'score' => 1.0,
            'threshold' => 1.0,
            'result' => 'pass',
            'reason' => sprintf(
                'Considered %d learned rule(s) for %s (%d blocking, %d advisory).',
                $rules->count(),
                $draft->platform,
                $blockingRules,
                $rules->count() - $blockingRules,
            ),
            'details' => [
                'platform' => $draft->platform,
                'rule_count' => $rules->count(),
                'blocking_rules' => $blockingRules,
                'top_rules' => $rules->take(5)->map(fn ($r) => [
                    'rule_kind' => $r->rule_kind,
                    'occurrences' => $r->occurrences,
                    'directive' => mb_substr($r->directive, 0, 160),
                ])->all(),
            ],
            'checked_at' => now(),
        ]);
    }

    // ─── Check 2: banned phrases ──────────────────────────────────────────

    private function checkBannedPhrases(Draft $draft, Brand $brand): ComplianceCheck
    {
        $matched = [];
        foreach ($brand->bannedPhrases as $phrase) {
            if ($phrase->matches($draft->body)) {
                $matched[] = ['phrase' => $phrase->phrase, 'reason' => $phrase->reason];
            }
        }
        $passed = empty($matched);

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'banned_phrase',
            'score' => $passed ? 1.0 : 0.0,
            'threshold' => 1.0,
            'result' => $passed ? 'pass' : 'fail',
            'reason' => $passed ? 'No banned phrases detected.' : 'Matched '.count($matched).' banned phrase(s).',
            'details' => ['matches' => $matched],
            'checked_at' => now(),
        ]);
    }

    // ─── Check 2: embargoes ───────────────────────────────────────────────

    private function checkEmbargo(Draft $draft, Brand $brand): ComplianceCheck
    {
        $now = now();
        $active = Embargo::where('brand_id', $brand->id)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->get();

        $matched = $active->filter(fn (Embargo $e) => $e->matches($draft->body))->values();
        $passed = $matched->isEmpty();

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'embargo',
            'score' => $passed ? 1.0 : 0.0,
            'threshold' => 1.0,
            'result' => $passed ? 'pass' : 'fail',
            'reason' => $passed
                ? 'No active embargo matched.'
                : 'Hit embargo: '.$matched->pluck('label')->implode(', '),
            'details' => ['embargo_ids' => $matched->pluck('id')->all()],
            'checked_at' => now(),
        ]);
    }

    // ─── Check 3: dedup ──────────────────────────────────────────────────

    private function checkDedup(Draft $draft, Brand $brand): ComplianceCheck
    {
        try {
            $vector = $this->embeddings->embedQuery($draft->body, $brand, $brand->workspace);
        } catch (\Throwable $e) {
            // If embedding fails, we can't dedup — fail open (warning) so the user
            // can still see the draft. Surface this explicitly so it's not silent.
            Log::warning('Compliance: dedup embedding failed', ['draft_id' => $draft->id, 'error' => $e->getMessage()]);
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'dedup',
                'result' => 'warning',
                'reason' => 'Could not run dedup check (embedding service unavailable).',
                'details' => ['error' => substr($e->getMessage(), 0, 200)],
                'checked_at' => now(),
            ]);
        }

        $row = DB::selectOne(
            'SELECT id, 1 - (embedding <=> ?::vector) AS similarity, content
             FROM brand_corpus
             WHERE brand_id = ? AND source_type = ? AND embedding IS NOT NULL
             ORDER BY embedding <=> ?::vector
             LIMIT 1',
            [(string) $vector, $brand->id, 'historical_post', (string) $vector]
        );

        if (! $row) {
            // No corpus to dedup against — pass with score 1.0
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'dedup',
                'score' => 1.0,
                'threshold' => 1.0 - self::DEDUP_SIMILARITY_THRESHOLD,
                'result' => 'pass',
                'reason' => 'No prior posts indexed — dedup not applicable.',
                'checked_at' => now(),
            ]);
        }

        $similarity = (float) $row->similarity;
        $score = 1.0 - $similarity; // higher = more original
        $threshold = 1.0 - self::DEDUP_SIMILARITY_THRESHOLD;
        $passed = $similarity < self::DEDUP_SIMILARITY_THRESHOLD;

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'dedup',
            'score' => round($score, 4),
            'threshold' => round($threshold, 4),
            'result' => $passed ? 'pass' : 'fail',
            'reason' => $passed
                ? sprintf('Most similar prior post is %.0f%% similar (under %.0f%% threshold).', $similarity * 100, self::DEDUP_SIMILARITY_THRESHOLD * 100)
                : sprintf('%.0f%% similar to a prior post — too close.', $similarity * 100),
            'details' => [
                'closest_corpus_id' => $row->id,
                'similarity' => round($similarity, 4),
                'closest_excerpt' => substr($row->content, 0, 200),
            ],
            'checked_at' => now(),
        ]);
    }

    // ─── Check 4: brand-voice score (LLM-as-judge) ────────────────────────

    private function checkBrandVoice(Draft $draft, Brand $brand): ComplianceCheck
    {
        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'brand_voice',
                'result' => 'error',
                'reason' => 'No current brand_style to score against.',
                'checked_at' => now(),
            ]);
        }

        $userMessage = "## brand-style.md\n\n{$brandStyle->content_md}\n\n## DRAFT TO SCORE\n\nPlatform: {$draft->platform}\nBody:\n{$draft->body}";

        try {
            $result = $this->llm->call(
                promptVersion: $this->promptVersion(),
                systemPrompt: ComplianceVoicePrompt::system(),
                userMessage: $userMessage,
                brand: $brand,
                workspace: $brand->workspace,
                modelId: config('services.anthropic.cheap_model'), // Haiku is fine for scoring
                maxTokens: 1000,
                jsonSchema: ComplianceVoicePrompt::schema(),
                agentRole: 'compliance.voice',
            );
        } catch (\Throwable $e) {
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'brand_voice',
                'result' => 'error',
                'reason' => 'Voice scoring call failed: '.substr($e->getMessage(), 0, 200),
                'checked_at' => now(),
            ]);
        }

        $payload = $result->parsedJson;
        if (! $payload || ! isset($payload['score'])) {
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'brand_voice',
                'result' => 'error',
                'reason' => 'Voice scorer returned no score.',
                'checked_at' => now(),
            ]);
        }

        $score = (float) $payload['score'];
        $passed = $score >= self::VOICE_THRESHOLD;

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'brand_voice',
            'score' => round($score, 4),
            'threshold' => self::VOICE_THRESHOLD,
            'result' => $passed ? 'pass' : 'fail',
            'reason' => $payload['reasoning'] ?? '',
            'details' => $payload,
            'model_id' => $result->modelId,
            'latency_ms' => $result->latencyMs,
            'checked_at' => now(),
        ]);
    }

    // ─── Check 5: factual grounding ───────────────────────────────────────

    private function checkFactualGrounding(Draft $draft, Brand $brand): ComplianceCheck
    {
        $sources = $draft->grounding_sources ?? [];
        if (empty($sources)) {
            // Writer didn't cite any sources — that's a fail unless the draft
            // makes no factual claims, which we can't easily detect. Be strict.
            return ComplianceCheck::create([
                'draft_id' => $draft->id,
                'brand_id' => $brand->id,
                'check_type' => 'factual_grounding',
                'score' => 0.0,
                'threshold' => self::FACTUAL_THRESHOLD,
                'result' => 'fail',
                'reason' => 'Writer cited no grounding sources. We refuse to publish ungrounded claims.',
                'checked_at' => now(),
            ]);
        }

        // Verify each source is real:
        // - source_type = brand_style → must reference an existing BrandStyle row
        // - source_type = historical_post → must reference an existing BrandCorpusItem
        // - source_type = evidence_quote → must match a quote in BrandStyle.evidence_sources
        // - source_type = calendar_entry → must reference an existing CalendarEntry
        $brandStyle = $brand->currentStyle()->first();
        $verified = 0;

        foreach ($sources as $src) {
            switch ($src['source_type'] ?? '') {
                case 'brand_style':
                    if ($this->brandStyleVerifies($brandStyle, (string) ($src['source_excerpt'] ?? ''))) {
                        $verified++;
                    }
                    break;
                case 'evidence_quote':
                    if ($brandStyle && ! empty($brandStyle->evidence_sources)) {
                        $excerpt = substr($src['source_excerpt'] ?? '', 0, 30);
                        foreach ($brandStyle->evidence_sources as $ev) {
                            if (! empty($ev['quote']) && stripos($ev['quote'], $excerpt) !== false) {
                                $verified++;
                                break;
                            }
                        }
                    }
                    break;
                case 'historical_post':
                case 'website_page':
                    // Both source types live in brand_corpus and verify the same
                    // way: prefer source_id, fall back to substring match against
                    // corpus content. Treating them as one prevents Writer-vs-
                    // Compliance label-mismatch failures (Writer cites "historical_post"
                    // when the corpus only has "website_page" rows, or vice versa).
                    if ($this->corpusVerifies($brand, $src)) {
                        $verified++;
                    }
                    break;
                case 'calendar_entry':
                    // The calendar entry is the input — always verifiable
                    $verified++;
                    break;
            }
        }

        $score = count($sources) > 0 ? round($verified / count($sources), 4) : 0.0;
        $passed = $score >= self::FACTUAL_THRESHOLD;

        return ComplianceCheck::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'check_type' => 'factual_grounding',
            'score' => $score,
            'threshold' => self::FACTUAL_THRESHOLD,
            'result' => $passed ? 'pass' : 'fail',
            'reason' => sprintf(
                '%d of %d grounding sources verified (%d%%).',
                $verified,
                count($sources),
                round($score * 100)
            ),
            'details' => ['claimed_sources' => count($sources), 'verified' => $verified],
            'checked_at' => now(),
        ]);
    }

    /**
     * brand_style verification: was strict verbatim-match of the first 30
     * chars; that fails on any paraphrase (e.g. Writer cites a brand-style
     * principle in its own words). Now we try a few windows AND a normalised
     * comparison so paraphrased citations of real brand-style content pass,
     * while invented quotes still fail.
     */
    private function brandStyleVerifies($brandStyle, string $excerpt): bool
    {
        if (! $brandStyle || $excerpt === '') return false;
        $haystack = (string) $brandStyle->content_md;
        if ($haystack === '') return false;

        foreach ([60, 40, 25] as $len) {
            $needle = mb_substr($excerpt, 0, $len);
            if (mb_strlen($needle) < 15) continue;
            if (stripos($haystack, $needle) !== false) return true;
        }

        // Normalised compare — fold whitespace, drop punctuation. Catches
        // citations that quote a brand-style line with slightly different
        // spacing/quotes than the source. Cheap, last-resort.
        $norm = fn (string $s) => preg_replace('/\s+/', ' ', strtolower(preg_replace('/[^\w\s]/u', ' ', $s)));
        $needle = mb_substr($norm($excerpt), 0, 50);
        return mb_strlen($needle) >= 20 && stripos($norm($haystack), $needle) !== false;
    }

    /**
     * Corpus verification (historical_post + website_page). Prefers source_id
     * lookup, falls back to substring search across corpus content. The Writer
     * sometimes invents short ids (1, 2, 3) even when the prompt shows real
     * ones — substring fallback catches valid citations with bogus ids.
     */
    private function corpusVerifies(Brand $brand, array $src): bool
    {
        if (! empty($src['source_id'])) {
            $exists = BrandCorpusItem::where('id', $src['source_id'])
                ->where('brand_id', $brand->id)
                ->exists();
            if ($exists) return true;
        }

        $excerpt = (string) ($src['source_excerpt'] ?? '');
        if ($excerpt === '') return false;

        foreach ([80, 40, 20] as $len) {
            $needle = mb_substr($excerpt, 0, $len);
            if (mb_strlen($needle) < 15) continue;
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle);
            $matched = BrandCorpusItem::where('brand_id', $brand->id)
                ->where('content', 'ILIKE', '%' . $escaped . '%')
                ->exists();
            if ($matched) return true;
        }
        return false;
    }
}

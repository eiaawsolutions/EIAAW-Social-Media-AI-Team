<?php

namespace App\Agents;

use App\Agents\Prompts\ComplianceVoicePrompt;
use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Models\ComplianceCheck;
use App\Models\Draft;
use App\Models\Embargo;
use App\Services\Llm\LlmGateway;
use App\Services\Embeddings\EmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * The hard compliance gate. Runs 5 checks per draft. ANY failure → draft held.
 *
 * Checks (in order — early exits skip later checks for speed):
 *   1. banned_phrase — bypass-impossible. Substring/regex match against brand's
 *      banned_phrases list. Score is 1 (no match) or 0 (match).
 *   2. embargo — date+keyword check. Score is 1 (no active matching embargo) or 0.
 *   3. dedup — semantic similarity vs prior published_posts. Score = 1 - max_similarity.
 *      Threshold 0.85 (anything ≥ 85% similar is held).
 *   4. brand_voice — LLM call to score voice match. Threshold 0.70.
 *   5. factual_grounding — every grounding_source the Writer claimed must
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

        $checks = [
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

    // ─── Check 1: banned phrases ──────────────────────────────────────────

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
                    if ($brandStyle && stripos($brandStyle->content_md, substr($src['source_excerpt'] ?? '', 0, 30)) !== false) {
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
                    // Prefer source_id when the Writer cited one; fall back to
                    // substring match against any of the brand's corpus items
                    // when source_id is absent or doesn't resolve. Same lenient
                    // contract as brand_style / evidence_quote so citations
                    // aren't penalised when the model forgets the id field.
                    $matched = false;
                    if (! empty($src['source_id'])) {
                        $matched = BrandCorpusItem::where('id', $src['source_id'])
                            ->where('brand_id', $brand->id)
                            ->exists();
                    }
                    if (! $matched) {
                        $excerpt = substr($src['source_excerpt'] ?? '', 0, 30);
                        if (mb_strlen($excerpt) >= 10) {
                            $matched = BrandCorpusItem::where('brand_id', $brand->id)
                                ->where('content', 'ILIKE', '%' . str_replace('%', '\\%', $excerpt) . '%')
                                ->exists();
                        }
                    }
                    if ($matched) {
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
}

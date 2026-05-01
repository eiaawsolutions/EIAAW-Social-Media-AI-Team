<?php

namespace App\Agents;

use App\Agents\Prompts\WriterPrompt;
use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Llm\LlmGateway;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Drafts a single post for one (calendar entry, platform) pair.
 *
 * Flow:
 *   1. Resolve calendar_entry → topic + angle + visual_direction.
 *   2. Pull the brand_style.md (current version).
 *   3. Retrieve top-5 most similar brand_corpus historical posts (RAG via pgvector).
 *   4. Send to Claude with the WriterPrompt schema.
 *   5. Persist Draft row with full provenance.
 *   6. Hand off to ComplianceAgent (caller's responsibility — Writer doesn't run Compliance itself).
 *
 * Why Writer doesn't run Compliance directly: Compliance is a separate audit
 * surface. Drafts can be generated for review without auto-running every check
 * (e.g. for human-only A/B comparisons). The default pipeline runs both in
 * sequence; the Writer just produces and hands off.
 */
class WriterAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    public function __construct(
        LlmGateway $llm,
        private readonly EmbeddingService $embeddings,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'writer'; }
    public function promptVersion(): string { return WriterPrompt::VERSION; }

    /**
     * Required input keys:
     *   - calendar_entry_id (int)
     *   - platform (string — must be one of WriterPrompt::PLATFORM_LIMITS)
     */
    protected function handle(Brand $brand, array $input): AgentResult
    {
        $entryId = $input['calendar_entry_id'] ?? null;
        $platform = $input['platform'] ?? null;

        if (! $entryId || ! $platform) {
            throw new InvalidArgumentException('WriterAgent requires calendar_entry_id and platform.');
        }
        if (! array_key_exists($platform, WriterPrompt::PLATFORM_LIMITS)) {
            throw new InvalidArgumentException("Unsupported platform: $platform");
        }

        $entry = CalendarEntry::where('id', $entryId)->where('brand_id', $brand->id)->first();
        if (! $entry) {
            return AgentResult::fail('Calendar entry not found for this brand.');
        }

        $brandStyle = $brand->currentStyle()->first();
        // currentStyle is enforced by RequiresReadiness — but defensive null check
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice not synthesised yet.');
        }

        // RAG: top-5 most similar historical posts
        $similar = $this->retrieveSimilarPosts($brand, $entry, $platform);

        $userMessage = $this->buildUserMessage($brand, $brandStyle->content_md, $entry, $similar, $platform);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: WriterPrompt::system($platform),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 3000,
            jsonSchema: WriterPrompt::schema($platform),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! $payload || empty($payload['body'])) {
            return AgentResult::fail('Writer returned empty body. Try again.');
        }

        $draft = DB::transaction(function () use ($brand, $entry, $platform, $payload, $similar, $result, $userMessage) {
            return Draft::create([
                'brand_id' => $brand->id,
                'calendar_entry_id' => $entry->id,
                'platform' => $platform,
                'content_type' => 'caption',
                'body' => $payload['body'],
                'hashtags' => $payload['hashtags'] ?? [],
                'mentions' => $payload['mentions'] ?? [],
                // Provenance
                'agent_role' => $this->role(),
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'prompt_inputs' => [
                    'calendar_entry_id' => $entry->id,
                    'brand_style_version' => $brand->currentStyle->version ?? null,
                    'platform' => $platform,
                    'similar_post_ids' => array_column($similar, 'id'),
                ],
                'grounding_sources' => $payload['grounding_sources'] ?? [],
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_usd' => $result->costUsd,
                'latency_ms' => $result->latencyMs,
                // Approval state — start in compliance_pending so Compliance picks it up
                'status' => 'compliance_pending',
                'lane' => $brand->defaultLaneFor($platform),
            ]);
        });

        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'draft_id' => $draft->id,
            'platform' => $draft->platform,
            'body_preview' => substr($draft->body, 0, 140),
            'char_count' => strlen($draft->body),
            'grounding_count' => count($draft->grounding_sources ?? []),
            'lane' => $draft->lane,
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /**
     * Retrieve similar prior posts via pgvector cosine distance.
     * Returns top-5 candidate posts for grounding.
     *
     * @return array<int, array{id: int, content: string, source_url: ?string, similarity: float}>
     */
    private function retrieveSimilarPosts(Brand $brand, CalendarEntry $entry, string $platform): array
    {
        $queryText = trim("{$entry->topic}\n{$entry->angle}\nplatform: {$platform}");

        try {
            $vector = $this->embeddings->embedQuery($queryText, $brand, $brand->workspace);
        } catch (\Throwable $e) {
            Log::warning('Writer: embedding query failed, skipping RAG', ['error' => $e->getMessage()]);
            return [];
        }

        // pgvector cosine distance: 1 - (a <=> b) gives similarity. We want the
        // closest 5 historical posts — but only if there's any corpus.
        $rows = BrandCorpusItem::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?', [(string) $vector])
            ->limit(5)
            ->get(['id', 'content', 'source_url', 'source_label']);

        return $rows->map(fn (BrandCorpusItem $r) => [
            'id' => $r->id,
            'content' => substr($r->content, 0, 800),
            'source_url' => $r->source_url,
            'source_label' => $r->source_label,
        ])->all();
    }

    private function buildUserMessage(Brand $brand, string $brandStyleMd, CalendarEntry $entry, array $similar, string $platform): string
    {
        $similarBlock = empty($similar)
            ? "(no historical posts indexed yet — ground in brand-style only)"
            : collect($similar)->map(fn ($s, $i) => "[".($i+1)."] ".$s['content'])->implode("\n\n---\n\n");

        return <<<MSG
BRAND: {$brand->name}
PLATFORM: {$platform}

# Calendar entry to write
- Topic: {$entry->topic}
- Angle: {$entry->angle}
- Pillar: {$entry->pillar}
- Format: {$entry->format}
- Objective: {$entry->objective}
- Visual direction: {$entry->visual_direction}

# brand-style.md (single source of truth)
{$brandStyleMd}

# Top similar prior posts (for voice grounding — DO cite if your phrasing borrows from them)
{$similarBlock}

Now draft the post per the schema. Only write the JSON object.
MSG;
    }
}

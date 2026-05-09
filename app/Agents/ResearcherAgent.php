<?php

namespace App\Agents;

use App\Agents\Prompts\ResearcherPrompt;
use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Models\CalendarEntry;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Sits between Strategist and Writer. For one calendar entry, produces a
 * research_brief — 5 distinct cited angles — that gives the Writer real
 * material to work with instead of a single one-line "angle".
 *
 * Cost profile: one Sonnet call per entry, ~$0.02–0.04. Only triggered
 * lazily (when DraftCalendarEntry runs and finds research_brief is null
 * or stale) so we never pay for entries the operator drops.
 *
 * Failure mode: returns AgentResult::ok with no brief if the corpus is
 * too small or the model returns malformed JSON. The Writer falls back
 * to the original angle when research_brief is null. NEVER blocks
 * drafting — research is best-effort.
 */
class ResearcherAgent extends BaseAgent
{
    /** Brand voice must exist; corpus may be empty (we'll cite brand_style only). */
    protected array $requiredStages = ['brand_style'];

    public function __construct(
        LlmGateway $llm,
        private readonly EmbeddingService $embeddings,
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'researcher'; }
    public function promptVersion(): string { return ResearcherPrompt::VERSION; }

    /**
     * Required input:
     *   - calendar_entry_id (int)
     *
     * Optional:
     *   - force (bool): re-run even if research_brief is already populated.
     */
    protected function handle(Brand $brand, array $input): AgentResult
    {
        $entryId = $input['calendar_entry_id'] ?? null;
        if (! $entryId) {
            throw new InvalidArgumentException('ResearcherAgent requires calendar_entry_id.');
        }

        $entry = CalendarEntry::where('id', $entryId)->where('brand_id', $brand->id)->first();
        if (! $entry) {
            return AgentResult::fail('Calendar entry not found for this brand.');
        }

        $force = (bool) ($input['force'] ?? false);
        if (! $force && is_array($entry->research_brief) && ! empty($entry->research_brief['angles'] ?? [])) {
            return AgentResult::ok([
                'calendar_entry_id' => $entry->id,
                'cached' => true,
                'angle_count' => count($entry->research_brief['angles']),
            ]);
        }

        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice not synthesised yet.');
        }

        // RAG: top-8 corpus snippets for the topic. Slightly wider than
        // Writer's top-5 because Researcher synthesises across 5 angles
        // and benefits from more raw material per angle.
        $similar = $this->retrieveSimilarPosts($brand, $entry);

        $userMessage = $this->buildUserMessage($brand, $brandStyle->content_md, $entry, $similar);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: ResearcherPrompt::system(),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 4000,
            jsonSchema: ResearcherPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        $angles = is_array($payload['angles'] ?? null) ? $payload['angles'] : [];

        if (empty($angles)) {
            // Best-effort: don't fail the pipeline. Writer falls back to angle.
            Log::info('Researcher: empty angles array, leaving research_brief null', [
                'calendar_entry_id' => $entry->id,
            ]);
            return AgentResult::ok([
                'calendar_entry_id' => $entry->id,
                'angle_count' => 0,
                'fallback' => true,
            ], [
                'model' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => $result->costUsd,
                'latency_ms' => $result->latencyMs,
            ]);
        }

        // Filter source_ids to only valid corpus ids we actually showed
        // the model. Hallucinated ids get dropped silently — same defence
        // pattern Writer uses for grounding_sources.
        $allowedIds = collect($similar)->pluck('id')->all();
        foreach ($angles as &$angle) {
            $ids = is_array($angle['source_ids'] ?? null) ? $angle['source_ids'] : [];
            $angle['source_ids'] = array_values(array_intersect(
                array_map('intval', $ids),
                $allowedIds,
            ));
        }
        unset($angle);

        $entry->forceFill([
            'research_brief' => [
                'angles' => array_slice($angles, 0, 5), // hard cap at 5 even if model overshoots
                'generated_at' => now()->toIso8601String(),
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'cost_usd' => (float) ($result->costUsd ?? 0),
                'corpus_size_seen' => count($similar),
            ],
        ])->save();

        return AgentResult::ok([
            'calendar_entry_id' => $entry->id,
            'angle_count' => count($entry->research_brief['angles']),
            'cached' => false,
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /**
     * Top-8 corpus snippets for this entry's topic via pgvector cosine
     * distance. Mirrors WriterAgent::retrieveSimilarPosts but at width=8.
     *
     * @return array<int, array{id:int, content:string, source_url:?string, source_label:?string, source_type:?string}>
     */
    private function retrieveSimilarPosts(Brand $brand, CalendarEntry $entry): array
    {
        $queryText = trim("{$entry->topic}\n{$entry->angle}");

        try {
            $vector = $this->embeddings->embedQuery($queryText, $brand, $brand->workspace);
        } catch (\Throwable $e) {
            Log::warning('Researcher: embedding query failed, no corpus context', ['error' => $e->getMessage()]);
            return [];
        }

        $rows = BrandCorpusItem::query()
            ->where('brand_id', $brand->id)
            ->whereNotNull('embedding')
            ->orderByRaw('embedding <=> ?', [(string) $vector])
            ->limit(8)
            ->get(['id', 'content', 'source_url', 'source_label', 'source_type']);

        return $rows->map(fn (BrandCorpusItem $r) => [
            'id' => $r->id,
            'content' => substr((string) $r->content, 0, 800),
            'source_url' => $r->source_url,
            'source_label' => $r->source_label,
            'source_type' => $r->source_type,
        ])->all();
    }

    private function buildUserMessage(
        Brand $brand,
        string $brandStyleMd,
        CalendarEntry $entry,
        array $similar,
    ): string {
        $allowedIds = collect($similar)->pluck('id')->implode(', ');
        $evidenceBlock = empty($similar)
            ? '(no corpus snippets indexed yet — ground angles in brand-style only; source_ids array stays empty)'
            : collect($similar)
                ->map(fn ($s) => "[id={$s['id']} type=".($s['source_type'] ?? 'historical_post').($s['source_url'] ? " url={$s['source_url']}" : '')."] {$s['content']}")
                ->implode("\n\n---\n\n");

        $idsLine = $allowedIds ? "VALID source_ids you may cite (use only these integers): {$allowedIds}\n\n" : '';

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}

# Calendar entry to deepen
- Topic: {$entry->topic}
- Strategist's angle: {$entry->angle}
- Pillar: {$entry->pillar}
- Format: {$entry->format}
- Objective: {$entry->objective}

# brand-style.md (voice + values + evidence guardrail)
{$brandStyleMd}

# EVIDENCE — top-8 brand corpus snippets matched to the topic

{$idsLine}{$evidenceBlock}

Produce 5 distinct angles per the schema. Each angle's evidence must be a verbatim phrase from one of the [id=N] blocks above (or from brand-style.md if no corpus matches). Cite source_ids accurately — fake ids will be discarded.
MSG;
    }
}

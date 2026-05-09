<?php

namespace App\Agents;

use App\Agents\Prompts\RepurposePrompt;
use App\Agents\Prompts\WriterPrompt;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Services\Llm\LlmGateway;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Fans a master draft out into platform-specific derivatives that share
 * the master's hook + narrative spine + CTA but are rewritten for each
 * target platform's conventions and character limit.
 *
 * Triggered ONLY when calendar_entry.is_pillar = true. For non-pillar
 * entries, the existing flow (one independent Writer call per platform)
 * is unchanged.
 *
 * Why a separate agent rather than reusing Writer:
 *   - Different prompt: Writer drafts from topic; Repurpose adapts from
 *     an existing approved master.
 *   - Different audit signal: derivatives surface in /agency/drafts as
 *     "derivative of master #N", with a parent_draft_id link the operator
 *     can navigate.
 *   - Different cost profile: a derivative is typically shorter than a
 *     greenfield draft — less reasoning, lower latency.
 *   - Decoupled rejection: rejecting the master can cascade-cancel its
 *     pending derivatives without going through Writer's bookkeeping.
 *
 * Idempotent: skips platforms that already have a draft for this entry
 * (the master's own platform, or a derivative produced on a previous run).
 */
class RepurposeAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    public function __construct(LlmGateway $llm)
    {
        parent::__construct($llm);
    }

    public function role(): string { return 'repurpose'; }
    public function promptVersion(): string { return RepurposePrompt::VERSION; }

    /**
     * Required input:
     *   - master_draft_id (int): the approved or generated master to fan out from.
     *   - target_platforms (array<string>): platforms to produce derivatives for.
     *     The master's own platform is automatically excluded.
     */
    protected function handle(Brand $brand, array $input): AgentResult
    {
        $masterId = $input['master_draft_id'] ?? null;
        $targets = (array) ($input['target_platforms'] ?? []);

        if (! $masterId) {
            throw new InvalidArgumentException('RepurposeAgent requires master_draft_id.');
        }

        $master = Draft::where('id', $masterId)->where('brand_id', $brand->id)->first();
        if (! $master) {
            return AgentResult::fail('Master draft not found for this brand.');
        }

        $entry = $master->calendarEntry;
        if (! $entry) {
            return AgentResult::fail('Master draft has no calendar entry — cannot determine target platforms.');
        }

        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice not synthesised yet.');
        }

        // Drop the master's own platform from targets, plus any platform
        // that already has a non-rejected draft for this entry.
        $existingPlatforms = $entry->drafts()
            ->whereNotIn('status', ['rejected'])
            ->pluck('platform')
            ->all();

        $targetPlatforms = array_values(array_unique(array_filter(
            array_map(fn ($p) => is_string($p) ? strtolower($p) : null, $targets),
            fn ($p) => $p
                && $p !== strtolower((string) $master->platform)
                && ! in_array($p, $existingPlatforms, true)
                && array_key_exists($p, WriterPrompt::PLATFORM_LIMITS),
        )));

        if (empty($targetPlatforms)) {
            return AgentResult::ok([
                'master_draft_id' => $master->id,
                'derivatives_created' => 0,
                'reason' => 'all targets already drafted or invalid',
            ]);
        }

        $created = [];
        $totalCost = 0.0;
        $totalLatency = 0;

        foreach ($targetPlatforms as $platform) {
            try {
                $derivative = $this->repurposeOne($brand, $master, $entry, $brandStyle->content_md, $platform);
                if ($derivative) {
                    $created[] = ['draft_id' => $derivative->id, 'platform' => $platform];
                    $totalCost += (float) ($derivative->cost_usd ?? 0);
                    $totalLatency += (int) ($derivative->latency_ms ?? 0);
                }
            } catch (\Throwable $e) {
                Log::warning('RepurposeAgent: derivative failed', [
                    'master_draft_id' => $master->id,
                    'platform' => $platform,
                    'error' => substr($e->getMessage(), 0, 240),
                ]);
            }
        }

        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'master_draft_id' => $master->id,
            'derivatives_created' => count($created),
            'derivatives' => $created,
        ], [
            'cost_usd' => $totalCost,
            'latency_ms' => $totalLatency,
        ]);
    }

    /**
     * Produce one platform-specific derivative from the master. Returns the
     * persisted Draft, or null if the LLM returned malformed JSON.
     */
    private function repurposeOne(
        Brand $brand,
        Draft $master,
        CalendarEntry $entry,
        string $brandStyleMd,
        string $platform,
    ): ?Draft {
        $userMessage = $this->buildUserMessage($brand, $brandStyleMd, $entry, $master, $platform);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: RepurposePrompt::system($platform, $brand->workspace_id),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 3000,
            jsonSchema: RepurposePrompt::schema($platform),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! $payload || empty($payload['body'])) {
            Log::warning('RepurposeAgent: empty body returned', [
                'master_draft_id' => $master->id,
                'platform' => $platform,
            ]);
            return null;
        }

        return DB::transaction(function () use ($brand, $master, $entry, $platform, $payload, $result) {
            $bodyCap = WriterPrompt::PLATFORM_LIMITS[$platform] ?? 1000;
            $body = mb_substr((string) ($payload['body'] ?? ''), 0, $bodyCap);
            $hashtags = array_slice($payload['hashtags'] ?? [], 0, 30);
            $brandingPayload = $this->extractBrandingPayload($payload);

            return Draft::create([
                'brand_id' => $brand->id,
                'calendar_entry_id' => $entry->id,
                'parent_draft_id' => $master->id,
                'platform' => $platform,
                'content_type' => 'caption',
                'body' => $body,
                'hashtags' => $hashtags,
                'mentions' => $payload['mentions'] ?? [],
                'branding_payload' => $brandingPayload,
                // Provenance
                'agent_role' => $this->role(),
                'model_id' => $result->modelId,
                'prompt_version' => $result->promptVersion,
                'prompt_inputs' => [
                    'calendar_entry_id' => $entry->id,
                    'master_draft_id' => $master->id,
                    'master_platform' => $master->platform,
                    'brand_style_version' => $brand->currentStyle->version ?? null,
                    'platform' => $platform,
                ],
                'grounding_sources' => $payload['grounding_sources'] ?? [],
                'input_tokens' => $result->inputTokens,
                'output_tokens' => $result->outputTokens,
                'cost_usd' => $result->costUsd,
                'latency_ms' => $result->latencyMs,
                'status' => 'compliance_pending',
                'lane' => $brand->defaultLaneFor($platform),
            ]);
        });
    }

    /**
     * Mirrors WriterAgent::extractBrandingPayload — same shape, same cleaning.
     * Keeps Designer + Video happy on derivatives without a second distil call.
     *
     * @return array{quote:string, voiceover:string, distilled_at:string, source:string}|null
     */
    private function extractBrandingPayload(array $payload): ?array
    {
        $quote = trim((string) ($payload['quote'] ?? ''));
        $voiceover = trim((string) ($payload['voiceover'] ?? ''));

        $quote = preg_replace('/^[\"\'\x{201C}\x{2018}]+|[\"\'\x{201D}\x{2019}]+$/u', '', $quote) ?? $quote;
        $quote = trim($quote);

        if ($quote === '' && $voiceover === '') return null;

        return [
            'quote' => $quote,
            'voiceover' => $voiceover,
            'distilled_at' => now()->toIso8601String(),
            'source' => 'repurpose',
        ];
    }

    private function buildUserMessage(
        Brand $brand,
        string $brandStyleMd,
        CalendarEntry $entry,
        Draft $master,
        string $platform,
    ): string {
        $masterBranding = is_array($master->branding_payload) ? $master->branding_payload : [];
        $masterQuote = trim((string) ($masterBranding['quote'] ?? ''));
        $masterVoiceover = trim((string) ($masterBranding['voiceover'] ?? ''));
        $masterHashtags = is_array($master->hashtags) ? implode(', ', $master->hashtags) : '';
        $masterPlatform = ucfirst((string) $master->platform);

        $brandingExtras = '';
        if ($masterQuote !== '') $brandingExtras .= "\n- Master quote: {$masterQuote}";
        if ($masterVoiceover !== '') $brandingExtras .= "\n- Master voiceover: {$masterVoiceover}";

        return <<<MSG
BRAND: {$brand->name}
TARGET PLATFORM: {$platform}
SOURCE: master draft #{$master->id} ({$masterPlatform})

# Calendar entry context
- Topic: {$entry->topic}
- Pillar: {$entry->pillar}
- Format: {$entry->format}
- Objective: {$entry->objective}

# Master draft to repurpose (from {$masterPlatform})
<<<MASTER
{$master->body}
MASTER

- Master hashtags: {$masterHashtags}{$brandingExtras}

# brand-style.md (single source of truth)
{$brandStyleMd}

Now produce the {$platform}-native derivative per the schema. Same hook + spine + CTA as the master, rewritten for {$platform}'s conventions. Only write the JSON object.
MSG;
    }
}

<?php

namespace App\Agents;

use App\Agents\Prompts\StrategistPrompt;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\ContentCalendar;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds a month of content. Stage 6 of the wizard.
 *
 * Inputs: brand_style + active platform connections + pillar/format mix targets.
 * Outputs: a ContentCalendar with N CalendarEntry rows.
 *
 * Default mix is sensible if the user hasn't specified one. They can override
 * via $input['pillar_mix'] / $input['format_mix'].
 */
class StrategistAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style', 'platform_connected'];

    private const DEFAULT_PILLAR_MIX = [
        'educational' => 0.30,
        'community' => 0.25,
        'promotional' => 0.15,
        'behind_the_scenes' => 0.15,
        'thought_leadership' => 0.15,
    ];

    private const DEFAULT_FORMAT_MIX = [
        'single_image' => 0.30,
        'carousel' => 0.30,
        'reel' => 0.20,
        'text_only' => 0.15,
        'video' => 0.05,
    ];

    public function role(): string { return 'strategist'; }
    public function promptVersion(): string { return StrategistPrompt::VERSION; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $brandStyle = $brand->currentStyle()->first();
        if (! $brandStyle) {
            return AgentResult::fail('Brand voice has not been synthesised yet. Run Onboarding first.');
        }

        $activePlatforms = $brand->platformConnections()
            ->where('status', 'active')
            ->pluck('platform')
            ->unique()
            ->values()
            ->all();

        if (empty($activePlatforms)) {
            return AgentResult::fail('No active platform connections. Connect at least one platform first.');
        }

        $pillarMix = $input['pillar_mix'] ?? self::DEFAULT_PILLAR_MIX;
        $formatMix = $input['format_mix'] ?? self::DEFAULT_FORMAT_MIX;
        $startsOn = isset($input['period_starts_on'])
            ? Carbon::parse($input['period_starts_on'])
            : Carbon::now($brand->timezone)->startOfMonth();
        $endsOn = $startsOn->copy()->endOfMonth();

        $userMessage = $this->buildUserMessage($brand, $brandStyle->content_md, $activePlatforms, $pillarMix, $formatMix, $startsOn, $endsOn);

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: StrategistPrompt::system(),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 8000,
            jsonSchema: StrategistPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! $payload || empty($payload['entries'])) {
            return AgentResult::fail('Calendar synthesis came back empty. Try again.');
        }

        // Range enforcement lives here, not in the JSON schema, because
        // Anthropic's structured-output validator only allows minItems of
        // 0 or 1 (and rejects bounded maxItems on some models). The system
        // prompt asks for 30 entries; we accept anything ≥10 as a usable
        // calendar and reject thinner responses as a failed plan.
        $entryCount = count($payload['entries']);
        if ($entryCount < 10) {
            return AgentResult::fail(sprintf(
                'Strategist returned only %d entries — a usable monthly plan needs at least 10. Try again.',
                $entryCount,
            ));
        }

        $calendar = DB::transaction(function () use ($brand, $payload, $pillarMix, $formatMix, $activePlatforms, $startsOn, $endsOn) {
            $calendar = ContentCalendar::create([
                'brand_id' => $brand->id,
                'label' => $payload['period_label'] ?? $startsOn->format('F Y'),
                'period_starts_on' => $startsOn,
                'period_ends_on' => $endsOn,
                'pillar_mix' => $pillarMix,
                'format_mix' => $formatMix,
                'platform_mix' => array_fill_keys($activePlatforms, 1.0 / count($activePlatforms)),
                'status' => 'in_review',
                'created_by_user_id' => auth()->id(),
            ]);

            foreach ($payload['entries'] as $entry) {
                $platforms = array_values(array_intersect($entry['platforms'] ?? [], $activePlatforms))
                    ?: [$activePlatforms[0]];

                // Clamp day_offset to [0, daysInMonth-1]. Schema-side
                // minimum/maximum was removed (Anthropic rejects them on
                // integer types) so we enforce the range here.
                $offset = max(0, min(
                    $startsOn->daysInMonth - 1,
                    (int) ($entry['day_offset'] ?? 0),
                ));

                CalendarEntry::create([
                    'content_calendar_id' => $calendar->id,
                    'brand_id' => $brand->id,
                    'scheduled_date' => $startsOn->copy()->addDays($offset),
                    'scheduled_time' => null,
                    'topic' => substr($entry['topic'] ?? 'Untitled', 0, 250),
                    'angle' => $entry['angle'] ?? '',
                    'pillar' => $entry['pillar'] ?? 'educational',
                    'format' => $entry['format'] ?? 'single_image',
                    'platforms' => $platforms,
                    'objective' => $entry['objective'] ?? 'engagement',
                    'visual_direction' => $entry['visual_direction'] ?? null,
                    'status' => 'planned',
                ]);
            }

            return $calendar;
        });

        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'calendar_id' => $calendar->id,
            'label' => $calendar->label,
            'entry_count' => $calendar->entries()->count(),
            'period_starts_on' => $calendar->period_starts_on->toDateString(),
            'period_ends_on' => $calendar->period_ends_on->toDateString(),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    private function buildUserMessage(
        Brand $brand,
        string $brandStyleMd,
        array $platforms,
        array $pillarMix,
        array $formatMix,
        Carbon $startsOn,
        Carbon $endsOn,
    ): string {
        $platformList = implode(', ', $platforms);
        $pillarLines = collect($pillarMix)->map(fn ($pct, $key) => "- $key: ".round($pct * 100)."%")->implode("\n");
        $formatLines = collect($formatMix)->map(fn ($pct, $key) => "- $key: ".round($pct * 100)."%")->implode("\n");

        return <<<MSG
BRAND: {$brand->name}
INDUSTRY: {$brand->industry}
PERIOD: {$startsOn->format('F j')} – {$endsOn->format('F j, Y')} ({$startsOn->daysInMonth} days)
TIMEZONE: {$brand->timezone}
ACTIVE PLATFORMS: {$platformList}

# Pillar mix targets
{$pillarLines}

# Format mix targets
{$formatLines}

# brand-style.md (single source of truth)
{$brandStyleMd}

Plan the calendar now. Return one entry per day for the month, distributed across pillars/formats per the mix targets and across the active platforms. Use day_offset = 0 for the first day of the period.
MSG;
    }
}

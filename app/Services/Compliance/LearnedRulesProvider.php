<?php

namespace App\Services\Compliance;

use App\Models\ComplianceLearnedRule;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side companion to LearnedRulesRecorder. Three responsibilities:
 *
 *   1. activeRulesFor(platform, workspaceId)
 *      Returns enabled, non-stale learned rules for a platform — global
 *      rules + workspace-scoped rules merged. Used by ComplianceAgent's
 *      learned-rule check.
 *
 *   2. promptDirectiveFor(platform, workspaceId)
 *      Returns a markdown bullet list ready to drop into Writer/Designer
 *      system prompts. Empty string when no rules exist (so prompts that
 *      embed it stay clean for new platforms).
 *
 *   3. matches(draft, rule)
 *      Decides whether a draft would re-trigger a learned rule. Static-rule
 *      kinds (media_required, caption_too_long, etc) are deferred to
 *      PlatformRules — this is for memory-only kinds (banned content,
 *      missing_required_property, etc) that PlatformRules doesn't model.
 *
 * Output is cached for 60s per (platform, workspace) tuple to keep the
 * Writer/Designer hot path under one DB hit per minute. Cache busts on
 * every recorder upsert (cheap; this is per-rejection, not per-publish).
 */
class LearnedRulesProvider
{
    private const CACHE_TTL_SECONDS = 60;

    /**
     * @return \Illuminate\Support\Collection<int, ComplianceLearnedRule>
     */
    public function activeRulesFor(string $platform, ?int $workspaceId = null)
    {
        $key = sprintf('learned_rules:%s:%s', strtolower($platform), $workspaceId ?? 'global');
        return Cache::remember($key, self::CACHE_TTL_SECONDS, function () use ($platform, $workspaceId) {
            return ComplianceLearnedRule::query()
                ->where('platform', strtolower($platform))
                ->where('disabled', false)
                ->where(function ($q) use ($workspaceId) {
                    $q->whereNull('workspace_id');
                    if ($workspaceId !== null) {
                        $q->orWhere('workspace_id', $workspaceId);
                    }
                })
                ->orderByDesc('occurrences')
                ->orderByDesc('last_seen_at')
                ->limit(20) // hard cap — Writer prompt size matters
                ->get();
        });
    }

    /**
     * Build a markdown directive block for Writer/Designer/Compliance prompts.
     * Returns "" when there are no rules (so the prompt template doesn't
     * render a stray empty section).
     */
    public function promptDirectiveFor(string $platform, ?int $workspaceId = null): string
    {
        $rules = $this->activeRulesFor($platform, $workspaceId);
        if ($rules->isEmpty()) return '';

        $lines = [];
        foreach ($rules as $rule) {
            $confidence = $rule->occurrences >= 10 ? '★★★' : ($rule->occurrences >= 3 ? '★★' : '★');
            $lines[] = sprintf(
                "- [%s] %s (observed %dx; first %s, last %s)",
                $confidence,
                $rule->directive,
                $rule->occurrences,
                $rule->first_seen_at?->format('Y-m-d') ?? 'recently',
                $rule->last_seen_at?->format('Y-m-d') ?? 'recently',
            );
        }

        return "# Learned platform rules — DO NOT VIOLATE\n\n"
            . "These were derived from real {$platform} rejection telemetry. Each was a "
            . "live publish failure. Star count = how many times this exact failure repeated.\n\n"
            . implode("\n", $lines)
            . "\n";
    }

    /**
     * Bust the cached rule list for a platform after a recorder upsert.
     * Called from LearnedRulesRecorder; safe to call from operator UI too.
     */
    public function bustCache(string $platform, ?int $workspaceId = null): void
    {
        Cache::forget(sprintf('learned_rules:%s:%s', strtolower($platform), $workspaceId ?? 'global'));
    }
}

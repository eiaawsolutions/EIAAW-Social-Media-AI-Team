<?php

namespace App\Agents\Concerns;

use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\GrowthStrategyBrief;

/**
 * Shared rendering of the calendar-entry-derived context blocks that any
 * copy-producing agent needs to feed the model: the Researcher's 5-angle
 * brief, the Strategist's creative intent (target_emotion + content_angle),
 * and the brand's proven per-objective growth guidance.
 *
 * Extracted from WriterAgent so RepurposeAgent reuses the IDENTICAL rendering
 * (same suppression idiom, same wording) — a repurposed derivative must be
 * built from the same deepened angles and proven hooks as a greenfield draft,
 * not a thinner subset. Both agents `use` this trait; the prompt files
 * (WriterPrompt / RepurposePrompt) instruct the model how to consume each block.
 *
 * Every renderer is self-suppressing: it returns '' when its source data is
 * absent, so an un-enriched brand's user message is byte-identical to the
 * pre-feature version (keeps prompt-version cohorts comparable for the optimizer).
 */
trait RendersWriterContext
{
    /**
     * Render the ResearcherAgent's 5-angle brief if present, otherwise empty.
     * The empty case suppresses the section header entirely so the model
     * isn't reading "Research brief — 5 angles" with no follow-up.
     */
    protected function renderResearchBrief(CalendarEntry $entry): string
    {
        $brief = $entry->research_brief;
        $angles = is_array($brief['angles'] ?? null) ? $brief['angles'] : [];
        if (empty($angles)) {
            return '';
        }

        $lines = collect($angles)
            ->take(5)
            ->map(function (array $a, int $i): string {
                $hook = trim((string) ($a['hook'] ?? ''));
                $thesis = trim((string) ($a['thesis'] ?? ''));
                $evidence = trim((string) ($a['evidence'] ?? ''));
                $tension = trim((string) ($a['tension'] ?? ''));
                $audience = trim((string) ($a['audience'] ?? ''));
                $idx = $i + 1;

                return "{$idx}. HOOK: {$hook}\n   THESIS: {$thesis}\n   EVIDENCE: {$evidence}\n   TENSION: {$tension}\n   AUDIENCE: {$audience}";
            })
            ->implode("\n\n");

        return "\n# Research brief — 5 angles (pick ONE that best fits the platform/format/objective)\n{$lines}\n";
    }

    /**
     * Render the Strategist's creative intent (target_emotion + content_angle)
     * stored at research_brief.creative. Returns lines appended to the calendar
     * entry block so the agent evokes the planned emotion and builds the planned
     * hook. Empty string when the Strategist didn't supply them (older calendars
     * planned before Strategist v1.2) — graceful degradation, no header noise.
     */
    protected function renderCreativeIntent(CalendarEntry $entry): string
    {
        $creative = is_array($entry->research_brief['creative'] ?? null)
            ? $entry->research_brief['creative']
            : [];
        if (empty($creative)) {
            return '';
        }

        $lines = '';
        $emotion = trim((string) ($creative['target_emotion'] ?? ''));
        if ($emotion !== '') {
            $lines .= "\n- Target emotion (make the hook + body evoke THIS): {$emotion}";
        }
        $angle = trim((string) ($creative['content_angle'] ?? ''));
        if ($angle !== '') {
            $lines .= "\n- Content angle (build the hook from this direction): {$angle}";
        }

        return $lines;
    }

    /**
     * Per-objective growth guidance (Growth Strategist, v1.6). Reads the current
     * GrowthStrategyBrief and, keyed off this entry's `objective`, surfaces the
     * hook patterns + CTA styles that have measurably worked for THIS brand on
     * THIS objective. Returns '' when no brief or no guidance for the objective —
     * so an un-enriched brand's prompt is byte-identical to the pre-feature version.
     *
     * Best-effort: a brief-lookup failure (DB unreachable, table absent) degrades
     * to '' rather than breaking caption generation — the base prompt stays safe.
     */
    protected function renderGrowthObjectiveGuidance(Brand $brand, CalendarEntry $entry): string
    {
        try {
            $brief = GrowthStrategyBrief::currentForBrand($brand->id)->first();
        } catch (\Throwable) {
            return '';
        }

        if (! $brief) {
            return '';
        }

        return self::renderGrowthObjectiveGuidanceBlock(
            (array) ($brief->objective_guidance ?? []),
            (string) $entry->objective,
        );
    }

    /**
     * Corpus source_types whose source_id is looked up against brand_corpus.id
     * (a bigint). A non-numeric id on these crashed the Compliance corpus query
     * before PR #56 guarded it; this list mirrors that guard.
     */
    private static array $corpusSourceTypes = ['historical_post', 'website_page'];

    /**
     * Normalise the LLM-emitted grounding_sources before persisting. For a
     * corpus citation (historical_post / website_page) whose source_id is NOT a
     * plain numeric brand_corpus id — e.g. RepurposeAgent's invented
     * source_id="master_432" referencing the master draft — the bogus id is
     * dropped (the claim + excerpt + type are kept, so Compliance's substring
     * match still verifies the citation). This stops a non-corpus id reaching
     * the gate's bigint lookup at the SOURCE, complementing the Compliance guard.
     * Malformed (non-array) entries are dropped. Pure — no DB.
     *
     * @param  array<int,mixed>  $sources
     * @return array<int,array<string,mixed>>
     */
    public static function sanitizeGroundingSources(array $sources): array
    {
        $out = [];
        foreach ($sources as $src) {
            if (! is_array($src)) {
                continue;
            }
            $type = (string) ($src['source_type'] ?? '');
            $id = trim((string) ($src['source_id'] ?? ''));
            // Drop a non-numeric id on a corpus citation; keep everything else.
            if (in_array($type, self::$corpusSourceTypes, true) && $id !== '' && ! ctype_digit($id)) {
                unset($src['source_id']);
            }
            $out[] = $src;
        }

        return $out;
    }

    /**
     * Pure renderer — no DB. Returns lines (each starting "\n- ") to append under
     * the calendar-entry block, or '' when there's nothing for this objective.
     * Mirrors renderCreativeIntent's suppression idiom.
     *
     * @param  array<string,mixed>  $objectiveGuidance  {objective: {hook_patterns:[], cta_styles:[]}}
     */
    public static function renderGrowthObjectiveGuidanceBlock(array $objectiveGuidance, string $objective): string
    {
        $objective = trim($objective);
        $g = $objectiveGuidance[$objective] ?? null;
        if (! is_array($g)) {
            return '';
        }

        $hooks = array_values(array_filter(array_map(
            fn ($h) => trim((string) $h),
            (array) ($g['hook_patterns'] ?? []),
        ), fn ($h) => $h !== ''));
        $ctas = array_values(array_filter(array_map(
            fn ($c) => trim((string) $c),
            (array) ($g['cta_styles'] ?? []),
        ), fn ($c) => $c !== ''));

        $lines = '';
        if ($hooks !== []) {
            $lines .= "\n- Proven hook patterns for this objective ({$objective}): ".implode(', ', $hooks);
        }
        if ($ctas !== []) {
            $ctaList = implode('; ', array_map(fn ($c) => "\"{$c}\"", array_slice($ctas, 0, 4)));
            $lines .= "\n- Proven CTA styles for this objective: {$ctaList}";
        }

        return $lines;
    }
}

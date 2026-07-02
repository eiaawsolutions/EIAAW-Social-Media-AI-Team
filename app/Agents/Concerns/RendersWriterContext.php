<?php

namespace App\Agents\Concerns;

use App\Agents\StrategistAgent;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\GrowthStrategyBrief;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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
        $positioning = trim((string) ($creative['positioning_goal'] ?? ''));
        if ($positioning !== '') {
            $lines .= "\n- Positioning goal (the strategic job this post does): {$positioning}";
        }

        return $lines;
    }

    /**
     * Render the platform-specific directive for THIS platform. This is the fix
     * for cross-platform cloning: when the Strategist supplied a `platform_angles`
     * map (research_brief.creative.platform_angles), the Writer gets the DISTINCT
     * native angle the Strategist planned for this exact platform — so sibling
     * platforms of the same calendar entry no longer receive a byte-identical
     * user message and stop producing near-identical bodies.
     *
     * Always emits a native-mechanics line for the platform (even without a
     * planned angle) instructing the model to write for THIS platform's audience
     * and NOT to reproduce a generic entry angle verbatim across platforms.
     * Self-suppresses to '' only for an unknown platform string.
     */
    protected function renderPlatformDirective(CalendarEntry $entry, string $platform): string
    {
        $mechanics = self::platformMechanics($platform);
        if ($mechanics === '') {
            return '';
        }

        $creative = is_array($entry->research_brief['creative'] ?? null)
            ? $entry->research_brief['creative']
            : [];
        $angles = is_array($creative['platform_angles'] ?? null)
            ? $creative['platform_angles']
            : [];
        $plannedAngle = trim((string) ($angles[$platform] ?? ''));

        $siblings = is_array($entry->platforms) ? $entry->platforms : [];
        $isMultiPlatform = count(array_unique($siblings)) > 1;

        $out = "\n# Write natively for {$platform}\n- Platform mechanics: {$mechanics}";
        if ($plannedAngle !== '') {
            $out .= "\n- Native angle for {$platform} (the Strategist planned THIS take for this platform — build the hook from it): {$plannedAngle}";
        }
        if ($isMultiPlatform) {
            $out .= "\n- This entry also ships to other platforms. Write a DISTINCT {$platform}-native take — do NOT produce the same body a sibling platform would get. Different hook, different rhythm, native to {$platform}.";
        }

        return $out."\n";
    }

    /**
     * One-line native-mechanics cue per platform, mirroring the Strategist's
     * platform-mechanics model so the Writer and Strategist speak the same
     * language. Returns '' for an unrecognised platform.
     */
    protected static function platformMechanics(string $platform): string
    {
        return match ($platform) {
            'linkedin' => 'professional authority + aspiration; insight-led first-person POV a peer would repost; hook is a specific claim or lesson.',
            'instagram' => 'visual-first scroll-stop; first line is a headline; short paragraphs, generous line breaks.',
            'tiktok' => 'trend + entertainment; wins or dies in the first 3 seconds; lower-case, conversational, native, never corporate.',
            'x' => 'wit + opinion + conversation; one sharp idea, no preamble, punchy; every word earns its place.',
            'threads' => 'casual, opinion-led, built for replies; softer and more human than X; start a conversation.',
            'facebook' => 'community + slightly longer-form; question-led / story-led; relationship-driven.',
            'youtube' => 'search + watch-time; title-style hook; the description sells the click; evergreen and discoverable.',
            'pinterest' => 'search + save intent; keyword-front-loaded, aspirational, evergreen how-to / inspiration.',
            default => '',
        };
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
     * The brand's recently-published topics/angles as a DO-NOT-REPEAT block —
     * the same exclusion list the Strategist plans against, now also handed to
     * the Writer so it avoids echoing prior posts DURING generation (previously
     * the Writer was blind to recycling until Compliance's post-hoc dedup).
     * Excludes the entry currently being written so a redraft of the same slot
     * doesn't see itself. Delegates to StrategistAgent's pure block renderer for
     * identical formatting; self-suppresses to '' when there's no history.
     */
    protected function renderRecentlyPublishedForWriter(Brand $brand, CalendarEntry $entry, int $days = 90, int $limit = 25): string
    {
        try {
            $since = Carbon::now()->subDays($days);

            $rows = DB::table('scheduled_posts as sp')
                ->join('drafts as d', 'd.id', '=', 'sp.draft_id')
                ->join('calendar_entries as ce', 'ce.id', '=', 'd.calendar_entry_id')
                ->where('sp.brand_id', $brand->id)
                ->where('sp.status', 'published')
                ->where('sp.published_at', '>=', $since)
                ->where('ce.id', '!=', $entry->id)
                ->whereNotNull('ce.topic')
                ->orderByDesc('sp.published_at')
                ->limit(150)
                ->get(['ce.topic', 'ce.angle', 'ce.pillar', 'sp.published_at']);
        } catch (\Throwable) {
            return '';
        }

        $entries = [];
        foreach ($rows as $r) {
            $topic = trim((string) ($r->topic ?? ''));
            if ($topic === '') {
                continue;
            }
            $entries[] = [
                'topic' => $topic,
                'angle' => trim((string) ($r->angle ?? '')),
                'pillar' => trim((string) ($r->pillar ?? '')),
                'published_at' => $r->published_at ? Carbon::parse($r->published_at) : null,
            ];
        }

        $block = StrategistAgent::renderRecentlyPublishedBlock($entries, $days, $limit);

        return $block === '' ? '' : "\n".$block."\n";
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

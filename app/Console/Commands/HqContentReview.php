<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Read-only dump of the HQ (eiaaw_internal) content lined up for a given month,
 * with the FULL draft body text, so a human/agent can review it for
 * over-claiming / exaggeration before it publishes.
 *
 *   php artisan hq:content-review                 # July 2026 (default), not-yet-published
 *   php artisan hq:content-review --month=2026-08
 *   php artisan hq:content-review --all-statuses  # include published too
 *   php artisan hq:content-review --json          # machine-readable dump
 *
 * "HQ" = brands whose workspace plan is 'eiaaw_internal'. Anchored on the
 * calendar entry's scheduled_date; drafts with no calendar entry are matched
 * via their scheduled_post.scheduled_for as a fallback.
 *
 * This command WRITES NOTHING. It is safe to run against production.
 */
class HqContentReview extends Command
{
    protected $signature = 'hq:content-review {--month=} {--all-statuses} {--json}';
    protected $description = 'Read-only: dump HQ (eiaaw_internal) content lined up for a month, with full draft body, for truthfulness review.';

    /** Draft statuses that count as "lined up but not yet published". */
    private const PLANNED_STATUSES = [
        'compliance_pending', 'compliance_failed', 'awaiting_approval',
        'approved', 'scheduled',
    ];

    public function handle(): int
    {
        $monthOpt = (string) ($this->option('month') ?: '2026-07');
        try {
            $start = Carbon::createFromFormat('Y-m', $monthOpt)->startOfMonth();
        } catch (\Throwable $e) {
            $this->error("Bad --month '{$monthOpt}', expected YYYY-MM.");
            return self::FAILURE;
        }
        $end = (clone $start)->endOfMonth();

        $hqBrandIds = Brand::whereHas('workspace', fn ($q) => $q->where('plan', 'eiaaw_internal'))
            ->pluck('name', 'id');

        if ($hqBrandIds->isEmpty()) {
            $this->warn('No HQ (eiaaw_internal) brands found. Wrong database?');
            $this->line('current DB: ' . \DB::connection()->getDatabaseName());
            return self::SUCCESS;
        }

        // Anchor on calendar entries in the month for HQ brands.
        $entries = CalendarEntry::with(['drafts', 'brand'])
            ->whereIn('brand_id', $hqBrandIds->keys())
            ->whereBetween('scheduled_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->get();

        // Fallback: drafts with a July scheduled_post but no calendar-entry anchor.
        $orphanDrafts = Draft::with(['brand', 'scheduledPosts'])
            ->whereIn('brand_id', $hqBrandIds->keys())
            ->whereNull('calendar_entry_id')
            ->whereHas('scheduledPosts', fn ($q) => $q->whereBetween('scheduled_for', [$start, $end]))
            ->get();

        $rows = [];

        foreach ($entries as $e) {
            $drafts = $e->drafts;
            if ($drafts->isEmpty()) {
                $rows[] = $this->rowFromEntry($e, null, $hqBrandIds);
                continue;
            }
            foreach ($drafts as $d) {
                if (! $this->option('all-statuses') && ! in_array($d->status, self::PLANNED_STATUSES, true)) {
                    continue;
                }
                $rows[] = $this->rowFromEntry($e, $d, $hqBrandIds);
            }
        }
        foreach ($orphanDrafts as $d) {
            if (! $this->option('all-statuses') && ! in_array($d->status, self::PLANNED_STATUSES, true)) {
                continue;
            }
            $rows[] = $this->rowFromDraft($d, $hqBrandIds);
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $this->line('');
        $this->info("== HQ content lined up for {$start->format('F Y')} ==");
        $this->line('DB: ' . \DB::connection()->getDatabaseName() . '   HQ brands: ' . $hqBrandIds->map(fn ($n, $id) => "#{$id} {$n}")->join(', '));
        $this->line('Items: ' . count($rows) . ($this->option('all-statuses') ? ' (all statuses)' : ' (planned/not-yet-published)'));
        $this->line('');

        foreach ($rows as $r) {
            $this->line(str_repeat('─', 78));
            $this->line(sprintf(
                '[%s] brand #%s %s  |  draft #%s status=%s lane=%s  |  %s',
                $r['scheduled_date'] ?? '(no date)',
                $r['brand_id'], $r['brand_name'],
                $r['draft_id'] ?? '-', $r['draft_status'] ?? '-', $r['lane'] ?? '-',
                $r['platform'] ?? '-',
            ));
            if ($r['topic'] || $r['angle'] || $r['pillar']) {
                $this->line("  topic: {$r['topic']}");
                $this->line("  angle: {$r['angle']}   pillar: {$r['pillar']}   objective: {$r['objective']}");
            }
            $this->line('  ── body ──');
            foreach (preg_split('/\n/', (string) $r['body']) as $ln) {
                $this->line('  | ' . $ln);
            }
            if (! empty($r['hashtags'])) {
                $this->line('  hashtags: ' . implode(' ', $r['hashtags']));
            }
        }
        $this->line(str_repeat('─', 78));
        $this->line('');

        return self::SUCCESS;
    }

    private function rowFromEntry(CalendarEntry $e, ?Draft $d, $hqBrandIds): array
    {
        return [
            'scheduled_date' => optional($e->scheduled_date)->toDateString(),
            'scheduled_time' => $e->scheduled_time,
            'brand_id' => $e->brand_id,
            'brand_name' => $hqBrandIds[$e->brand_id] ?? '?',
            'calendar_entry_id' => $e->id,
            'topic' => $e->topic,
            'angle' => $e->angle,
            'pillar' => $e->pillar,
            'objective' => $e->objective,
            'format' => $e->format,
            'draft_id' => $d?->id,
            'draft_status' => $d?->status,
            'lane' => $d?->lane,
            'platform' => $d?->platform,
            'body' => $d?->body,
            'hashtags' => is_array($d?->hashtags) ? $d->hashtags : [],
        ];
    }

    private function rowFromDraft(Draft $d, $hqBrandIds): array
    {
        $sp = $d->scheduledPosts->first();
        return [
            'scheduled_date' => optional($sp?->scheduled_for)->toDateString(),
            'scheduled_time' => optional($sp?->scheduled_for)->format('H:i'),
            'brand_id' => $d->brand_id,
            'brand_name' => $hqBrandIds[$d->brand_id] ?? '?',
            'calendar_entry_id' => null,
            'topic' => null,
            'angle' => null,
            'pillar' => null,
            'objective' => null,
            'format' => $d->content_type,
            'draft_id' => $d->id,
            'draft_status' => $d->status,
            'lane' => $d->lane,
            'platform' => $d->platform,
            'body' => $d->body,
            'hashtags' => is_array($d->hashtags) ? $d->hashtags : [],
        ];
    }
}

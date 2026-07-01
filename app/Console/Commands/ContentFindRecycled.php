<?php

namespace App\Console\Commands;

use App\Agents\ComplianceAgent;
use App\Agents\WriterAgent;
use App\Models\Draft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Finds recycled content in the UNPUBLISHED pipeline and (optionally) redrafts it.
 *
 * Two recycling shapes are detected among drafts that have not yet published
 * (status in scheduled / awaiting_approval / approved / compliance_pending):
 *
 *   1. Cross-platform clones — sibling drafts of the SAME calendar entry whose
 *      normalized bodies are (near-)identical. This is the intermittent Writer
 *      fan-out bug (one entry copied across platforms). Now prevented at
 *      generation time by the per-platform Writer directive; this command cleans
 *      up the ones already sitting in the queue.
 *   2. Thematic near-dupes — different calendar entries for the same brand whose
 *      normalized bodies overlap heavily (token-set similarity), i.e. the same
 *      idea reworded.
 *
 * Read-only by default (report). With --redraft it regenerates each flagged,
 * Writer-authored draft through the REAL pipeline: WriterAgent (which now emits a
 * distinct platform-native body) then ComplianceAgent::run — Compliance owns the
 * status transition (never hand-forced). Operator-authored ("customised") posts
 * are NEVER auto-rewritten — they are reported for manual review only.
 */
class ContentFindRecycled extends Command
{
    protected $signature = 'content:find-recycled
                            {--brand= : Limit to one brand id}
                            {--status=scheduled,awaiting_approval,approved,compliance_pending : CSV of draft statuses to scan}
                            {--sibling-threshold=0.9 : token-set similarity at/above which same-entry siblings are a clone}
                            {--theme-threshold=0.6 : token-set similarity at/above which different-entry drafts are a thematic dupe}
                            {--redraft : regenerate flagged Writer-authored drafts via Writer + real Compliance (default: report only)}
                            {--limit=50 : max drafts to redraft in one run}';

    protected $description = 'Detect (and optionally redraft) recycled content in the unpublished pipeline.';

    public function handle(): int
    {
        $statuses = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('status')))));
        $siblingT = (float) $this->option('sibling-threshold');
        $themeT = (float) $this->option('theme-threshold');
        $redraft = (bool) $this->option('redraft');
        $limit = max(1, (int) $this->option('limit'));

        $query = Draft::query()
            ->whereIn('status', $statuses)
            ->whereNotNull('body')
            ->select('id', 'brand_id', 'calendar_entry_id', 'platform', 'status', 'body', 'agent_role', 'prompt_version');
        if ($brandId = (int) $this->option('brand')) {
            $query->where('brand_id', $brandId);
        }
        $drafts = $query->orderBy('brand_id')->orderBy('id')->get();

        $this->info(sprintf('Scanning %d unpublished draft(s) [%s]%s', $drafts->count(), implode(',', $statuses), $redraft ? ' — REDRAFT mode' : ' — report only'));

        // Group by brand for the thematic comparison; siblings group by entry.
        $byBrand = $drafts->groupBy('brand_id');

        $flagged = []; // draft_id => reason
        foreach ($byBrand as $bid => $items) {
            // 1. Cross-platform clones (same calendar_entry_id).
            foreach ($items->whereNotNull('calendar_entry_id')->groupBy('calendar_entry_id') as $ce => $siblings) {
                if ($siblings->count() < 2) {
                    continue;
                }
                $list = $siblings->values();
                for ($i = 0; $i < $list->count(); $i++) {
                    for ($j = $i + 1; $j < $list->count(); $j++) {
                        $sim = self::similarity($list[$i]->body, $list[$j]->body);
                        if ($sim >= $siblingT) {
                            // Flag the LATER (higher-id) sibling so the earliest
                            // stays and only the copies get regenerated.
                            $keep = $list[$i];
                            $drop = $list[$j];
                            $flagged[$drop->id] = sprintf(
                                'cross-platform clone of #%d (entry %s, %.0f%% similar)',
                                $keep->id, $ce, $sim * 100,
                            );
                            $this->line(sprintf(
                                '  CLONE brand#%d entry=%s  #%d(%s) ≈ #%d(%s) %.0f%%',
                                $bid, $ce, $keep->id, $keep->platform, $drop->id, $drop->platform, $sim * 100,
                            ));
                        }
                    }
                }
            }

            // 2. Thematic near-dupes across DIFFERENT entries.
            $list = $items->values();
            for ($i = 0; $i < $list->count(); $i++) {
                for ($j = $i + 1; $j < $list->count(); $j++) {
                    if ($list[$i]->calendar_entry_id === $list[$j]->calendar_entry_id && $list[$i]->calendar_entry_id !== null) {
                        continue; // same-entry siblings handled above
                    }
                    $sim = self::similarity($list[$i]->body, $list[$j]->body);
                    if ($sim >= $themeT) {
                        $drop = $list[$j];
                        if (! isset($flagged[$drop->id])) {
                            $flagged[$drop->id] = sprintf('thematic near-dupe of #%d (%.0f%% similar)', $list[$i]->id, $sim * 100);
                            $this->line(sprintf(
                                '  THEME brand#%d  #%d(%s) ≈ #%d(%s) %.0f%%',
                                $bid, $list[$i]->id, $list[$i]->platform, $drop->id, $drop->platform, $sim * 100,
                            ));
                        }
                    }
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('Found %d flagged draft(s).', count($flagged)));

        if (empty($flagged)) {
            return self::SUCCESS;
        }

        if (! $redraft) {
            $this->line('Run again with --redraft to regenerate the Writer-authored ones (operator-authored posts are reported only).');

            return self::SUCCESS;
        }

        // Redraft: only Writer-authored drafts, capped by --limit.
        $redrafted = 0;
        $skipped = 0;
        foreach (array_keys($flagged) as $draftId) {
            if ($redrafted >= $limit) {
                $this->warn(sprintf('Reached --limit=%d; %d flagged draft(s) not processed this run.', $limit, count($flagged) - $redrafted - $skipped));
                break;
            }

            $draft = Draft::find($draftId);
            if (! $draft) {
                continue;
            }

            // NEVER auto-rewrite operator-authored copy — surface for manual edit.
            if ($draft->agent_role === 'operator' || $draft->prompt_version === 'customised-post.v1') {
                $this->line(sprintf('  SKIP  #%d — operator-authored (manual review only)', $draft->id));
                $skipped++;

                continue;
            }
            if (! $draft->calendar_entry_id) {
                $this->line(sprintf('  SKIP  #%d — no calendar entry to re-anchor', $draft->id));
                $skipped++;

                continue;
            }

            $brand = $draft->brand;
            if (! $brand) {
                $skipped++;

                continue;
            }

            try {
                // Regenerate the body natively (the Writer now emits a distinct
                // per-platform take), then let the REAL Compliance gate own the
                // status transition — never hand-force approved.
                app(WriterAgent::class)->run($brand, [
                    'calendar_entry_id' => $draft->calendar_entry_id,
                    'platform' => $draft->platform,
                    'redraft_context' => [
                        'draft_id' => $draft->id,
                        'prior_draft_id' => $draft->id,
                        'prior_body' => (string) $draft->body,
                        'failures' => [[
                            'check_type' => 'dedup',
                            'reason' => $flagged[$draftId],
                        ]],
                    ],
                ]);
                app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
                $this->info(sprintf('  REDRAFTED #%d (%s) — %s', $draft->id, $draft->platform, $flagged[$draftId]));
                $redrafted++;
            } catch (\Throwable $e) {
                Log::error('content:find-recycled redraft failed', ['draft_id' => $draft->id, 'error' => $e->getMessage()]);
                $this->warn(sprintf('  FAIL  #%d — %s', $draft->id, substr($e->getMessage(), 0, 120)));
                $skipped++;
            }
        }

        $this->newLine();
        $this->line('--- summary ---');
        $this->line("redrafted: {$redrafted}");
        $this->line("skipped:   {$skipped}");
        $this->line('Note: Compliance owns the status; redrafted drafts re-enter the normal approval/schedule flow.');

        return self::SUCCESS;
    }

    /**
     * Token-set (Jaccard) similarity of two post bodies after normalization
     * (lowercase, strip urls/hashtags/punctuation, drop short + stop words).
     * Pure + deterministic so the detector is unit-testable without a DB or
     * embeddings. Catches both verbatim clones and reworded thematic dupes.
     */
    public static function similarity(string $a, string $b): float
    {
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if ($ta === [] || $tb === []) {
            return 0.0;
        }
        $inter = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));

        return $union > 0 ? $inter / $union : 0.0;
    }

    /** @return array<int,string> */
    private static function tokens(string $s): array
    {
        static $stop = null;
        if ($stop === null) {
            $stop = array_flip(explode(' ', 'the a an and or but if then your you they them their our we us is are was were be been being to of in on for with at by from as that this these those it its not do does did what when how why who which about into more most just like can will youre have has had our'));
        }

        $s = mb_strtolower($s);
        $s = preg_replace('#https?://\S+#', ' ', $s);
        $s = preg_replace('/#\w+/', ' ', $s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        $s = preg_replace('/\s+/', ' ', trim((string) $s));

        $out = [];
        foreach (explode(' ', (string) $s) as $w) {
            if (mb_strlen($w) > 3 && ! isset($stop[$w])) {
                $out[$w] = true;
            }
        }

        return array_keys($out);
    }
}

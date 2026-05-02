<?php

namespace App\Console\Commands;

use App\Models\ScheduledPost;
use Illuminate\Console\Command;

/**
 * Operator kill-switch for the publish queue. Cancels every scheduled_posts
 * row currently in queued/submitted status and flips the underlying draft
 * back to 'approved' so the operator can re-schedule when ready.
 *
 * Use cases:
 *   - Brand crisis: stop everything before the next minute's cron tick.
 *   - Pre-launch testing: prevent any queued staging post from going live.
 *   - Migration: switch publishing pipeline without orphan posts.
 *
 * Dry-run by default; pass --apply to actually cancel.
 */
class PostsCancelAllQueued extends Command
{
    protected $signature = 'posts:cancel-all-queued
        {--brand= : Limit to this brand_id}
        {--apply : Actually cancel (default is dry-run)}
        {--reason=Operator kill-switch : Stored on last_error for the audit trail}';

    protected $description = 'Cancel all queued/submitted scheduled posts (kill-switch).';

    public function handle(): int
    {
        $q = ScheduledPost::whereIn('status', ['queued', 'submitted']);
        if ($brandId = (int) $this->option('brand')) {
            $q->where('brand_id', $brandId);
        }
        $rows = $q->with('draft')->get();

        if ($rows->isEmpty()) {
            $this->info('Nothing to cancel.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'brand', 'platform', 'status', 'scheduled_for', 'draft_id'],
            $rows->map(fn ($r) => [
                $r->id,
                $r->brand_id,
                $r->draft?->platform ?? '?',
                $r->status,
                $r->scheduled_for?->toDateTimeString(),
                $r->draft_id,
            ])->all(),
        );

        if (! $this->option('apply')) {
            $this->warn('Dry run. Re-run with --apply to cancel ' . $rows->count() . ' row(s).');
            return self::SUCCESS;
        }

        $reason = (string) $this->option('reason');
        $cancelled = 0;
        foreach ($rows as $r) {
            $r->update([
                'status' => 'cancelled',
                'last_error' => $reason,
            ]);
            if ($r->draft && $r->draft->status === 'scheduled') {
                $r->draft->update(['status' => 'approved']);
            }
            $cancelled++;
        }

        $this->info("Cancelled {$cancelled} scheduled post(s). Drafts rolled back to 'approved'.");
        return self::SUCCESS;
    }
}

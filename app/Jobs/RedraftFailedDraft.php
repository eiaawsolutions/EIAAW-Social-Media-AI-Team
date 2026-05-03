<?php

namespace App\Jobs;

use App\Agents\ComplianceAgent;
use App\Agents\WriterAgent;
use App\Models\Draft;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Auto-redraft loop for compliance-failed drafts.
 *
 * Closes the gap where a draft hits compliance_failed and just sits in the
 * Drafts table forever waiting for a human. The Writer is given the prior
 * body + every Compliance fail reason and asked to fix the violations while
 * preserving topic + angle. Compliance then re-runs.
 *
 * Capped via Draft.revision_count (default 3 attempts) so we don't burn LLM
 * budget on drafts the model can't fix — those stay compliance_failed and
 * surface to the operator. Idempotent: if the draft is no longer in
 * compliance_failed, this job exits silently.
 */
class RedraftFailedDraft implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    /** Hard cap on Writer rewrites per draft. After this, human attention required. */
    public const MAX_REVISIONS = 3;

    public function __construct(
        public int $draftId,
    ) {}

    public function handle(): void
    {
        @set_time_limit(180);

        $draft = Draft::find($this->draftId);
        if (! $draft) return;

        // Idempotency: only act on drafts that are still failed and under cap.
        // The cron may have queued multiple jobs for the same draft if the
        // operator also clicked 'Re-run Compliance' manually — second one
        // just exits.
        if ($draft->status !== 'compliance_failed') {
            return;
        }
        if (($draft->revision_count ?? 0) >= self::MAX_REVISIONS) {
            return;
        }
        if (! $draft->calendar_entry_id) {
            // No calendar entry to re-anchor against — Writer needs topic/angle
            // input. Surface this so the operator knows it's not silently
            // skipped forever.
            Log::info('RedraftFailedDraft: skipping — no calendar entry', ['draft_id' => $draft->id]);
            return;
        }

        $brand = $draft->brand;
        if (! $brand) return;

        // Snapshot the prior body + the fail reasons before Writer mutates the row.
        $priorBody = (string) $draft->body;
        $failures = $draft->complianceChecks()
            ->where('result', 'fail')
            ->orderBy('id')
            ->get(['check_type', 'reason', 'details'])
            ->map(fn ($c) => [
                'check_type' => $c->check_type,
                'reason' => $c->reason,
                'details' => $c->details,
            ])
            ->all();

        if (empty($failures)) {
            // Nothing to fix — defensive: status said failed but no fail rows.
            // Just re-run Compliance and let it correct the status.
            try {
                app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
            } catch (\Throwable $e) {
                Log::error('RedraftFailedDraft: defensive recompliance failed', [
                    'draft_id' => $draft->id, 'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        try {
            $writerResult = app(WriterAgent::class)->run($brand, [
                'calendar_entry_id' => $draft->calendar_entry_id,
                'platform' => $draft->platform,
                'redraft_context' => [
                    'draft_id' => $draft->id,
                    'prior_draft_id' => $draft->id,
                    'prior_body' => $priorBody,
                    'failures' => $failures,
                ],
            ]);

            if (! $writerResult->ok) {
                Log::warning('RedraftFailedDraft: Writer rewrite failed', [
                    'draft_id' => $draft->id,
                    'error' => $writerResult->errorMessage,
                ]);
                // Bump revision_count so we don't immediately retry the same
                // doomed draft on the next cron tick — let the cap exhaust.
                $draft->forceFill([
                    'revision_count' => ($draft->revision_count ?? 0) + 1,
                    'last_redraft_at' => now(),
                ])->save();
                return;
            }

            // Writer mutated the draft in-place (status reset to compliance_pending,
            // revision_count incremented). Now run Compliance against the new body.
            app(ComplianceAgent::class)->run($brand, ['draft_id' => $draft->id]);
        } catch (\Throwable $e) {
            Log::error('RedraftFailedDraft: redraft loop crashed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            // Bump the counter so a permanently-broken draft doesn't get
            // re-picked every cron tick. The error is logged for diagnosis.
            try {
                $draft->forceFill([
                    'revision_count' => ($draft->revision_count ?? 0) + 1,
                    'last_redraft_at' => now(),
                ])->save();
            } catch (\Throwable) {
                // swallow — we're already in an error path
            }
        }
    }
}

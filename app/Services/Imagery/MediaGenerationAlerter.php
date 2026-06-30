<?php

namespace App\Services\Imagery;

use App\Mail\MediaGenerationFailed;
use App\Models\Brand;
use App\Models\Draft;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the admin IMMEDIATELY when media generation fails, naming the REASON
 * and the ACTION REQUIRED. Called from the FAL failure catch sites in
 * DesignerAgent / VideoAgent.
 *
 * Two reason-classes, each mapping to a distinct admin action:
 *
 *   - REASON_ACCOUNT_LOCKED  → FAL prepaid balance exhausted; every call 403s
 *     until top-up. ACTION: top up at fal.ai/dashboard/billing — generation
 *     auto-resumes within ~2 min (the breaker clears on the first good call).
 *
 *   - REASON_GENERATION_FAILED → a per-request failure (bad prompt, 422,
 *     transient 5xx). ACTION: investigate the draft / FAL status; the agent
 *     already fell back to the brand library if it could.
 *
 * Throttled per reason-class so a backlog run (which can hit the SAME lockout
 * on dozens of drafts in seconds) sends ONE email, not dozens. The suppressed
 * count is drained into the next allowed email so the admin still sees the true
 * blast radius. Best-effort throughout: a mail or cache hiccup must never break
 * the agent's own fallback path — alerting is a side-channel, not the work.
 */
class MediaGenerationAlerter
{
    public const REASON_ACCOUNT_LOCKED = 'account_locked';

    public const REASON_GENERATION_FAILED = 'generation_failed';

    /** FAL balance exhausted — admin must top up. */
    public function accountLocked(string $mediaKind, Brand $brand, Draft $draft, string $detail = ''): void
    {
        $this->send(
            reason: self::REASON_ACCOUNT_LOCKED,
            mediaKind: $mediaKind,
            brand: $brand,
            draft: $draft,
            reasonText: sprintf(
                'FAL.AI account is LOCKED (prepaid balance exhausted). Every %s generation will fail '
                .'until the balance is topped up.',
                $mediaKind,
            ),
            actionText: 'Top up the FAL.AI balance at https://fal.ai/dashboard/billing. '
                .'Generation auto-resumes within ~2 minutes of a successful top-up — no deploy needed. '
                .'Consider enabling FAL auto-top-up so this cannot recur. Held drafts can then be recovered '
                .'with: php artisan drafts:regenerate-image <id>.',
            detail: $detail,
        );
    }

    /** Per-request generation failure — admin should investigate. */
    public function generationFailed(string $mediaKind, Brand $brand, Draft $draft, string $detail = ''): void
    {
        $this->send(
            reason: self::REASON_GENERATION_FAILED,
            mediaKind: $mediaKind,
            brand: $brand,
            draft: $draft,
            reasonText: sprintf('FAL.AI %s generation failed for this draft (not an account lockout).', $mediaKind),
            actionText: sprintf(
                'Check FAL.AI status and the worker logs for draft #%d. The agent fell back to the brand '
                .'library if a usable asset existed; if not, the draft is held as compliance_failed. '
                .'Re-run with: php artisan drafts:regenerate-image %d%s.',
                $draft->id,
                $draft->id,
                $mediaKind === 'video' ? ' --video' : '',
            ),
            detail: $detail,
        );
    }

    private function send(
        string $reason,
        string $mediaKind,
        Brand $brand,
        Draft $draft,
        string $reasonText,
        string $actionText,
        string $detail,
    ): void {
        $throttle = $this->throttle($reason);
        if (! $throttle['allow']) {
            return; // suppressed — counter incremented, drained into the next allowed email
        }

        $recipient = (string) config('media.alerts.recipient', 'eiaawsolutions@gmail.com');
        $mailer = (string) config('media.alerts.mailer', 'resend');

        try {
            Mail::mailer($mailer)
                ->to($recipient)
                ->queue(new MediaGenerationFailed(
                    reason: $reason,
                    mediaKind: $mediaKind,
                    reasonText: $reasonText,
                    actionText: $actionText,
                    brandName: (string) $brand->name,
                    draftId: (int) $draft->id,
                    platform: (string) $draft->platform,
                    detail: mb_substr($detail, 0, 500),
                    suppressedCount: $throttle['suppressed'],
                ));
        } catch (Throwable $e) {
            // Never let an alert failure break the agent's own fallback path.
            Log::error('MediaGenerationAlerter: alert dispatch failed', [
                'reason' => $reason,
                'draft_id' => $draft->id,
                'mailer' => $mailer,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * One alert per reason-class per `throttle_minutes`. Mirrors
     * SecurityAlertThrottle's Cache::add-then-increment token bucket: the first
     * hit in a window sends + sets the TTL; subsequent hits increment a
     * suppressed counter that the next allowed email drains and reports.
     *
     * @return array{allow: bool, suppressed: int}
     */
    private function throttle(string $reason): array
    {
        $windowSeconds = max(60, (int) config('media.alerts.throttle_minutes', 30) * 60);
        $gateKey = "media:alert:gate:{$reason}";
        $suppressedKey = "media:alert:suppressed:{$reason}";

        try {
            // First hit in the window claims the gate (sets TTL) → allowed.
            if (Cache::add($gateKey, 1, $windowSeconds)) {
                $suppressed = (int) Cache::get($suppressedKey, 0);
                Cache::forget($suppressedKey);

                return ['allow' => true, 'suppressed' => $suppressed];
            }

            // Gate already held → suppress, but count it for the next email.
            if (! Cache::add($suppressedKey, 1, 86400)) {
                Cache::increment($suppressedKey);
            }

            return ['allow' => false, 'suppressed' => 0];
        } catch (Throwable $e) {
            // Cache backend down — fail OPEN (send the alert). An extra email on a
            // cache hiccup is the right trade vs. a swallowed outage alert.
            Log::warning('MediaGenerationAlerter: throttle cache failed; alerting anyway', [
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return ['allow' => true, 'suppressed' => 0];
        }
    }
}

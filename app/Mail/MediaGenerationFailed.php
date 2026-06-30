<?php

namespace App\Mail;

use App\Services\Imagery\MediaGenerationAlerter;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Admin alert email for a media-generation failure (FAL account lockout or a
 * per-request generation failure). Body states the REASON and the ACTION
 * REQUIRED explicitly. Pinned to Resend via config('media.alerts.mailer') by
 * the caller (MediaGenerationAlerter).
 *
 * Scalar-only constructor (no Eloquent models) so the queued mail serialises
 * cleanly and never tries to re-hydrate a draft that may have changed by the
 * time the worker sends it.
 */
class MediaGenerationFailed extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $reason,
        public readonly string $mediaKind,
        public readonly string $reasonText,
        public readonly string $actionText,
        public readonly string $brandName,
        public readonly int $draftId,
        public readonly string $platform,
        public readonly string $detail = '',
        public readonly int $suppressedCount = 0,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('media.alerts.from_address', 'noreply@eiaawsolutions.com');
        $fromName = (string) config('media.alerts.from_name', 'EIAAW Social Media Team');

        $label = $this->reason === MediaGenerationAlerter::REASON_ACCOUNT_LOCKED
            ? 'FAL ACCOUNT LOCKED'
            : 'MEDIA GEN FAILED';

        $subject = sprintf(
            '[%s] %s %s failed — %s',
            $label,
            ucfirst($this->mediaKind),
            'generation',
            $this->brandName,
        );

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.media-generation-failed',
            with: [
                'isLockout' => $this->reason === MediaGenerationAlerter::REASON_ACCOUNT_LOCKED,
                'mediaKind' => $this->mediaKind,
                'reasonText' => $this->reasonText,
                'actionText' => $this->actionText,
                'brandName' => $this->brandName,
                'draftId' => $this->draftId,
                'platform' => $this->platform,
                'detail' => $this->detail,
                'suppressedCount' => $this->suppressedCount,
                'draftsUrl' => rtrim((string) config('app.url'), '/').'/agency/drafts',
            ],
        );
    }
}

<?php

namespace App\Mail;

use App\Models\SecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Operator alert email for HIGH-severity security events. Pinned to the
 * Resend mailer via mailer() so an accidental MAIL_MAILER change can't
 * route alerts onto a less reliable transport.
 *
 * Body is intentionally short — operator opens the security_events row
 * in the admin to see the full payload. Anything sensitive (the actual
 * prompt content) lives in the DB, not in the email body. Resend stores
 * email content; keeping evidence out keeps Resend out of the secrets
 * threat model.
 */
class SecurityAlert extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  SecurityEvent  $event  The persisted event row.
     * @param  int  $suppressedCount Events suppressed since the last alert; 0 if none.
     */
    public function __construct(
        public readonly SecurityEvent $event,
        public readonly int $suppressedCount = 0,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('security.alerts.from_address', 'security@eiaawsolutions.com');
        $fromName = (string) config('security.alerts.from_name', 'EIAAW Security');

        $subject = sprintf(
            '[%s] %s — %s',
            strtoupper($this->event->severity),
            $this->event->event_type,
            $this->event->category ?? 'security',
        );

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.security-alert',
            with: [
                'event' => $this->event,
                'suppressedCount' => $this->suppressedCount,
                'adminUrl' => $this->buildAdminUrl(),
            ],
        );
    }

    /**
     * Best-effort link into the Filament admin. The exact resource may or
     * may not exist yet — if not, the URL still resolves to /agency and
     * the operator can navigate from there.
     */
    private function buildAdminUrl(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        return $base . '/agency';
    }
}

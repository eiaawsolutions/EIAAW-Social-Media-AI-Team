<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent by HQ (via `workspace:set-blotato-handle --notify-customer`) once the
 * customer's dedicated Blotato account is provisioned.
 *
 * Carries the Blotato login URL, the account email, and the temp password the
 * operator generated when creating the Blotato account. The customer is told
 * to change it on first login. The temp password lives only in the email and
 * the operator's note — it is NOT stored in our DB.
 *
 * Why Resend-pinned: same reasoning as PostsCapWarning + SecurityAlert — this
 * is operational mail that must arrive even if the product transactional
 * mailer changes.
 */
class BlotatoAccountReady extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly string $loginUrl,
        public readonly string $blotatoAccountEmail,
        public readonly ?string $tempPassword,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.cap_warning.from_address', 'noreply@eiaawsolutions.com');
        $fromName = (string) config('mail.cap_warning.from_name', 'EIAAW Social Media Team');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: 'Your Blotato publishing account is ready',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.blotato-account-ready',
            with: [
                'workspace'           => $this->workspace,
                'loginUrl'            => $this->loginUrl,
                'blotatoAccountEmail' => $this->blotatoAccountEmail,
                'tempPassword'        => $this->tempPassword,
                'verifyUrl'           => rtrim((string) config('app.url'), '/') . '/agency/platform-setup',
            ],
        );
    }
}

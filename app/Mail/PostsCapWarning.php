<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 80%-of-monthly-cap warning sent once per period per workspace via Resend.
 *
 * Resend is pinned at the dispatch site (config('mail.cap_warning.mailer'))
 * for the same reason SecurityAlert pins it: a future MAIL_MAILER swap on
 * the product transactional path (Mailgun → Postmark, etc) shouldn't move
 * critical operational mails onto a less reliable transport.
 *
 * Body is intentionally short: tell them where they are (used / cap), what
 * happens at 100% (posts queue for next period), and how to lift the cap
 * (upgrade link). The Billing page already shows the full usage panel —
 * the email's job is just to redirect attention.
 */
class PostsCapWarning extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly int $postsUsed,
        public readonly int $postsCap,
        public readonly int $pctUsed,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.cap_warning.from_address', 'noreply@eiaawsolutions.com');
        $fromName = (string) config('mail.cap_warning.from_name', 'EIAAW Social Media Team');

        $subject = sprintf(
            "You've used %d%% of this month's posts — let's keep you publishing",
            $this->pctUsed,
        );

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.posts-cap-warning',
            with: [
                'workspace' => $this->workspace,
                'postsUsed' => $this->postsUsed,
                'postsCap' => $this->postsCap,
                'pctUsed' => $this->pctUsed,
                'planName' => (string) (config('billing.plans.' . $this->workspace->plan . '.name') ?? ucfirst((string) $this->workspace->plan)),
                'billingUrl' => rtrim((string) config('app.url'), '/') . '/agency/billing',
            ],
        );
    }
}

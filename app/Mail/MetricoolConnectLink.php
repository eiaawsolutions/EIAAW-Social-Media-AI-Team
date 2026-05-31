<?php

namespace App\Mail;

use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The Metricool connect-link handoff email — the Metricool analogue of
 * BlotatoAccountReady, for the connect-link onboarding ([[metricool-onboarding]]).
 *
 * Unlike Blotato (where HQ provisioned a dedicated account + creds), Metricool
 * is ONE shared agency account and the customer connects their own socials via
 * a per-brand SHARE-LINK that HQ mints by hand in the Metricool UI (there's no
 * API to mint it — see metricool-onboarding memory). This mail simply delivers
 * that share-link to the customer with clear "what to do next" steps; detection
 * of the result is automatic via the wizard's "Check now" button.
 *
 * Sent by `brand:send-metricool-link`. The brand must already be MAPPED
 * (metricool_blog_id set) — the command enforces that and stamps
 * metricool_connect_link_sent_at on success.
 *
 * Why Resend-pinned at the dispatch site (same as BlotatoAccountReady /
 * PostsCapWarning / SecurityAlert): this is operational onboarding mail that
 * must arrive even if the product transactional MAIL_MAILER changes. The
 * command sends it via mailer('resend') explicitly.
 */
class MetricoolConnectLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Workspace $workspace,
        public readonly Brand $brand,
        public readonly string $connectUrl,
    ) {}

    public function envelope(): Envelope
    {
        // Reuse the cap_warning sender identity — it's the established
        // EIAAW operational-mail from-address and is already verified on the
        // sending domain. Falls back to the global mail.from if unset.
        $fromAddress = (string) (config('mail.cap_warning.from_address')
            ?: config('mail.from.address', 'noreply@eiaawsolutions.com'));
        $fromName = (string) (config('mail.cap_warning.from_name')
            ?: config('mail.from.name', 'EIAAW Social Media Team'));

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            subject: sprintf('Connect your social accounts for %s', $this->brand->name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.metricool-connect-link',
            with: [
                'workspace'  => $this->workspace,
                'brand'      => $this->brand,
                'connectUrl' => $this->connectUrl,
                // Where they confirm once they've connected — the self-serve
                // "Check now" lives on the Platform setup wizard.
                'verifyUrl'  => rtrim((string) config('app.url'), '/') . '/agency/metricool-setup',
                'supportEmail' => (string) config('mail.support_enquiry.to', 'eiaawsolutions@gmail.com'),
            ],
        );
    }
}

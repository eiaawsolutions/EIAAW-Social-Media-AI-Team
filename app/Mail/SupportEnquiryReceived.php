<?php

namespace App\Mail;

use App\Models\SupportEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * HQ notification for a new "Talk to us" enquiry from the floating chatbot.
 *
 * The lead is already persisted to support_enquiries before this is sent — the
 * email is the push notification, the table is the source of truth. Mailer is
 * pinned at the dispatch site (config('mail.support_enquiry.mailer')).
 *
 * Reply-To is set to the visitor's submitted email so HQ can reply directly.
 */
class SupportEnquiryReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly SupportEnquiry $enquiry,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.support_enquiry.from_address', 'noreply@eiaawsolutions.com');
        $fromName = (string) config('mail.support_enquiry.from_name', 'EIAAW SMT — Website');

        $surfaceLabel = match ($this->enquiry->surface) {
            'client' => 'client panel',
            'hq' => 'HQ panel',
            default => 'landing page',
        };

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
            subject: sprintf('New SMT enquiry from %s (%s)', $this->enquiry->name, $surfaceLabel),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.support-enquiry',
            with: [
                'enquiry' => $this->enquiry,
            ],
        );
    }
}

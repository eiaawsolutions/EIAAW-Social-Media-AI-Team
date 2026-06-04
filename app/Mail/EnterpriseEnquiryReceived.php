<?php

namespace App\Mail;

use App\Models\EnterpriseEnquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * HQ notification for a new Enterprise "Talk to us" lead from /enterprise.
 *
 * The lead is already persisted to enterprise_enquiries before this is sent —
 * the email is the push notification, the table is the source of truth. Mailer
 * is pinned at the dispatch site (config('mail.support_enquiry.mailer')).
 *
 * Reply-To is the lead's work email so HQ can reply directly. Subject is tagged
 * [ENTERPRISE] so the sales pipeline is filterable from generic web enquiries.
 */
class EnterpriseEnquiryReceived extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly EnterpriseEnquiry $enquiry,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = (string) config('mail.support_enquiry.from_address', 'noreply@eiaawsolutions.com');
        $fromName = (string) config('mail.support_enquiry.from_name', 'EIAAW SMT — Website');

        return new Envelope(
            from: new Address($fromAddress, $fromName),
            replyTo: [new Address($this->enquiry->email, $this->enquiry->name)],
            subject: sprintf('[ENTERPRISE] %s — %s', $this->enquiry->company, $this->enquiry->name),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.enterprise-enquiry',
            with: ['enquiry' => $this->enquiry],
        );
    }
}

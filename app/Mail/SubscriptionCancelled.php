<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Confirms a customer-requested cancellation. Queued to the workspace owner
 * from the in-panel Cancel action (and the HQ admin cancel). States plainly
 * that access continues until the paid period ends and that reactivation is
 * possible before then — the international SaaS norm (cancel-at-period-end,
 * no clawback).
 *
 * Transport: the global default mailer, pinned to Resend in production via
 * MAIL_MAILER + the MailTransport guard ([[resend_mail_wiring]]). This class
 * does not pin the transport itself — same as TrialEndingSoon.
 */
class SubscriptionCancelled extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Workspace $workspace,
        public ?Carbon $endsAt = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your EIAAW subscription is cancelling — '.$this->workspace->name,
        );
    }

    public function content(): Content
    {
        $planConfig = config('billing.plans.'.$this->workspace->plan);

        return new Content(
            view: 'emails.subscription-cancelled',
            with: [
                'workspace'  => $this->workspace,
                'owner'      => $this->workspace->owner,
                'planName'   => $planConfig['name'] ?? ucfirst($this->workspace->plan),
                'endsAt'     => $this->endsAt,
                'billingUrl' => url('/agency/billing'),
            ],
        );
    }
}

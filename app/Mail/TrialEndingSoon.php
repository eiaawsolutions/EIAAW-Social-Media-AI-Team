<?php

namespace App\Mail;

use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent T-3 days before the trial ends. Stripe fires the
 * customer.subscription.trial_will_end webhook automatically; we listen
 * in StripeWebhookController and queue this mail to the workspace owner.
 */
class TrialEndingSoon extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Workspace $workspace) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your EIAAW trial ends soon — '.$this->workspace->name,
        );
    }

    public function content(): Content
    {
        $planConfig = config('billing.plans.'.$this->workspace->plan);

        return new Content(
            view: 'emails.trial-ending-soon',
            with: [
                'workspace'    => $this->workspace,
                'owner'        => $this->workspace->owner,
                'planName'     => $planConfig['name'] ?? ucfirst($this->workspace->plan),
                'priceMyr'     => $planConfig['price_myr'] ?? null,
                'trialEndsAt'  => $this->workspace->trial_ends_at,
                'billingUrl'   => url('/agency/billing'),
            ],
        );
    }
}

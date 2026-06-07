<?php

namespace App\Services\Metricool;

use App\Mail\MetricoolConnectLink;
use App\Models\Brand;
use Illuminate\Support\Facades\Mail;

/**
 * Single code path for "store + email a Metricool connect-link to a customer".
 *
 * Shared by the operator command (brand:send-metricool-link) and the HQ
 * Metricool-onboarding console's one-click "Store & send" button. Centralising
 * it means both surfaces apply the SAME guards — valid https URL, a delivering
 * transport (never a fake "sent" through log/array or a keyless Resend), a
 * synchronous send so failures surface, and the store/stamp ordering — so the
 * UI button can never silently diverge from the battle-tested command.
 *
 * Returns a structured result instead of throwing for the expected failure
 * modes, so callers (CLI + Livewire) can render their own messaging.
 *
 * Mail discipline (same rationale as the command — [[resend-mail-wiring]],
 * [[queued-mail-verify-at-provider]]): pinned to the cap_warning mailer
 * (normally Resend), sent ->send() not ->queue() so a transport error is caught
 * here, and the brand is only stamped link_sent AFTER a confirmed send.
 */
class MetricoolConnectLinkSender
{
    /**
     * @return array{ok:bool, code:string, message:string, recipient?:string, state?:string}
     *   code ∈ not_mapped | no_workspace | bad_url | no_recipient | transport | send_failed | sent
     */
    public function send(Brand $brand, string $url, ?string $toOverride = null, ?string $mailerOverride = null): array
    {
        if (empty($brand->metricool_blog_id)) {
            return ['ok' => false, 'code' => 'not_mapped',
                'message' => "Brand #{$brand->id} ({$brand->name}) isn't mapped to a Metricool blogId yet — map it first."];
        }

        // Validate the URL before touching anything else — it's the operator's
        // direct input and the cheapest, most-specific thing to get wrong.
        $url = trim($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'code' => 'bad_url',
                'message' => "Connect URL must be a valid https:// URL (got '{$url}')."];
        }

        $workspace = $brand->workspace;
        if (! $workspace) {
            return ['ok' => false, 'code' => 'no_workspace',
                'message' => "Brand #{$brand->id} has no workspace."];
        }

        $to = trim((string) $toOverride) ?: (string) optional($workspace->owner)->email;
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'code' => 'no_recipient',
                'message' => 'No valid recipient — the workspace owner has no email on file.'];
        }

        $mailer = (string) ($mailerOverride ?: config('mail.cap_warning.mailer', 'resend') ?: 'resend');

        $transportError = $this->transportBlocker($mailer);
        if ($transportError !== null) {
            return ['ok' => false, 'code' => 'transport', 'message' => $transportError];
        }

        try {
            // Synchronous so a transport error surfaces here, not silently in a
            // worker after we've already reported success.
            Mail::mailer($mailer)->to($to)->send(new MetricoolConnectLink($workspace, $brand, $url));
        } catch (\Throwable $e) {
            return ['ok' => false, 'code' => 'send_failed',
                'message' => 'Send failed — nothing was delivered and no stamp written: ' . $e->getMessage()];
        }

        // Store the durable last-sent link (audit) + stamp link_sent — only AFTER
        // a confirmed send, so a failed send leaves the brand state untouched.
        $brand->forceFill([
            'metricool_connect_url' => $url,
            'metricool_connect_link_sent_at' => now(),
        ])->save();

        return [
            'ok' => true,
            'code' => 'sent',
            'message' => "Sent connect-link to {$to} via '{$mailer}'.",
            'recipient' => $to,
            'state' => $brand->fresh()->metricoolSetupState(),
        ];
    }

    /**
     * Return a human error string if the named mailer cannot actually deliver
     * (no-op log/array transport, or Resend without both keys), else null.
     * Mirrors BrandSendMetricoolLink::assertTransportCanDeliver.
     */
    private function transportBlocker(string $mailer): ?string
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        if (in_array($transport, ['log', 'array'], true)) {
            return "Mailer '{$mailer}' uses the '{$transport}' transport, which does NOT deliver real email. "
                . 'Set a real mailer (RESEND_KEY for resend) before sending.';
        }

        if ($transport === 'resend') {
            // Both the transport key (services.resend.key) AND the package client
            // key (resend.api_key) must be set, or the send fails in the worker
            // after a fake "sent". See [[resend-mail-wiring]].
            if (empty(config('services.resend.key')) || empty(config('resend.api_key'))) {
                return 'Resend is not fully configured (services.resend.key / resend.api_key empty). '
                    . 'Set RESEND_KEY (Infisical handle) before sending.';
            }
        }

        if ($transport === 'mailgun' && empty(config('services.mailgun.secret'))) {
            return "Mailer '{$mailer}' is Mailgun, but services.mailgun.secret is empty.";
        }

        return null;
    }
}

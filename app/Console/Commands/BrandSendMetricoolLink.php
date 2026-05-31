<?php

namespace App\Console\Commands;

use App\Mail\MetricoolConnectLink;
use App\Models\Brand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * brand:send-metricool-link — email a Metricool connect-link to a customer and
 * stamp metricool_connect_link_sent_at. The send half of the connect-link
 * onboarding ([[metricool-onboarding]]); pairs with
 * `brand:set-metricool-blog --mark-link-sent` (which only stamps, doesn't mail).
 *
 * The share-link is minted BY HAND in the Metricool UI (no API to mint it), so
 * the operator passes it in as the {url} argument. The brand must already be
 * mapped (metricool_blog_id set) — there's nothing to connect to otherwise.
 *
 * Recipient defaults to the workspace owner's email; override with --to.
 *
 * Resend-pinned: the mail is sent via the 'resend' mailer explicitly (same
 * rationale as BlotatoAccountReady / PostsCapWarning) regardless of the product
 * MAIL_MAILER. Because EIAAW's default MAIL_MAILER is 'log', this command does a
 * PRE-FLIGHT transport check and REFUSES to "send" into a no-op transport — it
 * will not pretend a log-only mailer delivered real mail. Set RESEND_KEY (a
 * secret:// handle in Railway env → Infisical value) to enable delivery.
 *
 * Usage:
 *   php artisan brand:send-metricool-link 8 https://f.mtr.cool/IPDVUOFUIU
 *   php artisan brand:send-metricool-link 8 https://f.mtr.cool/XXXX --to=owner@example.com
 *   php artisan brand:send-metricool-link 8 https://f.mtr.cool/XXXX --dry-run
 *   php artisan brand:send-metricool-link 8 https://f.mtr.cool/XXXX --mailer=log   # force (testing only)
 */
class BrandSendMetricoolLink extends Command
{
    protected $signature = 'brand:send-metricool-link
                            {brand : Brand ID (must already be mapped to a Metricool blogId)}
                            {url : The Metricool connect/share-link (https://f.mtr.cool/...)}
                            {--to= : Override recipient email (defaults to the workspace owner)}
                            {--mailer= : Override the mailer (default: the pinned cap_warning mailer, normally resend)}
                            {--no-stamp : Send without stamping metricool_connect_link_sent_at}
                            {--dry-run : Resolve recipient + validate, but do not send}';

    protected $description = 'Email a Metricool connect-link to a customer and mark it sent (operator-only).';

    public function handle(): int
    {
        $brandId = (int) $this->argument('brand');
        $brand = Brand::find($brandId);
        if (! $brand) {
            $this->error("Brand #{$brandId} not found.");
            return self::FAILURE;
        }

        if (empty($brand->metricool_blog_id)) {
            $this->error("Brand #{$brandId} ({$brand->name}) is not mapped to a Metricool blogId yet. Run brand:set-metricool-blog first.");
            return self::FAILURE;
        }

        $url = trim((string) $this->argument('url'));
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            $this->error("Connect URL must be a valid https:// URL (got '{$url}').");
            return self::FAILURE;
        }

        $workspace = $brand->workspace;
        if (! $workspace) {
            $this->error("Brand #{$brandId} has no workspace.");
            return self::FAILURE;
        }

        $to = trim((string) $this->option('to'))
            ?: (string) optional($workspace->owner)->email;
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('No valid recipient. Workspace owner has no email — pass --to=someone@example.com.');
            return self::FAILURE;
        }

        // Resolve the mailer: pinned to the cap_warning mailer (normally resend)
        // unless overridden, mirroring the other operational mails.
        $mailer = (string) ($this->option('mailer')
            ?: config('mail.cap_warning.mailer', 'resend'));

        $this->line('');
        $this->line("  Brand      : #{$brand->id} {$brand->name} (blogId {$brand->metricool_blog_id})");
        $this->line("  Workspace  : #{$workspace->id} {$workspace->name}");
        $this->line("  Recipient  : {$to}");
        $this->line("  Mailer     : {$mailer}");
        $this->line("  Connect URL: {$url}");
        $this->line('');

        // Pre-flight: refuse to "send" through a transport that won't deliver.
        // EIAAW's default MAIL_MAILER is 'log'; the pinned mailer is resend but
        // its key may be unset. Don't claim a send that goes nowhere.
        if (! $this->option('dry-run')) {
            $guard = $this->assertTransportCanDeliver($mailer);
            if ($guard !== null) {
                return $guard;
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no email sent, no stamp written.');
            return self::SUCCESS;
        }

        try {
            // Sent synchronously (->send, not ->queue) so a transport error
            // surfaces here as a non-zero exit, rather than failing silently in
            // a background worker after we've already reported success.
            Mail::mailer($mailer)
                ->to($to)
                ->send(new MetricoolConnectLink($workspace, $brand, $url));
        } catch (\Throwable $e) {
            $this->error('Send FAILED — nothing was delivered and no stamp written:');
            $this->error('  ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info("Sent connect-link to {$to} via '{$mailer}'.");

        if (! $this->option('no-stamp')) {
            $brand->forceFill(['metricool_connect_link_sent_at' => now()])->save();
            $this->info("Stamped metricool_connect_link_sent_at — brand state is now: {$brand->fresh()->metricoolSetupState()}.");
        } else {
            $this->line('Skipped stamping (--no-stamp).');
        }

        return self::SUCCESS;
    }

    /**
     * Return a non-null failure code if the named mailer cannot actually
     * deliver mail (so the caller bails before pretending to send).
     */
    private function assertTransportCanDeliver(string $mailer): ?int
    {
        $transport = (string) config("mail.mailers.{$mailer}.transport", $mailer);

        // 'log' / 'array' are no-op transports — they never deliver.
        if (in_array($transport, ['log', 'array'], true)) {
            $this->error("Mailer '{$mailer}' uses the '{$transport}' transport, which does NOT deliver real email.");
            $this->line("Set a real mailer (RESEND_KEY for resend) or pass --mailer=<live mailer>. Aborting to avoid a fake 'sent'.");
            return self::FAILURE;
        }

        // resend needs a key; without it the send throws a cryptic auth error
        // mid-flight. Catch it up front with a clear remediation. Check BOTH
        // config paths: the Laravel mail transport uses services.resend.key,
        // but the resend/resend-laravel package's client (which actually
        // transmits queued mail) uses resend.api_key. A populated transport key
        // with an empty package key fails ONLY in the worker, after this
        // command has already reported "Sent" — so guard the package key too.
        if ($transport === 'resend') {
            if (empty(config('services.resend.key'))) {
                $this->error("Mailer '{$mailer}' is Resend, but services.resend.key is empty.");
                $this->line('Set RESEND_KEY (a secret:// handle → Infisical value) for eiaaw-smt-prod, then retry.');
                $this->line('Per the EIAAW Deploy Contract the raw key lives in Infisical, NOT in Railway env.');
                return self::FAILURE;
            }
            if (empty(config('resend.api_key'))) {
                $this->error('resend.api_key is empty — the resend-laravel package client has no key.');
                $this->line('config/resend.php must read env(RESEND_KEY) and resend.api_key must be in config/secrets.php resolve list.');
                $this->line('Without this the send is enqueued OK but FAILS in the worker (ApiKeyIsMissing).');
                return self::FAILURE;
            }
        }

        if ($transport === 'mailgun' && empty(config('services.mailgun.secret'))) {
            $this->error("Mailer '{$mailer}' is Mailgun, but services.mailgun.secret is empty.");
            return self::FAILURE;
        }

        return null;
    }
}

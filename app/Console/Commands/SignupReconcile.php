<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\SignupProvisioner;
use App\Services\Billing\SignupProvisionResult;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

/**
 * signup:reconcile — daily backstop that catches any paid signup the webhook
 * AND the success() redirect both missed (e.g. a Stripe webhook outage that
 * outlasted Stripe's 3-day retry window, or a bug that 500'd both paths).
 *
 * Sweeps Stripe Checkout Sessions created in the last --hours, keeps only
 * signup sessions (metadata.intent=signup) that are paid/trialing, and finds
 * the ones with NO matching account in our DB — "stranded" customers who paid
 * but never got provisioned ([[signup_provisioning_gap]] / [[signup_hardening]]).
 *
 * A session is matched (NOT stranded) when EITHER:
 *   - a Workspace already has its stripe_customer_id, OR
 *   - a User already has its email (the provisioner's idempotency key).
 * Only when neither resolves is the session stranded.
 *
 * Behaviour:
 *   bare run           → AUTO-PROVISION each stranded session via the shared,
 *                        idempotent SignupProvisioner (emails credentials on the
 *                        pinned Resend transport). This is the operator default.
 *   --report-only      → list stranded sessions, write NOTHING, email HQ if any
 *                        are found. THIS is what the daily scheduler uses — an
 *                        unattended run must never auto-create accounts.
 *   --dry-run          → alias for --report-only (no DB writes), but does NOT
 *                        email HQ. Pure inspection.
 *
 * The webhook is the real-time net; this is the slow daily backstop. Belt and
 * suspenders — neither alone, both together.
 *
 * Usage:
 *   php artisan signup:reconcile                      # auto-provision stranded (last 48h)
 *   php artisan signup:reconcile --report-only        # list + alert HQ, no writes
 *   php artisan signup:reconcile --hours=168 --dry-run # inspect last 7 days
 */
class SignupReconcile extends Command
{
    protected $signature = 'signup:reconcile
        {--hours=48 : Look back this many hours of Stripe Checkout Sessions}
        {--report-only : List + alert HQ only; never provision (used by the daily scheduler)}
        {--dry-run : List only; no DB writes and no HQ email (pure inspection)}';

    protected $description = 'Sweep recent Stripe Checkout Sessions for paid signups with no matching account; auto-provision (or report) the stranded ones.';

    public function handle(SignupProvisioner $provisioner): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $dryRun = (bool) $this->option('dry-run');
        $reportOnly = (bool) $this->option('report-only') || $dryRun;

        $since = now()->subHours($hours);

        $this->info("signup:reconcile — scanning Stripe Checkout Sessions since {$since->toIso8601String()} ({$hours}h)");
        if ($reportOnly) {
            $this->comment($dryRun ? 'DRY-RUN: report only, no DB writes, no HQ email.' : 'REPORT-ONLY: no DB writes; HQ alerted if any stranded.');
        } else {
            $this->comment('AUTO-PROVISION mode: stranded sessions WILL be provisioned + credential-emailed.');
        }

        try {
            $sessions = $this->collectSignupSessions($since);
        } catch (\Throwable $e) {
            Log::error('signup:reconcile — Stripe session list failed', ['error' => $e->getMessage()]);
            $this->error('Stripe session list failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->line('Signup sessions in window: ' . count($sessions));

        $stranded = array_values(array_filter($sessions, fn ($s) => $this->isStranded($s)));

        if (empty($stranded)) {
            $this->info('No stranded paid signups. Webhook + success() covered everything. ✅');
            return self::SUCCESS;
        }

        $this->warn(count($stranded) . ' STRANDED paid signup(s) found (paid in Stripe, no account in DB):');
        $rows = [];
        foreach ($stranded as $s) {
            $meta = $this->metadataOf($s);
            $rows[] = [
                $s->id,
                $s->payment_status ?? '(null)',
                strtolower($meta['email'] ?? ($s->customer_email ?? '(none)')),
                $meta['workspace_name'] ?? '(none)',
                $meta['plan'] ?? '(none)',
                Carbon::createFromTimestamp($s->created)->toDateTimeString(),
            ];
        }
        $this->table(['session', 'pay', 'email', 'workspace', 'plan', 'created'], $rows);

        // Report-only / dry-run: do not provision.
        if ($reportOnly) {
            if (! $dryRun) {
                $this->alertHq($stranded);
                $this->line('HQ alerted.');
            }
            $this->comment('Report-only — re-run WITHOUT --report-only to provision these.');
            return self::SUCCESS;
        }

        // Auto-provision each stranded session via the shared provisioner.
        $provisioned = 0;
        $failed = 0;
        foreach ($stranded as $s) {
            try {
                $full = Cashier::stripe()->checkout->sessions->retrieve($s->id, [
                    'expand' => ['subscription', 'customer'],
                ]);
                $result = $provisioner->provisionFromSession($full, sendWelcomeEmail: true);

                if ($result->wasProvisioned()) {
                    $provisioned++;
                    $this->info("  ✓ provisioned {$s->id} → workspace #{$result->workspace?->id}");
                    Log::warning("signup:reconcile RECOVERED stranded signup {$s->id} → workspace #{$result->workspace?->id}");
                } elseif ($result->status === SignupProvisionResult::ALREADY_PROVISIONED) {
                    // Raced with the webhook between the list and now — fine.
                    $this->line("  · {$s->id} already provisioned (raced) — skipped.");
                } else {
                    $failed++;
                    $this->error("  ✗ {$s->id} not provisioned: {$result->status} ({$result->reason})");
                }
            } catch (\Throwable $e) {
                $failed++;
                Log::error("signup:reconcile — provisioning {$s->id} failed", ['error' => $e->getMessage()]);
                $this->error("  ✗ {$s->id} threw: " . $e->getMessage());
            }
        }

        $this->newLine();
        $this->info("Done. Provisioned: {$provisioned}. Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Page through Stripe Checkout Sessions created since $since, keeping only
     * signup sessions that are paid or trialing. Bounded paging (defensive cap)
     * so a runaway never lists the whole account.
     *
     * @return array<int, object>
     */
    private function collectSignupSessions(Carbon $since): array
    {
        $stripe = Cashier::stripe();
        $kept = [];
        $params = [
            'created' => ['gte' => $since->getTimestamp()],
            'limit' => 100,
        ];

        $pages = 0;
        do {
            $page = $stripe->checkout->sessions->all($params);
            foreach ($page->data as $s) {
                $meta = $this->metadataOf($s);
                if (($meta['intent'] ?? null) !== 'signup') {
                    continue;
                }
                $paid = ($s->payment_status ?? null) === 'paid'
                    || ($s->payment_status ?? null) === 'no_payment_required';
                // status='complete' + paid is the normal completed signup; an
                // 'open'/'expired' session never charged, so skip it.
                if (! $paid) {
                    continue;
                }
                $kept[] = $s;
            }
            $hasMore = $page->has_more ?? false;
            if ($hasMore && ! empty($page->data)) {
                $params['starting_after'] = end($page->data)->id;
            }
            $pages++;
        } while ($hasMore && $pages < 20); // 20×100 = 2000 sessions — far beyond any real daily window.

        if ($pages >= 20) {
            Log::warning('signup:reconcile — hit the 20-page cap; window may be too wide.');
            $this->warn('Note: hit the 20-page scan cap (2000 sessions). Narrow --hours if this recurs.');
        }

        return $kept;
    }

    /** A session is stranded when NEITHER its customer nor its email resolves to an account. */
    private function isStranded(object $session): bool
    {
        $customerId = is_string($session->customer ?? null)
            ? $session->customer
            : ($session->customer->id ?? null);

        if ($customerId && Workspace::where('stripe_customer_id', $customerId)->exists()) {
            return false;
        }

        $meta = $this->metadataOf($session);
        $email = strtolower($meta['email'] ?? ($session->customer_email ?? ''));
        if ($email && User::where('email', $email)->exists()) {
            return false;
        }

        return true;
    }

    /** Normalise Stripe metadata (StripeObject or array) to a plain array. */
    private function metadataOf(object $session): array
    {
        $raw = $session->metadata ?? null;
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw) && method_exists($raw, 'toArray')) {
            return $raw->toArray();
        }
        return [];
    }

    /** Email HQ a plain-text summary of stranded sessions on the pinned ops mailer. */
    private function alertHq(array $stranded): void
    {
        try {
            $mailer = (string) config('mail.support_enquiry.mailer', 'resend');
            $to = (string) (config('mail.support_enquiry.to') ?: 'eiaawsolutions@gmail.com');
            $from = (string) (config('mail.support_enquiry.from_address') ?: 'noreply@eiaawsolutions.com');

            $lines = ["signup:reconcile found " . count($stranded) . " STRANDED paid signup(s) — paid in Stripe, no account in DB.\n"];
            foreach ($stranded as $s) {
                $meta = $this->metadataOf($s);
                $lines[] = sprintf(
                    "- session=%s  email=%s  workspace=%s  plan=%s  created=%s",
                    $s->id,
                    strtolower($meta['email'] ?? ($s->customer_email ?? '?')),
                    $meta['workspace_name'] ?? '?',
                    $meta['plan'] ?? '?',
                    Carbon::createFromTimestamp($s->created)->toDateTimeString(),
                );
            }
            $lines[] = "\nProvision them with:  php artisan signup:reconcile --hours=72";
            $lines[] = "(or one at a time:  php artisan billing:reconcile-session --session=<id> --apply)";
            $body = implode("\n", $lines);

            $count = count($stranded);
            Mail::mailer($mailer)->raw($body, function ($m) use ($to, $from, $count) {
                $m->to($to)
                  ->subject("[SMT ops] {$count} stranded paid signup(s) — action needed")
                  ->from($from, 'EIAAW SMT — Reconcile bot');
            });
        } catch (\Throwable $e) {
            Log::error('signup:reconcile — HQ alert email failed', ['error' => $e->getMessage()]);
        }
    }
}

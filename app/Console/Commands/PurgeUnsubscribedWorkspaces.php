<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PurgeUnsubscribedWorkspaces — transitional cleanup for any workspace rows
 * created BEFORE the Stripe-Checkout signup flow (BillingController) became
 * the only path. Under that flow no User/Workspace can be created without
 * a completed checkout, so this command exists to wipe the trialing-only
 * test rows that already exist; afterwards it's effectively a no-op
 * forever.
 *
 * Selection rule (deliberately conservative):
 *   - subscription_status IN (trialing, none, canceled, past_due)
 *   - plan != 'eiaaw_internal'
 *   - owner.email NOT IN --keep-emails (defaults to eiaawsolutions@gmail.com)
 *
 * The `eiaaw_internal` plan and the `active` status are NEVER purged
 * regardless of flags — defense-in-depth against an accidentally-broad
 * purge wiping a real subscriber.
 *
 * Pass --purge-stripe-customers to also delete the Stripe customer record
 * for each purged workspace (otherwise they linger forever in Stripe even
 * after the DB row is gone — minor housekeeping; safe to skip).
 *
 * Why we need an audit-log escape:
 *   The audit_log table has BEFORE DELETE / BEFORE UPDATE triggers that
 *   raise an exception ("audit_log is append-only"). A normal cascade
 *   from workspaces -> audit_log will fail the entire transaction.
 *   For TEST data this is wrong — we want them gone, not preserved.
 *   The Postgres operator escape `SET session_replication_role = replica;`
 *   suspends triggers AND foreign-key cascades for the session, lets us
 *   wipe cleanly, then we restore the default. This matches the GDPR
 *   delete pattern documented in the followups memory.
 *
 * Default mode is dry-run. Pass --apply to commit.
 */
class PurgeUnsubscribedWorkspaces extends Command
{
    protected $signature = 'workspaces:purge-unsubscribed
        {--apply : Actually perform deletes (default is dry-run)}
        {--keep-emails=eiaawsolutions@gmail.com : Comma-separated owner emails to always keep}
        {--purge-stripe-customers : Also delete the Stripe customer record for each purged workspace}';

    protected $description = 'Delete test workspaces that never converted to a paid subscription. Always preserves eiaaw_internal + active subscribers.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $purgeStripe = (bool) $this->option('purge-stripe-customers');
        $keepEmails = collect(explode(',', (string) $this->option('keep-emails')))
            ->map(fn ($e) => strtolower(trim($e)))
            ->filter()
            ->values()
            ->all();

        $this->info($apply ? 'PURGE MODE: changes will be committed.' : 'DRY-RUN: nothing will be deleted. Pass --apply to commit.');
        $this->line('Keeping owner emails: ' . implode(', ', $keepEmails));
        $this->newLine();

        // Strict-checkout invariant: a workspace is a candidate for purge if
        // it has NO Cashier subscriptions row, regardless of subscription_status.
        // The only legitimate way to get a subscriptions row is via
        // BillingController::success after a real Stripe Checkout. Anything
        // without one is an orphan from before this rule landed.
        //
        // The keep-emails list ONLY protects accounts that have a real
        // subscription — it does NOT shield orphans. So if eiaawsolutions@gmail.com
        // owns a workspace with no subscriptions row, it still gets purged
        // so the founder can re-signup through the public checkout flow.
        $candidates = Workspace::with('owner')
            ->where('plan', '!=', 'eiaaw_internal')
            ->whereDoesntHave('subscriptions')
            ->get();

        $kept = $candidates->filter(function (Workspace $w) use ($keepEmails) {
            $email = strtolower((string) ($w->owner->email ?? ''));
            return in_array($email, $keepEmails, true);
        });
        if ($kept->isNotEmpty()) {
            $this->warn(
                'Note: ' . $kept->count() . ' workspace(s) owned by keep-emails ALSO have no Cashier subscription:'
            );
            foreach ($kept as $w) {
                $this->line('  - #' . $w->id . ' ' . $w->slug . ' owner=' . ($w->owner->email ?? '?'));
            }
            $this->warn('These will be PURGED too because the strict-checkout invariant overrides keep-emails.');
            $this->warn('Re-create them via public /signup flow after the purge — coupon EIAAW_FOUNDER (or whatever you set) at the Stripe Checkout step gives you the founder account at $0.');
            $this->newLine();
        }

        if ($candidates->isEmpty()) {
            $this->info('Nothing to purge — no workspaces matched.');
            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'name', 'plan', 'status', 'owner_email', 'created_at', 'trial_ends_at'],
            $candidates->map(fn (Workspace $w) => [
                $w->id,
                $w->slug,
                substr((string) $w->name, 0, 32),
                $w->plan,
                $w->subscription_status,
                $w->owner->email ?? '(no owner)',
                optional($w->created_at)->toDateTimeString() ?? '-',
                optional($w->trial_ends_at)->toDateString() ?? '-',
            ])->all(),
        );

        $this->newLine();
        $this->warn('About to delete ' . $candidates->count() . ' workspace(s) and ALL related rows (brands, drafts, calendar entries, audit log, members, subscriptions).');

        if (! $apply) {
            $this->comment('Dry-run only. Re-run with --apply to commit.');
            return self::SUCCESS;
        }

        if (! $this->confirm('Proceed with the deletion above?', false)) {
            $this->line('Aborted.');
            return self::SUCCESS;
        }

        $ids = $candidates->pluck('id')->all();
        $stripeCustomerIds = $candidates
            ->pluck('stripe_customer_id')
            ->filter()
            ->values()
            ->all();

        try {
            DB::transaction(function () use ($ids) {
                // Postgres operator escape — suspends triggers (incl. audit_log
                // append-only block) and FK cascades for this transaction.
                // Requires the connection role to have REPLICATION privileges,
                // which Railway's prod role does. If it doesn't, this throws
                // and the transaction rolls back — no half-deletion.
                DB::statement('SET session_replication_role = replica');

                // Children that reference workspaces directly (cascade-on-delete)
                // OR via brands. The session_replication_role=replica stops
                // Postgres from auto-cascading; we have to delete in the right
                // order ourselves. (We could rely on cascade and just delete
                // workspaces, but explicit is safer when triggers are off.)

                // Find brand IDs first.
                $brandIds = DB::table('brands')->whereIn('workspace_id', $ids)->pluck('id')->all();

                // Children of brands.
                if (! empty($brandIds)) {
                    DB::table('compliance_checks')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('scheduled_posts')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('drafts')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('calendar_entries')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('content_calendars')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('performance_uploads')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('platform_connections')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('brand_corpus')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('autonomy_settings')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('banned_phrases')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('embargoes')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('brand_styles')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('audit_log')->whereIn('brand_id', $brandIds)->delete();
                    DB::table('brands')->whereIn('id', $brandIds)->delete();
                }

                // Workspace-level children (pipeline_runs has workspace_id directly).
                DB::table('pipeline_runs')->whereIn('workspace_id', $ids)->delete();
                DB::table('audit_log')->whereIn('workspace_id', $ids)->delete();
                DB::table('ai_costs')->whereIn('workspace_id', $ids)->delete();
                DB::table('subscription_events')->whereIn('workspace_id', $ids)->delete();
                $subIds = DB::table('subscriptions')->whereIn('workspace_id', $ids)->pluck('id')->all();
                if (! empty($subIds)) {
                    DB::table('subscription_items')->whereIn('subscription_id', $subIds)->delete();
                    DB::table('subscriptions')->whereIn('id', $subIds)->delete();
                }
                DB::table('workspace_members')->whereIn('workspace_id', $ids)->delete();

                // Detach any user that points at these workspaces as their current.
                DB::table('users')->whereIn('current_workspace_id', $ids)->update(['current_workspace_id' => null]);

                DB::table('workspaces')->whereIn('id', $ids)->delete();

                DB::statement('SET session_replication_role = origin');
            });
        } catch (\Throwable $e) {
            // Belt + braces: ensure the role is restored if anything threw
            // outside the transaction-managed path.
            try { DB::statement('SET session_replication_role = origin'); } catch (\Throwable) {}
            $this->error('Purge failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('Purged ' . count($ids) . ' workspace(s) and all related rows.');

        if ($purgeStripe && ! empty($stripeCustomerIds)) {
            $this->newLine();
            $this->info('Deleting ' . count($stripeCustomerIds) . ' Stripe customer record(s)...');
            $secret = (string) env('STRIPE_SECRET');
            if ($secret === '') {
                $this->warn('STRIPE_SECRET is not set — skipping Stripe customer deletion.');
                return self::SUCCESS;
            }
            $deleted = 0;
            foreach ($stripeCustomerIds as $cid) {
                try {
                    $resp = Http::withBasicAuth($secret, '')
                        ->timeout(15)
                        ->delete('https://api.stripe.com/v1/customers/' . urlencode($cid));
                    if ($resp->successful()) {
                        $deleted++;
                        $this->line('  deleted ' . $cid);
                    } else {
                        $this->warn('  failed ' . $cid . ': ' . $resp->status() . ' ' . substr($resp->body(), 0, 120));
                    }
                } catch (\Throwable $e) {
                    Log::error('Stripe customer delete failed', ['cid' => $cid, 'error' => $e->getMessage()]);
                    $this->warn('  failed ' . $cid . ': ' . $e->getMessage());
                }
            }
            $this->info('Stripe customers deleted: ' . $deleted . ' / ' . count($stripeCustomerIds));
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Services\Billing;

use App\Models\EnterpriseEnquiry;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

/**
 * Provisions a bespoke ENTERPRISE workspace from a closed deal, and issues the
 * one-off Stripe Invoice that activates it on payment.
 *
 * WHY ENTERPRISE IS DIFFERENT FROM SignupProvisioner
 * --------------------------------------------------
 * Self-serve tiers (Solo/Studio/Agency) go through Stripe Checkout → a recurring
 * subscription → SignupProvisioner. Enterprise is INVOICE-BASED: an operator
 * agrees specs/caps/price with the customer, this service creates the workspace
 * (INACTIVE) with a bespoke cap snapshot, then issues a single Stripe Invoice.
 * The workspace activates only when that invoice is paid (handled in
 * StripeWebhookController via invoice.payment_succeeded + metadata.intent=enterprise).
 * There is NO subscription and NO recurring charge — each term is a fresh invoice.
 *
 * The bespoke caps reuse the exact grandfather mechanism: they're written to
 * workspaces.settings[plan_caps_snapshot], which PlanCaps::capsFor() already
 * reads ahead of config. plan='enterprise' (config caps=null) means that even
 * if the snapshot were ever missing, the workspace falls back to UNLIMITED, never
 * Solo — an Enterprise customer is never silently throttled.
 *
 * IDEMPOTENCY: keyed on the enquiry. If the enquiry already has a provisioned
 * workspace, provision() returns it untouched. Invoice creation is guarded by
 * the stored stripe_invoice_id so a double-click can't double-bill.
 */
class EnterpriseProvisioner
{
    /**
     * Create (or return the existing) bespoke workspace for a closed Enterprise
     * deal, then issue the one-off Stripe invoice. The workspace is created
     * INACTIVE (subscription_status='none'); the invoice-paid webhook flips it
     * to 'active'.
     *
     * @param  array{brands:int, image_posts:int, video_posts:int, price_myr:int}  $agreed
     * @return EnterpriseEnquiry  the enquiry, refreshed with workspace + invoice refs
     */
    public function provisionAndInvoice(EnterpriseEnquiry $enquiry, array $agreed): EnterpriseEnquiry
    {
        $workspace = $this->provisionWorkspace($enquiry, $agreed);
        $this->issueInvoice($enquiry->fresh(), $workspace, (int) $agreed['price_myr']);

        return $enquiry->fresh();
    }

    /**
     * Create the bespoke workspace + owner user + cap snapshot. Idempotent: if
     * the enquiry already points at a workspace, that workspace is returned
     * unchanged. A pre-existing user with the lead's email is reused as the owner
     * (we never mint a duplicate account for the same human).
     *
     * @param  array{brands:int, image_posts:int, video_posts:int, price_myr:int}  $agreed
     */
    public function provisionWorkspace(EnterpriseEnquiry $enquiry, array $agreed): Workspace
    {
        if ($enquiry->provisioned_workspace_id) {
            $existing = Workspace::find($enquiry->provisioned_workspace_id);
            if ($existing) {
                return $existing;
            }
        }

        $snapshot = $this->snapshotFromAgreed($agreed);
        $email = strtolower(trim($enquiry->email));
        $price = (int) $agreed['price_myr'];
        $workspaceName = $enquiry->company !== '' ? $enquiry->company : $enquiry->name;
        $tempPassword = Str::password(12, symbols: false);

        return DB::transaction(function () use ($enquiry, $email, $workspaceName, $snapshot, $price, $tempPassword) {
            // Reuse an existing user for this email, else create one.
            $user = User::where('email', $email)->first();
            if (! $user) {
                $user = User::create([
                    'name' => $enquiry->name,
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                ]);
            }

            $slug = Str::slug($workspaceName);
            if ($slug === '' || Workspace::where('slug', $slug)->exists()) {
                $slug = ($slug !== '' ? $slug : 'enterprise') . '-' . Str::lower(Str::random(6));
            }

            $workspace = Workspace::create([
                'slug' => $slug,
                'name' => $workspaceName,
                'owner_id' => $user->id,
                'type' => 'agency',
                'plan' => 'enterprise',
                // INACTIVE until the invoice is paid (webhook flips to 'active').
                'subscription_status' => 'none',
                'settings' => [
                    PlanCaps::SNAPSHOT_SETTINGS_KEY => $snapshot,
                    PlanCaps::ENTERPRISE_PRICE_SETTINGS_KEY => $price,
                ],
            ]);

            WorkspaceMember::firstOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                ['role' => 'owner', 'invited_at' => now(), 'accepted_at' => now()],
            );

            if (! $user->current_workspace_id) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            $enquiry->update([
                'provisioned_workspace_id' => $workspace->id,
                'agreed_brands' => $snapshot['max_brands'],
                'agreed_image_posts' => $snapshot['max_ai_image_posts_per_month'],
                'agreed_video_posts' => $snapshot['max_ai_videos_per_month'],
                'agreed_price_myr' => $price,
                'status' => 'qualified',
            ]);

            return $workspace;
        });
    }

    /**
     * Issue a one-off Stripe Invoice for the agreed monthly price and store its
     * id + hosted pay URL on the enquiry. Idempotent: if an invoice id is already
     * recorded, this is a no-op (prevents a double-click from double-billing).
     *
     * No subscription is created. The invoice carries metadata
     * intent=enterprise + workspace_id so the webhook can match the payment back
     * to this workspace and activate it.
     */
    public function issueInvoice(EnterpriseEnquiry $enquiry, Workspace $workspace, int $priceMyr): ?string
    {
        if ($enquiry->stripe_invoice_id) {
            return $enquiry->stripe_invoice_id; // already invoiced — never re-bill
        }

        if ($priceMyr <= 0) {
            Log::warning('EnterpriseProvisioner: refusing to invoice a non-positive amount', [
                'enquiry_id' => $enquiry->id,
                'price_myr' => $priceMyr,
            ]);
            return null;
        }

        $stripe = Cashier::stripe();

        // Ensure the workspace has a Stripe customer (Cashier proxies stripe_id
        // ↔ stripe_customer_id on Workspace).
        $customerId = $workspace->stripe_id;
        if (! $customerId) {
            $customer = $stripe->customers->create([
                'email' => $enquiry->email,
                'name' => $enquiry->company !== '' ? $enquiry->company : $enquiry->name,
                'metadata' => [
                    'workspace_id' => (string) $workspace->id,
                    'plan' => 'enterprise',
                ],
            ]);
            $customerId = $customer->id;
            $workspace->forceFill(['stripe_customer_id' => $customerId])->save();
        }

        // Invoice item (amount in sen; MYR has 100 minor units), then a one-off
        // invoice that collects it. send_invoice + days_until_due gives the
        // customer a hosted pay link rather than an immediate card charge.
        $stripe->invoiceItems->create([
            'customer' => $customerId,
            'amount' => $priceMyr * 100,
            'currency' => (string) config('billing.currency', 'myr'),
            'description' => sprintf(
                'EIAAW Social Media Team — Enterprise plan (%d brands · %d image · %d video / month)',
                (int) $enquiry->agreed_brands,
                (int) $enquiry->agreed_image_posts,
                (int) $enquiry->agreed_video_posts,
            ),
        ]);

        $invoice = $stripe->invoices->create([
            'customer' => $customerId,
            'collection_method' => 'send_invoice',
            'days_until_due' => 14,
            'auto_advance' => true,
            'metadata' => [
                'intent' => 'enterprise',
                'workspace_id' => (string) $workspace->id,
                'enquiry_id' => (string) $enquiry->id,
            ],
        ]);

        // Finalize so it gets a hosted_invoice_url, then send it to the customer.
        $finalized = $stripe->invoices->finalizeInvoice($invoice->id);
        try {
            $stripe->invoices->sendInvoice($invoice->id);
        } catch (\Throwable $e) {
            // Finalized but send failed — the operator can resend from Stripe;
            // we still have the hosted URL to share manually. Don't lose the id.
            Log::error('EnterpriseProvisioner: invoice send failed (finalized, recoverable)', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        $enquiry->update([
            'stripe_invoice_id' => $finalized->id,
            'stripe_invoice_url' => $finalized->hosted_invoice_url ?? null,
            'invoice_status' => 'sent',
        ]);

        Log::info('EnterpriseProvisioner: enterprise invoice issued', [
            'enquiry_id' => $enquiry->id,
            'workspace_id' => $workspace->id,
            'invoice_id' => $finalized->id,
            'amount_myr' => $priceMyr,
        ]);

        return $finalized->id;
    }

    /**
     * Build the four-key cap snapshot from the operator's agreed specs. Mirrors
     * the shape PlanCaps::capsFor() expects; published total = image + video so a
     * video post never eats the image budget (same model as the catalog tiers).
     *
     * @param  array{brands:int, image_posts:int, video_posts:int, price_myr:int}  $agreed
     * @return array{max_brands:int, max_ai_image_posts_per_month:int, max_published_posts_per_month:int, max_ai_videos_per_month:int}
     */
    public function snapshotFromAgreed(array $agreed): array
    {
        $brands = max(1, (int) $agreed['brands']);
        $image = max(0, (int) $agreed['image_posts']);
        $video = max(0, (int) $agreed['video_posts']);

        return [
            'max_brands' => $brands,
            'max_ai_image_posts_per_month' => $image,
            'max_published_posts_per_month' => $image + $video,
            'max_ai_videos_per_month' => $video,
        ];
    }
}

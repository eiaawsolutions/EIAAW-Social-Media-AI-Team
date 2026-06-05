<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\Billing\EnterpriseProvisioner;
use App\Services\Billing\PlanCaps;
use Tests\TestCase;

/**
 * Enterprise is invoice-based: an operator agrees bespoke specs/caps/price, the
 * workspace is provisioned INACTIVE with a per-workspace cap snapshot, and a
 * one-off Stripe invoice activates it on payment.
 *
 * These tests lock the DB-free, Stripe-free contracts:
 *   - the agreed-specs → cap-snapshot mapping (published = image + video)
 *   - PlanCaps reads that snapshot for an enterprise workspace
 *   - enterprise with NO snapshot still resolves UNLIMITED (never Solo fallback)
 * The Stripe invoice call + workspace/DB writes are exercised against the live
 * stack in integration paths; the suite here stays DB-free (matches the
 * project's prod-pointed-.env constraint).
 */
class EnterpriseProvisionerTest extends TestCase
{
    public function test_snapshot_from_agreed_maps_specs_to_caps(): void
    {
        $snap = (new EnterpriseProvisioner())->snapshotFromAgreed([
            'brands' => 50,
            'image_posts' => 2000,
            'video_posts' => 200,
            'price_myr' => 15000,
        ]);

        $this->assertSame(50, $snap['max_brands']);
        $this->assertSame(2000, $snap['max_ai_image_posts_per_month']);
        $this->assertSame(200, $snap['max_ai_videos_per_month']);
        // Published total = image + video so a video never eats the image budget.
        $this->assertSame(2200, $snap['max_published_posts_per_month']);
    }

    public function test_snapshot_floors_brands_at_one_and_clamps_negatives(): void
    {
        $snap = (new EnterpriseProvisioner())->snapshotFromAgreed([
            'brands' => 0,
            'image_posts' => -5,
            'video_posts' => -1,
            'price_myr' => 9000,
        ]);

        $this->assertSame(1, $snap['max_brands']);          // floored
        $this->assertSame(0, $snap['max_ai_image_posts_per_month']);
        $this->assertSame(0, $snap['max_ai_videos_per_month']);
        $this->assertSame(0, $snap['max_published_posts_per_month']);
    }

    public function test_plancaps_reads_enterprise_snapshot_when_present(): void
    {
        // A provisioned enterprise workspace carries its bespoke caps in
        // settings.plan_caps_snapshot — PlanCaps must return those exact numbers.
        $snap = (new EnterpriseProvisioner())->snapshotFromAgreed([
            'brands' => 25, 'image_posts' => 1000, 'video_posts' => 120, 'price_myr' => 12000,
        ]);

        $ws = new Workspace();
        $ws->plan = 'enterprise';
        $ws->settings = [PlanCaps::SNAPSHOT_SETTINGS_KEY => $snap];

        $caps = (new PlanCaps())->capsFor($ws);

        $this->assertSame(25, $caps['max_brands']);
        $this->assertSame(1000, $caps['max_ai_image_posts_per_month']);
        $this->assertSame(120, $caps['max_ai_videos_per_month']);
        $this->assertSame(1120, $caps['max_published_posts_per_month']);
    }

    public function test_enterprise_without_snapshot_is_unlimited_never_solo(): void
    {
        // Defensive: if a snapshot is ever missing, an enterprise workspace must
        // resolve to UNLIMITED (config caps=null), NOT fall back to Solo limits.
        $ws = new Workspace();
        $ws->plan = 'enterprise';
        $ws->settings = null;

        $caps = (new PlanCaps())->capsFor($ws);

        $this->assertGreaterThan(1_000_000, $caps['max_brands']);
        $this->assertGreaterThan(1_000_000, $caps['max_ai_videos_per_month']);
    }

    public function test_enterprise_price_settings_key_is_stable(): void
    {
        // The provisioner writes the agreed price under this key for later MRR.
        // Lock the constant so a rename can't silently orphan stored prices.
        $this->assertSame('enterprise_agreed_price_myr', PlanCaps::ENTERPRISE_PRICE_SETTINGS_KEY);
    }
}

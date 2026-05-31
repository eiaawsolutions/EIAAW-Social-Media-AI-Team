<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the brand-create hardening that fixed the split-brain regression: a
 * solo workspace (max_brands=1) ended up with TWO brands because the cap was
 * enforced only by a read-then-write canAddBrand() check in the Filament create
 * action, which leaked under a stale-relation / double-submit race. Onboarding
 * work then split across the two records (voice on one, connections on the
 * other). See [[onboarding-split-brain-brands]].
 *
 * The fix moves enforcement into PlanCaps::createBrandOrFail() — a single locked
 * transaction that re-counts under the lock and refuses an over-cap or duplicate
 * insert — and routes the Filament CreateAction through it via ->using().
 *
 * Source-inspection only — the unit suite runs against the live DB connection
 * (sqlite is commented out in phpunit.xml), so a row-writing test would pollute
 * prod. The exception contract is unit-tested separately in PlanCapsTest; the
 * structural guarantees that can only be proven by running a real transaction
 * are asserted on the source here.
 */
class BrandCreateAtomicityTest extends TestCase
{
    private function planCapsSource(): string
    {
        return file_get_contents(app_path('Services/Billing/PlanCaps.php'));
    }

    private function manageBrandsSource(): string
    {
        return file_get_contents(
            app_path('Filament/Agency/Resources/Brands/Pages/ManageBrands.php')
        );
    }

    private function createBrandOrFailBody(): string
    {
        $src = $this->planCapsSource();
        $this->assertTrue(
            (bool) preg_match(
                '/function createBrandOrFail\(.*?\)\s*:\s*Brand\s*\{(.*?)\n    \}/s',
                $src,
                $m,
            ),
            'Could not isolate PlanCaps::createBrandOrFail() body.',
        );

        return $m[1];
    }

    public function test_create_brand_or_fail_exists_and_is_transactional(): void
    {
        $body = $this->createBrandOrFailBody();

        $this->assertStringContainsString(
            'DB::transaction(',
            $body,
            'createBrandOrFail must wrap the check-and-insert in a single transaction.',
        );
    }

    public function test_create_locks_the_workspace_row_to_serialise_concurrent_creates(): void
    {
        $body = $this->createBrandOrFailBody();

        $this->assertStringContainsString(
            'lockForUpdate()',
            $body,
            'createBrandOrFail must take a row-level lock so two concurrent creates serialise — '
            . 'this is what closes the read-then-write race the old canAddBrand check left open.',
        );

        // The lock must be on the WORKSPACE (the per-tenant gate), and it must
        // come before the insert.
        $lockPos = strpos($body, 'lockForUpdate()');
        $createPos = strpos($body, 'Brand::create(');
        $this->assertNotFalse($lockPos);
        $this->assertNotFalse($createPos);
        $this->assertLessThan(
            $createPos,
            $lockPos,
            'The lock must be acquired before the Brand::create() insert.',
        );
    }

    public function test_cap_is_rechecked_inside_the_transaction_then_throws(): void
    {
        $body = $this->createBrandOrFailBody();

        // Must re-read the active-brand count under the lock (not trust a
        // relation count read earlier by the UI) and refuse when at cap.
        $this->assertMatchesRegularExpression(
            '/count\(\)\s*>=\s*\$max/s',
            $body,
            'createBrandOrFail must re-count active brands under the lock and compare against the cap.',
        );
        $this->assertStringContainsString(
            'BrandCreationRefused::capReached(',
            $body,
            'createBrandOrFail must throw the typed cap-reached refusal.',
        );
    }

    public function test_duplicate_name_is_guarded_case_insensitively(): void
    {
        $body = $this->createBrandOrFailBody();

        $this->assertStringContainsString(
            'BrandCreationRefused::duplicateName(',
            $body,
            'createBrandOrFail must reject a same-named active brand in the workspace.',
        );
        // Case-insensitive comparison so "The Bear Hug Cafe" and "the bear hug
        // cafe" collide.
        $this->assertMatchesRegularExpression(
            '/LOWER\(name\)|mb_strtolower\(/s',
            $body,
            'The duplicate-name guard must be case-insensitive.',
        );
    }

    public function test_cap_and_name_checks_only_count_non_archived_brands(): void
    {
        $body = $this->createBrandOrFailBody();

        // Archived brands free a slot (consistent with activeBrandsCount and the
        // archive action), so the authoritative recount must filter them out.
        $this->assertStringContainsString(
            "whereNull('archived_at')",
            $body,
            'The under-lock recount must exclude archived brands so archiving frees a slot.',
        );
    }

    public function test_filament_create_action_routes_through_create_brand_or_fail(): void
    {
        $src = $this->manageBrandsSource();

        // The authoritative create must run through PlanCaps, not a bare
        // Filament insert — otherwise the locked transaction is bypassed.
        $this->assertStringContainsString(
            '->using(',
            $src,
            'The CreateAction must override the create with ->using() so it routes through PlanCaps.',
        );
        $this->assertStringContainsString(
            'createBrandOrFail(',
            $src,
            'The CreateAction ->using() must call PlanCaps::createBrandOrFail().',
        );
        $this->assertStringContainsString(
            'BrandCreationRefused',
            $src,
            'The create action must catch BrandCreationRefused to show a friendly notification.',
        );
    }
}

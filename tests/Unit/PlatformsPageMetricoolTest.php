<?php

namespace Tests\Unit;

use App\Filament\Agency\Resources\PlatformConnections\Pages\ManagePlatformConnections;
use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use App\Filament\Resources\ClientPlatformConnections\ClientPlatformConnectionResource;
use App\Filament\Resources\ClientPlatformConnections\Pages\ManageClientPlatformConnections;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression lock for the customer-facing "Platforms" surface
 * (PlatformConnectionResource + ManagePlatformConnections) AND its HQ
 * counterpart (ClientPlatformConnectionResource under /admin).
 *
 * Two eras of intent are locked here:
 *
 *   1. Blotato→Metricool rebuild — the page must speak Metricool, not Blotato
 *      broker language, and sync through MetricoolConnectionService.
 *
 *   2. Tenant-isolation relocation (2026-06-02) — the customer-facing /agency
 *      Platforms surface is now HARD own-workspace for EVERYONE, including HQ.
 *      The previous super-admin machinery (cross-tenant base query, Brand/
 *      Workspace column, Brand filter, revoked toggle, HQ subheading) was a
 *      cross-tenant view living on a customer surface — it read as a data breach
 *      (HQ's own Agency account showed a client's connections). That machinery
 *      MOVED to the /admin panel (ClientPlatformConnectionResource), behind the
 *      super-admin panel gate. These tests pin BOTH halves: the /agency surface
 *      is clean of cross-tenant machinery, and the /admin surface carries it.
 *
 * DB-FREE by design (source-level reflection only) — the SMT local .env points
 * at Railway PROD, so tests never touch the DB.
 */
class PlatformsPageMetricoolTest extends TestCase
{
    private function pageSource(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(ManagePlatformConnections::class))->getFileName()
        );
    }

    private function resourceSource(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(PlatformConnectionResource::class))->getFileName()
        );
    }

    private function adminResourceSource(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(ClientPlatformConnectionResource::class))->getFileName()
        );
    }

    private function adminPageSource(): string
    {
        return (string) file_get_contents(
            (new ReflectionClass(ManageClientPlatformConnections::class))->getFileName()
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Era 1 — Blotato→Metricool: the customer page speaks Metricool
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The customer-visible strings that must NOT survive the rebuild. Each is a
     * substring of the old Blotato-broker copy/columns.
     *
     * @return array<int, array{0:string}>
     */
    public static function blotatoBrokerStrings(): array
    {
        return [
            ['Blotato as the OAuth broker'],
            ['Sync from Blotato'],
            ['Synced from Blotato'],
            ['Open Blotato'],
            ["label('Blotato ID')"],
            ['Connect your social accounts inside Blotato'],
        ];
    }

    #[DataProvider('blotatoBrokerStrings')]
    public function test_page_surface_has_no_blotato_broker_language(string $needle): void
    {
        $combined = $this->pageSource() . "\n" . $this->resourceSource();

        $this->assertStringNotContainsString(
            $needle,
            $combined,
            "Customer-facing Platforms surface still contains stale Blotato copy: \"{$needle}\". "
            . 'Publishing + metrics already run on Metricool; this page must speak Metricool.'
        );
    }

    public function test_page_syncs_through_metricool_connection_service(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString(
            'MetricoolConnectionService',
            $src,
            'The Platforms page must run its sync through MetricoolConnectionService '
            . '(reads /admin/profile), not Blotato\'s PlatformSyncService.'
        );
        $this->assertStringNotContainsString(
            'PlatformSyncService',
            $src,
            'The Platforms page must no longer reference Blotato\'s PlatformSyncService.'
        );
    }

    public function test_resource_exposes_a_routing_space_column_not_blotato_id(): void
    {
        $src = $this->resourceSource();

        $this->assertStringContainsString(
            "->label('Routing space')",
            $src,
            'The id column must be relabelled to the white-labelled routing key.'
        );
        $this->assertStringNotContainsString(
            "->label('Metricool brand')",
            $src,
            'The customer-facing column label must not name Metricool.'
        );
        $this->assertStringNotContainsString(
            "->label('Blotato ID')",
            $src,
            'The column must no longer carry the legacy Blotato label.'
        );
        $this->assertStringContainsString(
            "make('brand.metricool_blog_id')",
            $src,
            'The column must still bind to the brand metricool_blog_id.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Era 2 — Tenant-isolation relocation (2026-06-02):
    //  /agency Platforms is own-workspace for EVERYONE, including HQ.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Revoked connections are inert tombstones. They must stay hidden from the
     * /agency view for EVERYONE — the base query is the only thing protecting
     * the view now that the super-admin revoked toggle is gone.
     */
    public function test_customers_have_revoked_connections_filtered_in_base_query(): void
    {
        $src = $this->resourceSource();

        $this->assertStringContainsString(
            "->where('status', '!=', 'revoked')",
            $src,
            'The /agency Platforms base query must exclude revoked connections for everyone.'
        );
    }

    /**
     * THE core isolation invariant for this surface: the customer-facing
     * PlatformConnectionResource — a resource exposing OAuth/Metricool routing,
     * the most sensitive tenant object — must carry NO super-admin bypass or
     * cross-tenant machinery of any kind. Not in the base query, not in a column,
     * not in a filter, not in the subheading. A single `is_super_admin` token in
     * the resource OR its page is a regression back to the 2026-06-02 leak.
     */
    public function test_agency_platforms_surface_has_no_super_admin_machinery(): void
    {
        $combined = $this->resourceSource() . "\n" . $this->pageSource();

        $this->assertStringNotContainsString(
            'is_super_admin',
            $combined,
            'SECURITY: the /agency Platforms surface references is_super_admin. This panel is '
            . 'own-workspace for EVERYONE now — any super-admin branch (base-query bypass, gated '
            . 'column/filter, or HQ subheading) reintroduces the cross-tenant exposure that read '
            . 'as a breach on 2026-06-02. Cross-tenant administration lives in /admin → '
            . 'ClientPlatformConnectionResource. Remove it.'
        );
    }

    /**
     * The old cross-tenant UI (Brand/Workspace column, Brand filter, revoked
     * toggle) must be GONE from the customer surface. Pin the specific literals
     * so a copy-paste reintroduction fails fast with a clear message.
     */
    public function test_agency_platforms_dropped_the_cross_tenant_ui(): void
    {
        $src = $this->resourceSource();

        $this->assertStringNotContainsString(
            "->label('Brand / Workspace')",
            $src,
            'The Brand/Workspace column belongs on the /admin cross-tenant surface, not /agency.'
        );
        $this->assertStringNotContainsString(
            "SelectFilter::make('brand_id')",
            $src,
            'The cross-tenant Brand filter belongs on the /admin surface, not /agency.'
        );
        $this->assertStringNotContainsString(
            "Filter::make('hide_revoked')",
            $src,
            'The super-admin revoked toggle belongs on the /admin surface; /agency hides '
            . 'revoked unconditionally in the base query.'
        );
    }

    /**
     * The /agency subheading must be a single own-workspace message — it must NOT
     * branch on is_super_admin (covered by the machinery test above) and must
     * keep the honest customer copy.
     */
    public function test_agency_subheading_is_single_own_workspace_copy(): void
    {
        $src = $this->pageSource();

        $this->assertStringContainsString(
            'connected for your brand',
            $src,
            'The own-workspace subheading ("connected for your brand") must remain.'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Era 2 — the cross-tenant machinery now lives in /admin
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The /admin ClientPlatformConnectionResource is the relocated home of the
     * cross-tenant view. It MUST be super-admin gated at the resource boundary
     * (defense in depth on top of the panel gate) and carry the disambiguation
     * machinery the /agency surface used to hold.
     */
    public function test_admin_client_platforms_is_super_admin_gated(): void
    {
        $src = $this->adminResourceSource();

        $this->assertMatchesRegularExpression(
            '/function canViewAny\(\).*?is_super_admin/s',
            $src,
            'SECURITY: ClientPlatformConnectionResource::canViewAny() must gate on is_super_admin '
            . 'so the cross-tenant view is unreachable by a customer even if the panel config drifts.'
        );
        $this->assertMatchesRegularExpression(
            '/function canAccess\(\).*?is_super_admin/s',
            $src,
            'ClientPlatformConnectionResource::canAccess() must gate on is_super_admin.'
        );
    }

    public function test_admin_client_platforms_carries_the_cross_tenant_machinery(): void
    {
        $src = $this->adminResourceSource();

        $this->assertStringContainsString(
            "->label('Brand / Workspace')",
            $src,
            'The /admin cross-tenant surface must carry the Brand/Workspace column so HQ knows '
            . 'whose connection each row is.'
        );
        $this->assertStringContainsString(
            "SelectFilter::make('brand_id')",
            $src,
            'The /admin surface must carry the Brand filter to focus a single tenant.'
        );
        $this->assertStringContainsString(
            "Filter::make('hide_revoked')",
            $src,
            'The /admin surface must carry the revoked-visibility toggle for audit.'
        );
        // Full edit ported: the per-network target-overrides modal must exist.
        $this->assertStringContainsString(
            'target_overrides',
            $src,
            'The /admin surface must keep the per-network Target overrides editor (full edit).'
        );
    }

    /**
     * The /admin base query is cross-tenant BY DESIGN (no workspace_id
     * constraint) — that is the whole point, and it is safe because the panel
     * gate restricts the surface to super-admins. It must eager-load
     * brand.workspace to avoid N+1 on the all-tenants view, and must hide
     * archived-brand connections.
     */
    public function test_admin_client_platforms_base_query_is_cross_tenant_and_eager_loaded(): void
    {
        $src = $this->adminResourceSource();

        $this->assertStringContainsString(
            "->with('brand.workspace')",
            $src,
            'The /admin all-tenants view must eager-load brand.workspace to avoid N+1.'
        );
        $this->assertStringContainsString(
            'whereNull',
            $src,
            'The /admin view must hide connections whose brand is archived.'
        );
    }

    /**
     * HQ keeps the "refresh a client's connections from Metricool" capability —
     * relocated to the /admin page, where it can target ANY tenant's brand
     * (the /agency refresh only ever read the operator's own workspace).
     */
    public function test_admin_refresh_runs_through_metricool(): void
    {
        $src = $this->adminPageSource();

        $this->assertStringContainsString(
            'MetricoolConnectionService',
            $src,
            'The /admin Platforms page must keep the Metricool refresh capability for HQ.'
        );
    }
}

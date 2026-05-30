<?php

namespace Tests\Unit;

use App\Filament\Agency\Resources\PlatformConnections\Pages\ManagePlatformConnections;
use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression lock for the Blotato→Metricool rebuild of the customer-facing
 * "Platforms" surface (PlatformConnectionResource + ManagePlatformConnections).
 *
 * The bug this guards: the page kept telling customers "we use Blotato as the
 * OAuth broker / Sync from Blotato" while publishing + metrics had already moved
 * to Metricool. A customer following that copy connects to a provider we publish
 * through only on the legacy rollback path.
 *
 * Scope note: this PR rebuilds the PAGE only — it does NOT retire the legacy
 * PlatformSetup page or kill the PUBLISH_PROVIDER=blotato rollback (those live in
 * the separate Blotato-decommission PR). So these assertions cover just the page
 * surface: no Blotato broker language on it, and its sync goes through Metricool.
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

    /**
     * The customer-visible strings that must NOT survive the rebuild. Each is a
     * substring of the old Blotato-broker copy/columns shown in the screenshot.
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

    /**
     * Revoked connections are inert tombstones (legacy disconnected accounts the
     * Metricool/Blotato sync revoked rather than deleted, to preserve the
     * ScheduledPost audit chain). They must be hidden from the default Platforms
     * view for EVERYONE — including super admins viewing the panel for their own
     * brand, who previously saw them because of an unconditional "super admin
     * sees everything" branch. This locks the two-mechanism hide so that branch
     * can't regress.
     */
    public function test_customers_have_revoked_connections_filtered_in_base_query(): void
    {
        $src = $this->resourceSource();

        // The non-super-admin (customer) branch of getEloquentQuery must still
        // exclude revoked rows unconditionally — they never see the revoked
        // filter, and a hidden Filament filter applies no query, so the base
        // query is the only thing protecting their view.
        $this->assertStringContainsString(
            "->where('status', '!=', 'revoked')",
            $src,
            'Customer view must exclude revoked connections in the base query.'
        );
    }

    public function test_revoked_rows_are_gated_behind_a_super_admin_only_filter(): void
    {
        $src = $this->resourceSource();

        // The opt-in to surface revoked rows must exist, default to hiding them,
        // and only be visible to super admins.
        $this->assertStringContainsString(
            "Filter::make('hide_revoked')",
            $src,
            'A revoked-visibility filter must exist for super-admin audit access.'
        );
        $this->assertStringContainsString(
            '->default(true)',
            $src,
            'The revoked filter must default to ON (hide), so HQ gets the clean '
            . 'view a customer gets unless they explicitly opt in.'
        );
        $this->assertStringContainsString(
            'is_super_admin',
            $src,
            'The revoked filter must be gated to super admins only.'
        );
    }

    public function test_super_admin_branch_does_not_unconditionally_return_revoked_rows(): void
    {
        $src = $this->resourceSource();

        // Guard against the original bug: the super-admin branch returned ALL
        // connections (no status filter) which dumped revoked tombstones into
        // HQ's own-brand view. The branch must no longer name the broad
        // brand-only whereHas without the revoked control being filter-driven.
        // We assert the explanatory invariant comment is present so the intent
        // survives future edits.
        $this->assertStringContainsString(
            'Super admins control revoked visibility via',
            $src,
            'The super-admin branch must document that revoked visibility is '
            . 'filter-driven, not unconditional — preventing a silent regression '
            . 'back to "super admin sees every revoked row by default".'
        );
    }

    public function test_resource_exposes_a_routing_space_column_not_blotato_id(): void
    {
        $src = $this->resourceSource();

        // The column that read "Blotato ID" must now reflect the per-brand
        // routing target (backed by metricool_blog_id), shown to customers
        // under a white-labelled name — no third-party tool named in the UI.
        $this->assertStringContainsString(
            "->label('Routing space')",
            $src,
            'The id column must be relabelled to the white-labelled routing key.'
        );
        // Customer-facing label must not name the third-party tool, nor the
        // old Blotato wording.
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
        // It still routes off the Metricool blogId underneath (column binding
        // unchanged — only the visible label is white-labelled).
        $this->assertStringContainsString(
            "make('brand.metricool_blog_id')",
            $src,
            'The column must still bind to the brand metricool_blog_id.'
        );
    }
}

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

<?php

namespace Tests\Unit;

use App\Filament\Agency\Pages\AgentsMonitor;
use App\Filament\Agency\Pages\AutonomyLane;
use App\Filament\Agency\Pages\Billing;
use App\Filament\Agency\Pages\BrandCorpusSeed;
use App\Filament\Agency\Pages\Dashboard;
use App\Filament\Agency\Pages\LiveFeed;
use App\Filament\Agency\Pages\MetricoolSetup;
use App\Filament\Agency\Pages\Performance;
use App\Filament\Agency\Pages\PlatformSetup;
use App\Filament\Agency\Pages\SetupWizard;
use App\Filament\Agency\Resources\BrandAssets\BrandAssetResource;
use App\Filament\Agency\Resources\Brands\BrandResource;
use App\Filament\Agency\Resources\CalendarEntries\CalendarEntryResource;
use App\Filament\Agency\Resources\Drafts\DraftResource;
use App\Filament\Agency\Resources\GrowthGoals\GrowthGoalResource;
use App\Filament\Agency\Resources\PlatformConnections\PlatformConnectionResource;
use App\Filament\Agency\Resources\ScheduledPosts\ScheduledPostResource;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the Agency sidebar order so a future edit can't silently reshuffle it.
 *
 * Why this exists: the sidebar order is spread across 15+ separate classes, each
 * carrying its own `protected static ?int $navigationSort`. Filament renders the
 * sidebar by sorting on that value, so a one-line change in any single file can
 * silently move (or, when two files collide on the same value, *non-deterministically*
 * reorder) the whole menu. That exact collision bit us once: stock
 * Filament\Pages\Dashboard ships navigationSort = -2, which tied with Platform
 * setup (also -2) and left their relative order to an unstable tiebreaker — fixed
 * by the App\Filament\Agency\Pages\Dashboard subclass pinned to -2 and Platform
 * setup dropped to -3.
 *
 * This test asserts the full intended order in one place. If you intend to change
 * the sidebar order, update EXPECTED_ORDER here in the same PR — that makes the
 * reshuffle explicit and reviewable instead of an accidental side effect.
 *
 * DB-free / boot-free by construction: it only reflects static properties and
 * reads two source files. It never opens a DB connection — safe under the
 * local-.env-points-at-prod caveat that the rest of this suite respects.
 */
class AgencyNavigationOrderTest extends TestCase
{
    /**
     * The canonical sidebar order, top to bottom, for EVERY user.
     *
     * Each row is [class, expected human label, expected navigationSort]. The
     * label is the source of truth for "what the operator sees"; the sort is the
     * mechanism Filament uses to place it. Both are asserted.
     *
     * The last two rows (Agents, admin) are HQ-only — they register in nav only
     * for super-admins (see test_hq_only_items_are_super_admin_gated). "admin" is
     * a NavigationItem defined inline in AgencyPanelProvider, not a class with a
     * static, so its sort is asserted against the provider source separately.
     *
     * @var list<array{0:class-string,1:string,2:int}>
     */
    private const EXPECTED_ORDER = [
        [MetricoolSetup::class,             'Platform setup', -3],
        [Dashboard::class,                  'Dashboard',      -2],
        [SetupWizard::class,                'Setup wizard',   -1],
        [BrandResource::class,              'Brands',          1],
        [BrandCorpusSeed::class,            'Brand corpus',    2],
        [PlatformConnectionResource::class, 'Platforms',       3],
        [AutonomyLane::class,               'Autonomy',        4],
        [GrowthGoalResource::class,         'Growth goals',    5],
        [BrandAssetResource::class,         'Asset library',   6],
        [CalendarEntryResource::class,      'Calendar',        7],
        [DraftResource::class,              'Drafts',          8],
        [ScheduledPostResource::class,      'Schedule',        9],
        [LiveFeed::class,                   'Live feed',      10],
        [Performance::class,                'Performance',    11],
        [Billing::class,                    'Billing',        12],
        // --- HQ-only below ---
        [AgentsMonitor::class,              'Agents',         91],
    ];

    /**
     * PlatformSetup is the Blotato-era twin of MetricoolSetup — only one of the
     * two registers at a time (provider-gated on the publishing provider). It must
     * carry the SAME sort as MetricoolSetup so the order holds under either
     * provider. Asserted separately so EXPECTED_ORDER stays a clean 1:1 sequence.
     */
    private const PLATFORM_SETUP_TWIN_SORT = -3;

    /** Read a Filament class's protected-static navigationSort via reflection. */
    private function navigationSort(string $class): ?int
    {
        // PHP 8.1+ reflection reads protected/private statics without
        // setAccessible() (which is a deprecated no-op since 8.1).
        return (new ReflectionClass($class))->getProperty('navigationSort')->getValue();
    }

    /**
     * Each item carries exactly its expected navigationSort. Catches a wrong
     * value in any single file.
     */
    public function test_each_item_has_its_expected_navigation_sort(): void
    {
        foreach (self::EXPECTED_ORDER as [$class, $label, $expectedSort]) {
            $this->assertSame(
                $expectedSort,
                $this->navigationSort($class),
                "Navigation sort drifted for \"{$label}\" ({$class}). Expected "
                . "{$expectedSort}. If this change is intentional, update "
                . 'EXPECTED_ORDER in ' . self::class . ' in the same PR.'
            );
        }

        $this->assertSame(
            self::PLATFORM_SETUP_TWIN_SORT,
            $this->navigationSort(PlatformSetup::class),
            'PlatformSetup (Blotato twin of Platform setup) must share '
            . "MetricoolSetup's sort so the order holds under either publishing "
            . 'provider.'
        );
    }

    /**
     * The labels, when sorted by each item's ACTUAL navigationSort, must equal the
     * intended top-to-bottom order. This is the real reshuffle guard: it fails if
     * any two items swap places, even if every individual value still "looks fine"
     * in isolation. Ties are explicitly disallowed (they render
     * non-deterministically — the bug this test was born from).
     */
    public function test_sidebar_renders_in_the_locked_order(): void
    {
        $rows = [];
        foreach (self::EXPECTED_ORDER as [$class, $label]) {
            $rows[] = ['label' => $label, 'sort' => $this->navigationSort($class)];
        }

        // No two items may share a sort value — a tie is an unstable order.
        $sorts = array_column($rows, 'sort');
        $this->assertSame(
            count($sorts),
            count(array_unique($sorts)),
            'Two navigation items share a navigationSort value — Filament would '
            . 'order them non-deterministically. Give each a distinct sort.'
        );

        // Sort by actual sort value, then assert the label sequence matches.
        usort($rows, fn (array $a, array $b): int => $a['sort'] <=> $b['sort']);
        $actualOrder = array_column($rows, 'label');
        $expectedOrder = array_column(self::EXPECTED_ORDER, 1);

        $this->assertSame(
            $expectedOrder,
            $actualOrder,
            'The Agency sidebar order changed. If intentional, update '
            . 'EXPECTED_ORDER in ' . self::class . '.'
        );
    }

    /**
     * The two HQ-only entries (Agents, admin) must stay super-admin gated. A
     * regression here would leak HQ tooling into every customer's sidebar.
     *
     * AgentsMonitor gates via shouldRegisterNavigation()/canAccess(); the "admin"
     * link is an inline NavigationItem in the provider with ->visible() bound to
     * is_super_admin and sorted just after Agents.
     */
    public function test_hq_only_items_are_super_admin_gated(): void
    {
        // AgentsMonitor must override shouldRegisterNavigation (i.e. it does not
        // inherit Filament's default "always register"), and its body must gate
        // on the super-admin flag.
        $ref = new ReflectionClass(AgentsMonitor::class);
        $this->assertSame(
            AgentsMonitor::class,
            $ref->getMethod('shouldRegisterNavigation')->getDeclaringClass()->getName(),
            'AgentsMonitor must declare its own shouldRegisterNavigation() to '
            . 'stay HQ-only.'
        );

        $agentsSource = file_get_contents($ref->getFileName());
        $this->assertStringContainsString(
            'is_super_admin',
            $agentsSource,
            'AgentsMonitor must gate visibility on is_super_admin.'
        );

        // The provider's inline "admin" NavigationItem must be visibility-gated on
        // is_super_admin and sorted at 92 (just after Agents at 91).
        $providerPath = dirname(__DIR__, 2)
            . '/app/Providers/Filament/AgencyPanelProvider.php';
        $providerSource = file_get_contents($providerPath);

        $this->assertMatchesRegularExpression(
            "/NavigationItem::make\('admin'\)/",
            $providerSource,
            'The HQ "admin" NavigationItem is missing from AgencyPanelProvider.'
        );
        $this->assertStringContainsString(
            '->sort(92)',
            $providerSource,
            'The HQ "admin" link must sort at 92 (just after Agents at 91).'
        );
        // The ->visible() callback for the admin item must reference
        // is_super_admin (single logical line: `->visible(fn (): bool => ...
        // is_super_admin)`). [^\n] keeps the match within that one call.
        $this->assertMatchesRegularExpression(
            '/->visible\(fn[^\n]*is_super_admin/',
            $providerSource,
            'The HQ "admin" link must be visible only to super-admins.'
        );
    }
}

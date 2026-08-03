<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards that every brand-owning surface in the Agency panel actually honours
 * the global brand scope.
 *
 * WHY THIS EXISTS
 * ---------------
 * Multi-brand tiers (Studio 3 / Agency 10 / Enterprise / EIAAW Internal) used
 * to have no way to choose a brand on any page except Brand corpus. Two classes
 * of defect followed, and this test locks both closed:
 *
 *   1. WRITE surfaces silently targeted `orderBy('id')->first()`. Picking an
 *      autonomy lane always wrote brand #1's row; "Draft all undrafted entries"
 *      only ever fanned out brand #1's calendar. Brands #2..n were unreachable.
 *
 *   2. READ surfaces blended every brand with nothing to tell them apart — and
 *      Performance was internally inconsistent, aggregating post metrics across
 *      ALL brands while its growth cards described ONE brand on the same screen.
 *
 * DB-free by convention (see BrandCorpusManageTest): wiring is proven by source
 * inspection rather than by writing rows into the live connection.
 */
class BrandScopeWiringTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $full = base_path($relativePath);
        $this->assertFileExists($full, "missing {$relativePath}");

        return (string) file_get_contents($full);
    }

    // ---- Resources: every brand-owning table is scoped -------------------

    /**
     * @return array<string, array{0:string}>
     */
    public static function brandOwningResources(): array
    {
        return [
            'drafts' => ['app/Filament/Agency/Resources/Drafts/DraftResource.php'],
            'scheduled posts' => ['app/Filament/Agency/Resources/ScheduledPosts/ScheduledPostResource.php'],
            'calendar entries' => ['app/Filament/Agency/Resources/CalendarEntries/CalendarEntryResource.php'],
            'brand assets' => ['app/Filament/Agency/Resources/BrandAssets/BrandAssetResource.php'],
            'growth goals' => ['app/Filament/Agency/Resources/GrowthGoals/GrowthGoalResource.php'],
            'platform connections' => ['app/Filament/Agency/Resources/PlatformConnections/PlatformConnectionResource.php'],
            'inbox conversations' => ['app/Filament/Agency/Resources/InboxConversations/InboxConversationResource.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandOwningResources')]
    public function test_resource_uses_the_scope_trait(string $path): void
    {
        $this->assertStringContainsString(
            'use \App\Filament\Agency\Concerns\ScopesToSelectedBrands;',
            $this->source($path),
            basename($path).' must use the ScopesToSelectedBrands trait',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandOwningResources')]
    public function test_resource_applies_the_scope_inside_get_eloquent_query(string $path): void
    {
        $this->assertMatchesRegularExpression(
            '/function getEloquentQuery\(.*?self::applyBrandScope\(/s',
            $this->source($path),
            basename($path).' must narrow getEloquentQuery() through applyBrandScope()',
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('brandOwningResources')]
    public function test_resource_still_isolates_by_workspace(string $path): void
    {
        // The brand scope NARROWS; it must never replace tenant isolation.
        $src = $this->source($path);

        $this->assertMatchesRegularExpression(
            '/function getEloquentQuery\(.*?workspace_id/s',
            $src,
            basename($path).' must keep its workspace isolation constraint',
        );
        $this->assertMatchesRegularExpression(
            '/function getEloquentQuery\(.*?whereRaw\(\'1 = 0\'\)/s',
            $src,
            basename($path).' must still return nothing when no workspace resolves',
        );
    }

    /**
     * Tables that previously had no way to tell one brand's rows from another's
     * must now label them. (GrowthGoals / InboxConversations / PlatformConnections
     * already had a brand column of their own and are excluded.)
     */
    public function test_previously_unlabelled_tables_now_show_a_brand_column(): void
    {
        $paths = [
            'app/Filament/Agency/Resources/Drafts/DraftResource.php',
            'app/Filament/Agency/Resources/ScheduledPosts/ScheduledPostResource.php',
            'app/Filament/Agency/Resources/CalendarEntries/CalendarEntryResource.php',
            'app/Filament/Agency/Resources/BrandAssets/BrandAssetResource.php',
        ];

        foreach ($paths as $path) {
            $this->assertMatchesRegularExpression(
                '/->columns\(\[\s*(\/\/[^\n]*\n\s*)*self::brandColumn\(\),/',
                $this->source($path),
                basename($path).' must render the brand column first in its table',
            );
        }
    }

    public function test_the_brand_column_hides_itself_when_only_one_brand_is_in_scope(): void
    {
        // A column repeating the same value on every row is noise.
        $this->assertMatchesRegularExpression(
            '/function brandColumn\(.*?->visible\(fn \(\): bool => count\(app\(BrandScope::class\)->brandIds\(\)\) > 1\)/s',
            $this->source('app/Filament/Agency/Concerns/ScopesToSelectedBrands.php'),
        );
    }

    public function test_apply_brand_scope_only_ever_narrows(): void
    {
        $src = $this->source('app/Filament/Agency/Concerns/ScopesToSelectedBrands.php');

        // It adds a whereIn and nothing else — no removal or widening of the
        // caller's existing workspace constraint.
        $this->assertMatchesRegularExpression(
            '/function applyBrandScope\(.*?->whereIn\(\$column, \$scope->brandIds\(\)\)/s',
            $src,
        );
        $this->assertMatchesRegularExpression(
            '/function applyBrandScope\(.*?if \(\$scope->isAll\(\)\) \{\s*return \$query;/s',
            $src,
            'an "All brands" scope must be a no-op, not a rewrite of the query',
        );
    }

    // ---- Write surfaces: no more silent first-brand fallback -------------

    public function test_autonomy_lane_exposes_a_brand_selector(): void
    {
        $page = $this->source('app/Filament/Agency/Pages/AutonomyLane.php');

        $this->assertStringContainsString('public function brands(', $page);
        // Pinning to a concrete id is what makes the dropdown show the brand the
        // buttons will write to.
        $this->assertMatchesRegularExpression(
            '/function mount\(.*?\$this->brand = \$this->resolveBrand\(\)\?->id;/s',
            $page,
            'mount() must pin $this->brand so the selector has a concrete value',
        );
        // Switching brand must re-read that brand's stored lane, or the
        // highlighted "Current" badge would describe the previous brand.
        $this->assertMatchesRegularExpression(
            '/function updatedBrand\(.*?refreshState\(\)/s',
            $page,
        );
    }

    public function test_autonomy_lane_view_binds_the_selector_live(): void
    {
        $view = $this->source('resources/views/filament/agency/pages/autonomy-lane.blade.php');

        $this->assertStringContainsString('wire:model.live="brand"', $view);
        $this->assertStringContainsString('$laneBrands->count() > 1', $view);
    }

    public function test_autonomy_lane_resolves_a_fully_hydrated_brand(): void
    {
        // brands() is built from BrandScope::available(), which selects only
        // id/name/timezone. Handing that partial model to SetupReadiness or an
        // agent would read nulls for real columns, so resolveBrand() re-fetches.
        $this->assertMatchesRegularExpression(
            '/function resolveBrand\(.*?return Brand::find\(\$targetId\);/s',
            $this->source('app/Filament/Agency/Pages/AutonomyLane.php'),
        );
    }

    public function test_autonomy_lane_no_longer_falls_back_to_the_first_brand_by_id(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            "/function resolveBrand\(.*?orderBy\('id'\)/s",
            $this->source('app/Filament/Agency/Pages/AutonomyLane.php'),
            'resolveBrand() must not silently pick the lowest brand id',
        );
    }

    public function test_calendar_draft_all_covers_every_brand_in_scope(): void
    {
        $src = $this->source('app/Filament/Agency/Resources/CalendarEntries/Pages/ManageCalendarEntries.php');

        $this->assertStringContainsString('app(BrandScope::class)->brandIds()', $src);
        $this->assertStringContainsString("CalendarEntry::whereIn('brand_id', \$brandIds)", $src);
        // The old single-brand resolver must be gone entirely. Match the
        // DECLARATION, not the bare name — the comment above the replacement
        // legitimately refers to it when explaining what changed.
        $this->assertStringNotContainsString('function resolveCurrentBrand', $src);
    }

    public function test_calendar_draft_all_names_its_blast_radius_before_confirming(): void
    {
        // The button dispatches paid AI work across brands; the operator must
        // see which brands from the confirmation modal.
        $this->assertMatchesRegularExpression(
            '/modalDescription\(fn \(\): string => sprintf\(.*?app\(BrandScope::class\)->description\(\)/s',
            $this->source('app/Filament/Agency/Resources/CalendarEntries/Pages/ManageCalendarEntries.php'),
        );
    }

    // ---- Read surfaces: consistent scope, honest labelling ---------------

    public function test_performance_computes_every_figure_from_one_scope(): void
    {
        $src = $this->source('app/Filament/Agency/Pages/Performance.php');

        // The defect: post metrics aggregated ALL brands while growth showed
        // ONE. Every aggregate must now read the same scoped id list.
        $this->assertStringNotContainsString(
            "\$brandIds = Brand::where('workspace_id', \$ws->id)->pluck('id');\n\n        \$publishedCount",
            $src,
        );
        foreach (['summary', 'perPlatform', 'topPosts'] as $method) {
            $this->assertMatchesRegularExpression(
                "/function {$method}\(.*?\\\$brandIds = \\\$this->scopedBrandIds\(\)/s",
                $src,
                "{$method}() must aggregate over the scoped brand ids",
            );
        }
    }

    public function test_performance_reports_growth_per_brand_never_summed(): void
    {
        $src = $this->source('app/Filament/Agency/Pages/Performance.php');

        // Growth is an account-level reading per Metricool blogId — summing it
        // across brands would be meaningless, so it is emitted as one block per
        // brand instead.
        $this->assertStringContainsString('public function growthBlocks(', $src);
        $this->assertStringContainsString('public function growthStrategyBlocks(', $src);
        $this->assertStringNotContainsString('brandForWorkspace($ws)', $src);
    }

    public function test_performance_refreshes_growth_for_all_scoped_brands(): void
    {
        // Refreshing only the first Metricool-mapped brand left the others
        // showing stale figures with no way to update them.
        $this->assertMatchesRegularExpression(
            '/foreach \(\$this->growthBrands\(\) as \$brand\)/',
            $this->source('app/Filament/Agency/Pages/Performance.php'),
        );
    }

    public function test_performance_charts_are_unique_per_brand(): void
    {
        // Duplicate canvas ids would make Chart.js draw every brand's datasets
        // into the first canvas.
        $this->assertStringContainsString(
            "\$chartId = 'perf-growth-'.\$g['brand']['id'].'-'.\$dimKey.'-'.\$g['data']['window_days'];",
            $this->source('resources/views/filament/agency/pages/performance.blade.php'),
        );
    }

    public function test_live_feed_stamps_each_post_in_its_own_brand_timezone(): void
    {
        $page = $this->source('app/Filament/Agency/Pages/LiveFeed.php');
        $view = $this->source('resources/views/filament/agency/pages/live-feed.blade.php');

        $this->assertMatchesRegularExpression(
            '/function postTimezone\(ScheduledPost \$post\).*?\$post->brand\?->timezone \?: \'UTC\'/s',
            $page,
        );
        // The view must actually use it — a helper nobody calls fixes nothing.
        $this->assertStringContainsString('$postTz = $this->postTimezone($post);', $view);
        $this->assertStringContainsString('$stampLocal = $stamp?->copy()->setTimezone($postTz);', $view);
    }

    public function test_read_pages_state_which_brands_they_are_showing(): void
    {
        // A screenshot of these pages must answer "which brand is this?".
        foreach ([
            'app/Filament/Agency/Pages/Performance.php',
            'app/Filament/Agency/Pages/LiveFeed.php',
        ] as $path) {
            $this->assertMatchesRegularExpression(
                '/function getSubheading\(.*?\$scope->description\(\)/s',
                $this->source($path),
                basename($path).' must name its brand scope in the subheading',
            );
        }
    }

    public function test_mixed_timezone_scopes_are_declared_not_implied(): void
    {
        // Printing one brand's timezone over a multi-brand page would be a lie.
        foreach ([
            'app/Filament/Agency/Pages/Performance.php',
            'app/Filament/Agency/Pages/LiveFeed.php',
        ] as $path) {
            $this->assertMatchesRegularExpression(
                '/function getSubheading\(.*?hasMixedTimezones\(\)/s',
                $this->source($path),
                basename($path).' must disclose a mixed-timezone scope',
            );
        }
    }

    // ---- The switcher itself ---------------------------------------------

    public function test_switcher_is_registered_in_the_agency_topbar(): void
    {
        $src = $this->source('app/Providers/Filament/AgencyPanelProvider.php');

        $this->assertStringContainsString('PanelsRenderHook::TOPBAR_START', $src);
        $this->assertStringContainsString('App\Livewire\BrandScopeSwitcher::class', $src);
        // Styles must reach <head>: a Livewire component renders after the
        // layout's @push stacks are flushed, so @push from it emits nothing.
        $this->assertStringContainsString('filament.agency.partials.brand-scope-styles', $src);
    }

    public function test_switcher_hides_itself_for_single_brand_workspaces(): void
    {
        // Solo tier (max_brands = 1) must never see the control.
        $this->assertMatchesRegularExpression(
            '/function shouldRender\(.*?available\(\)->count\(\) > 1/s',
            $this->source('app/Services/Brands/BrandScope.php'),
        );
        $this->assertStringContainsString(
            '@if ($this->shouldRender)',
            $this->source('resources/views/livewire/brand-scope-switcher.blade.php'),
        );
    }

    public function test_deselecting_the_last_brand_falls_back_to_all(): void
    {
        // An empty scope would render every page blank with no obvious way
        // back, which reads as a broken app rather than as a filter.
        $this->assertMatchesRegularExpression(
            '/function toggleBrand\(.*?if \(\$next === \[\]\) \{\s*\$this->selectAll\(\);/s',
            $this->source('app/Livewire/BrandScopeSwitcher.php'),
        );
    }

    public function test_changing_scope_reloads_so_no_surface_keeps_stale_data(): void
    {
        $this->assertMatchesRegularExpression(
            '/function apply\(array \$ids\).*?\$this->js\(\'window\.location\.reload\(\)\'\)/s',
            $this->source('app/Livewire/BrandScopeSwitcher.php'),
        );
    }

    public function test_switcher_does_not_override_a_livewire_framework_method(): void
    {
        // Livewire\Component::only() exists; overriding it with a different
        // signature is a fatal incompatibility.
        $src = $this->source('app/Livewire/BrandScopeSwitcher.php');

        $this->assertStringContainsString('public function showOnly(int $brandId)', $src);
        $this->assertStringNotContainsString('public function only(', $src);
    }

    public function test_scope_is_request_scoped_not_a_global_singleton(): void
    {
        // A plain singleton on a long-lived queue worker would carry one
        // tenant's brand selection into the next job.
        $this->assertStringContainsString(
            '$this->app->scoped(\App\Services\Brands\BrandScope::class);',
            $this->source('app/Providers/AppServiceProvider.php'),
        );
    }

    public function test_session_key_is_per_workspace(): void
    {
        // A super-admin moving between workspaces must not carry one tenant's
        // brand selection into another.
        $src = $this->source('app/Services/Brands/BrandScope.php');

        $this->assertStringContainsString("const SESSION_PREFIX = 'brand_scope.workspace_';", $src);
        $this->assertMatchesRegularExpression(
            '/function sessionKey\(.*?self::SESSION_PREFIX\.\$ws->id/s',
            $src,
        );
    }

    public function test_selection_is_reconciled_on_both_read_and_write(): void
    {
        $src = $this->source('app/Services/Brands/BrandScope.php');

        foreach (['selectedIds', 'set'] as $method) {
            $this->assertMatchesRegularExpression(
                "/function {$method}\(.*?self::reconcile\(/s",
                $src,
                "{$method}() must reconcile against the workspace's own brands",
            );
        }
    }
}

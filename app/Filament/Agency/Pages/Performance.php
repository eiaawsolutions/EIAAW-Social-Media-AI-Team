<?php

namespace App\Filament\Agency\Pages;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use App\Services\Brands\BrandScope;
use App\Services\Metricool\AccountGrowthService;
use App\Services\Metrics\MetricsCsvImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Performance — the receipt page. Real metrics from post_metrics, real
 * cost from ai_costs, real publish counts from scheduled_posts. Default
 * window is 30 days. Per-platform + per-pillar slices for the typical
 * "what's working" report.
 *
 * Stage 09 of SetupReadiness flips green when a published post has
 * platform_post_id (collected by MetricsCollector) OR a CSV upload exists.
 *
 * Metrics source: automated readings come from Blotato's analytics API via
 * BlotatoMetricsCollector. That backend is still on Blotato's roadmap, so
 * until it ships, the CSV upload below is the live "real metrics" path; once
 * Blotato turns analytics on, automated snapshots start flowing with no
 * change here. Either way, every number shown is a real reading or sourced
 * upload — never fabricated.
 */
class Performance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Performance';
    protected static ?string $title = 'Performance';
    protected static ?int $navigationSort = 12;
    protected static ?string $slug = 'performance';
    protected string $view = 'filament.agency.pages.performance';

    /** Livewire-safe scalar state. */
    public int $window = 30; // days

    public function mount(): void
    {
        $this->window = max(7, min(90, (int) request()->integer('window', 30)));
    }

    public function setWindow(int $days): void
    {
        $this->window = max(7, min(90, $days));
    }

    /**
     * CSV import + template download. While Blotato's analytics backend is
     * still on its roadmap, this is the live path for getting real engagement
     * numbers onto the dashboard (and remains the fallback afterwards for
     * posts Blotato can't report on). Operator exports analytics from each
     * platform's native dashboard (Meta Business Suite, LinkedIn page
     * analytics, TikTok studio, YT Studio, Threads Insights), pastes the post
     * URL, uploads. Each row → PostMetric snapshot with source='csv_upload'.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshGrowth')
                ->label('Refresh growth')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $svc = app(AccountGrowthService::class);

                    // Refresh EVERY brand currently in scope, not just the
                    // workspace's first Metricool-mapped brand — otherwise an
                    // agency viewing three clients could only ever refresh one
                    // of them, and the other two silently kept stale figures.
                    $refreshed = 0;
                    foreach ($this->growthBrands() as $brand) {
                        // Drop the cached payload AND the stampede guard, then
                        // queue a fresh pull on the worker. Never pull inline —
                        // that would re-pin the web worker (the original stall).
                        $svc->forget((int) $brand->metricool_blog_id, $this->window);
                        \Illuminate\Support\Facades\Cache::forget(
                            AccountGrowthService::refreshLockKey((int) $brand->metricool_blog_id, $this->window)
                        );
                        $svc->queueRefresh($brand, $this->window);
                        $refreshed++;
                    }

                    if ($refreshed === 0) {
                        Notification::make()
                            ->title('Nothing to refresh')
                            ->body('No brand in the current scope is connected to Metricool yet.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Refreshing growth')
                        ->body(sprintf(
                            'Pulling the latest followers + impressions from Metricool for %d brand%s in the background — they’ll appear here in a few seconds.',
                            $refreshed,
                            $refreshed === 1 ? '' : 's',
                        ))
                        ->success()
                        ->send();
                }),

            Action::make('downloadTemplate')
                ->label('Download CSV template')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    fn () => print(MetricsCsvImporter::templateCsv()),
                    'eiaaw-metrics-template.csv',
                    ['Content-Type' => 'text/csv'],
                )),

            Action::make('uploadMetrics')
                ->label('Upload metrics CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Upload analytics CSV')
                ->modalDescription('Export from the platform\'s native analytics, paste the public post URL alongside the counters, upload here. Rows match by platform_post_url against this workspace\'s posts. Empty cells stay empty (we never fabricate zeros).')
                ->schema([
                    FileUpload::make('csv')
                        ->label('CSV file')
                        ->acceptedFileTypes(['text/csv', 'application/csv', 'application/vnd.ms-excel', 'text/plain'])
                        ->maxSize(5 * 1024) // 5 MB
                        ->disk('local')
                        ->directory('metrics-uploads')
                        ->preserveFilenames()
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $relativePath = $data['csv'] ?? null;
                    if (! $relativePath) {
                        Notification::make()->title('No file received')->danger()->send();
                        return;
                    }

                    $ws = $this->workspace();
                    if (! $ws) {
                        Notification::make()->title('No workspace context')->danger()->send();
                        return;
                    }

                    // Deliberately the WHOLE workspace, not the current brand
                    // scope. Rows are matched by platform_post_url, which is
                    // globally unambiguous — narrowing to the selected brands
                    // would silently skip valid rows for brands the operator
                    // happens to have filtered out of view, and read as data
                    // loss rather than as a filter.
                    $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');
                    if ($brandIds->isEmpty()) {
                        Notification::make()
                            ->title('No brands in this workspace')
                            ->body('Add a brand and publish at least one post before uploading metrics.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $absolutePath = Storage::disk('local')->path($relativePath);
                    $report = app(MetricsCsvImporter::class)->import($absolutePath, $brandIds);

                    // Tidy up — uploaded CSV has been parsed, no need to keep.
                    Storage::disk('local')->delete($relativePath);

                    if (isset($report['error'])) {
                        Notification::make()
                            ->title('CSV could not be processed')
                            ->body($report['error'])
                            ->danger()
                            ->persistent()
                            ->send();
                        return;
                    }

                    $imported = (int) $report['imported'];
                    $skippedCount = count($report['skipped']);

                    if ($imported === 0 && $skippedCount === 0) {
                        Notification::make()
                            ->title('CSV was empty')
                            ->body('No data rows found.')
                            ->warning()
                            ->send();
                        return;
                    }

                    $body = "Imported {$imported} row(s) into post_metrics.";
                    if ($skippedCount > 0) {
                        $previewLines = collect(array_slice($report['skipped'], 0, 5))
                            ->map(fn (array $s) => "Row {$s['row']}: {$s['reason']}")
                            ->implode("\n");
                        $more = $skippedCount > 5 ? "\n…and " . ($skippedCount - 5) . ' more.' : '';
                        $body .= "\n\nSkipped {$skippedCount} row(s):\n{$previewLines}{$more}";
                    }

                    Notification::make()
                        ->title($imported > 0 ? 'Metrics imported' : 'No rows imported')
                        ->body($body)
                        ->color($imported > 0 ? 'success' : 'warning')
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function workspace(): ?Workspace
    {
        return $this->scope()->workspace();
    }

    /** The global brand scope set by the topbar switcher. */
    public function scope(): BrandScope
    {
        return app(BrandScope::class);
    }

    /**
     * The brand ids every figure on this page is computed from — the operator's
     * current selection, already intersected with their own workspace.
     *
     * This replaces the old "every brand in the workspace" pluck. That was the
     * root of this page's worst defect: post metrics summed ALL brands while the
     * growth and strategy cards above them described ONE brand, on the same
     * screen, with nothing saying so.
     *
     * @return array<int>
     */
    public function scopedBrandIds(): array
    {
        return $this->scope()->brandIds();
    }

    /**
     * Timezone for window cutoffs and display. Unambiguous when the scope is a
     * single brand; for a mixed scope it falls back to the workspace's first
     * brand and the subheading says so explicitly (see getSubheading) rather
     * than implying every brand shares one clock.
     */
    public function brandTimezone(): string
    {
        return $this->scope()->timezone();
    }

    /** True when the scope spans brands in different timezones. */
    public function hasMixedTimezones(): bool
    {
        return $this->scope()->hasMixedTimezones();
    }

    /**
     * Account growth (followers + impressions over time, per network) for THIS
     * workspace's brand — the section that used to live on the HQ-only
     * /admin/account-growth page, now folded into Performance so every workspace
     * (EIAAW HQ + each customer) sees its own account growth above its per-post
     * metrics. Live from Metricool via AccountGrowthService, scoped to the
     * workspace's mapped brand. Returns ['brand'=>null,…] when no brand is
     * mapped yet so the view shows a connect-prompt instead of a wrong account.
     *
     * @return array<string,mixed>
     */
    /**
     * The in-scope brands that actually have a Metricool account mapped, in
     * display order. Growth is an ACCOUNT-level reading (one timeseries per
     * blogId), so it can only ever be reported per brand — never summed.
     *
     * @return \Illuminate\Support\Collection<int, Brand>
     */
    public function growthBrands(): \Illuminate\Support\Collection
    {
        return Brand::query()
            ->whereIn('id', $this->scopedBrandIds())
            ->whereNotNull('metricool_blog_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * One account-growth block PER brand in scope.
     *
     * Previously this returned a single block for the workspace's first
     * Metricool-mapped brand while the post metrics below aggregated every
     * brand — so a Studio/Agency workspace read one client's follower chart
     * directly above another client's impressions. Growth is per-account and
     * cannot be summed, so the honest fix is one labelled block per brand.
     *
     * @return array<int, array<string,mixed>>
     */
    public function growthBlocks(): array
    {
        $svc = app(AccountGrowthService::class);
        $configured = \App\Services\Metricool\MetricoolClient::fromConfig() !== null;

        $blocks = [];
        foreach ($this->growthBrands() as $brand) {
            $blocks[] = [
                'configured' => $configured,
                'brand' => ['id' => $brand->id, 'name' => $brand->name, 'blog_id' => $brand->metricool_blog_id],
                // Web-safe read: cache-hit or a 'warming' scaffold — never the ~13
                // serial Metricool calls inside this request (that pinned the web
                // worker; see memory prod_web_is_artisan_serve_dev_server). The
                // worker warms the cache and the view polls it in.
                'data' => $svc->cachedForBrand($brand, $this->window),
            ];
        }

        return $blocks;
    }

    /**
     * The current Growth Strategy brief for the workspace's focused brand — best
     * posting times, winning hooks, CTA lift, follower momentum, recommended
     * objective mix, active-goal progress, and the plain-English summary. Every
     * number is computed from real metrics (GrowthStrategistAgent). Returns
     * ['brief'=>null] when no brief exists yet (the view shows nothing / a
     * "building" hint), so the card suppresses cleanly.
     *
     * @return array<string,mixed>
     */
    /**
     * One Growth Strategy brief PER brand in scope — same reasoning as
     * growthBlocks(): a brief is computed from one brand's own metrics and is
     * meaningless averaged across clients.
     *
     * @return array<int, array<string,mixed>>  each ['brand'=>string, 'brief'=>array]
     */
    public function growthStrategyBlocks(): array
    {
        $blocks = [];
        foreach (Brand::whereIn('id', $this->scopedBrandIds())->orderBy('name')->get() as $brand) {
            $block = $this->growthStrategyForBrand($brand);
            if ($block['brief'] !== null) {
                $blocks[] = ['brand' => $brand->name, 'brief' => $block['brief']];
            }
        }

        return $blocks;
    }

    /** @return array<string,mixed> */
    public function growthStrategyForBrand(Brand $brand): array
    {
        $brief = \App\Models\GrowthStrategyBrief::currentForBrand($brand->id)->first();
        if (! $brief) {
            return ['brief' => null];
        }

        $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $bestTimes = [];
        foreach ((array) ($brief->best_posting_times ?? []) as $platform => $buckets) {
            if (! is_array($buckets)) {
                continue;
            }
            $slots = [];
            foreach (array_slice($buckets, 0, 2) as $b) {
                if (is_array($b) && isset($b['hour'])) {
                    $slots[] = ($days[(int) ($b['day_of_week'] ?? 0)] ?? '?').' '.((int) $b['hour']).':00';
                }
            }
            if ($slots !== []) {
                $bestTimes[$platform] = implode(', ', $slots);
            }
        }

        $hooks = [];
        foreach ((array) ($brief->hook_performance ?? []) as $h) {
            if (is_array($h) && ! empty($h['hook_pattern'])) {
                $hooks[] = [
                    'hook' => (string) $h['hook_pattern'],
                    'win_rate' => isset($h['win_rate']) ? round(((float) $h['win_rate']) * 100) : null,
                ];
            }
        }

        $velocity = [];
        foreach ((array) ($brief->follower_velocity ?? []) as $v) {
            if (is_array($v) && ! empty($v['label'])) {
                $velocity[] = ['label' => (string) $v['label'], 'direction' => (string) ($v['direction'] ?? '')];
            }
        }

        $objectiveMix = [];
        foreach ((array) ($brief->recommended_objective_mix ?? []) as $obj => $pct) {
            $objectiveMix[$obj] = round(((float) $pct) * 100);
        }

        return [
            'brief' => [
                'summary' => (string) ($brief->summary ?? ''),
                'best_times' => $bestTimes,
                'platform_focus' => (array) ($brief->platform_focus ?? []),
                'hooks' => array_slice($hooks, 0, 5),
                'cta_lift' => (array) ($brief->cta_lift ?? []),
                'follower_velocity' => $velocity,
                'objective_mix' => $objectiveMix,
                'goal_progress' => (array) ($brief->goal_progress ?? []),
                'post_count' => (int) $brief->post_count_in_window,
                'updated_at' => $brief->updated_at?->diffForHumans(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $ws = $this->workspace();
        if (! $ws) return $this->emptySummary();

        $tz = $this->brandTimezone();
        $since = Carbon::now($tz)->subDays($this->window)->utc();
        $brandIds = $this->scopedBrandIds();

        $publishedCount = ScheduledPost::whereIn('brand_id', $brandIds)
            ->where('status', 'published')
            ->where('published_at', '>=', $since)
            ->count();

        $queuedCount = ScheduledPost::whereIn('brand_id', $brandIds)
            ->whereIn('status', ['queued', 'submitting', 'submitted'])
            ->count();

        $failedCount = ScheduledPost::whereIn('brand_id', $brandIds)
            ->where('status', 'failed')
            ->where('updated_at', '>=', $since)
            ->count();

        // Latest snapshot per post — sum across the window.
        $latestMetricIds = \DB::table('post_metrics')
            ->select(\DB::raw('MAX(id) as id'))
            ->whereIn('brand_id', $brandIds)
            ->where('observed_at', '>=', $since)
            ->groupBy('scheduled_post_id')
            ->pluck('id');

        $totals = PostMetric::whereIn('id', $latestMetricIds)
            ->selectRaw('COALESCE(SUM(impressions),0) as impressions')
            ->selectRaw('COALESCE(SUM(reach),0) as reach')
            ->selectRaw('COALESCE(SUM(likes),0) as likes')
            ->selectRaw('COALESCE(SUM(comments),0) as comments')
            ->selectRaw('COALESCE(SUM(shares),0) as shares')
            ->selectRaw('COALESCE(SUM(saves),0) as saves')
            ->selectRaw('COALESCE(SUM(video_views),0) as video_views')
            ->selectRaw('COALESCE(SUM(profile_visits),0) as profile_visits')
            ->selectRaw('COALESCE(SUM(url_clicks),0) as url_clicks')
            ->first();

        $totalCost = (float) AiCost::where('workspace_id', $ws->id)
            ->where('called_at', '>=', $since)
            ->sum('cost_usd');

        return [
            'window_days' => $this->window,
            'since' => $since->copy()->setTimezone($tz)->toDateString(),
            'published' => $publishedCount,
            'queued' => $queuedCount,
            'failed' => $failedCount,
            'impressions' => (int) ($totals->impressions ?? 0),
            'reach' => (int) ($totals->reach ?? 0),
            'likes' => (int) ($totals->likes ?? 0),
            'comments' => (int) ($totals->comments ?? 0),
            'shares' => (int) ($totals->shares ?? 0),
            'saves' => (int) ($totals->saves ?? 0),
            'video_views' => (int) ($totals->video_views ?? 0),
            'profile_visits' => (int) ($totals->profile_visits ?? 0),
            'url_clicks' => (int) ($totals->url_clicks ?? 0),
            'engagement_total' => (int) (($totals->likes ?? 0) + ($totals->comments ?? 0) + ($totals->shares ?? 0) + ($totals->saves ?? 0)),
            'cost_usd' => round($totalCost, 4),
            'cost_per_post' => $publishedCount > 0 ? round($totalCost / $publishedCount, 4) : 0,
        ];
    }

    /** @return array<int, array<string,mixed>> */
    public function perPlatform(): array
    {
        $ws = $this->workspace();
        if (! $ws) return [];
        $tz = $this->brandTimezone();
        $since = Carbon::now($tz)->subDays($this->window)->utc();
        $brandIds = $this->scopedBrandIds();

        $latestMetricIds = \DB::table('post_metrics')
            ->select(\DB::raw('MAX(id) as id'))
            ->whereIn('brand_id', $brandIds)
            ->where('observed_at', '>=', $since)
            ->groupBy('scheduled_post_id')
            ->pluck('id');

        return PostMetric::whereIn('id', $latestMetricIds)
            ->selectRaw('platform')
            ->selectRaw('COUNT(*) as posts')
            ->selectRaw('COALESCE(SUM(impressions),0) as impressions')
            ->selectRaw('COALESCE(SUM(likes),0) as likes')
            ->selectRaw('COALESCE(SUM(comments),0) as comments')
            ->selectRaw('COALESCE(SUM(shares),0) as shares')
            ->selectRaw('COALESCE(SUM(saves),0) as saves')
            ->groupBy('platform')
            ->orderByDesc('impressions')
            ->get()
            ->map(fn ($r) => [
                'platform' => $r->platform,
                'posts' => (int) $r->posts,
                'impressions' => (int) $r->impressions,
                'engagement' => (int) ($r->likes + $r->comments + $r->shares + $r->saves),
                'engagement_rate' => $r->impressions > 0
                    ? round(((int) ($r->likes + $r->comments + $r->shares + $r->saves)) / max(1, (int) $r->impressions), 4)
                    : null,
            ])
            ->all();
    }

    /** @return array<int, array<string,mixed>> */
    public function topPosts(): array
    {
        $ws = $this->workspace();
        if (! $ws) return [];
        $tz = $this->brandTimezone();
        $since = Carbon::now($tz)->subDays($this->window)->utc();
        $brandIds = $this->scopedBrandIds();

        // Latest metric per post — order by engagement desc.
        $latestMetricIds = \DB::table('post_metrics')
            ->select(\DB::raw('MAX(id) as id'))
            ->whereIn('brand_id', $brandIds)
            ->where('observed_at', '>=', $since)
            ->groupBy('scheduled_post_id')
            ->pluck('id');

        return PostMetric::with('scheduledPost.draft')
            ->whereIn('id', $latestMetricIds)
            ->orderByRaw('(COALESCE(impressions,0) + 5*COALESCE(likes,0) + 10*COALESCE(comments,0) + 20*COALESCE(shares,0) + 30*COALESCE(saves,0)) DESC')
            ->limit(10)
            ->get()
            ->map(fn (PostMetric $m) => [
                'platform' => $m->platform,
                'preview' => substr((string) ($m->scheduledPost?->draft?->body ?? '—'), 0, 80),
                'url' => $m->scheduledPost?->platform_post_url,
                'impressions' => $m->impressions,
                'likes' => $m->likes,
                'comments' => $m->comments,
                'shares' => $m->shares,
                'saves' => $m->saves,
            ])
            ->all();
    }

    public function getHeading(): string|Htmlable
    {
        return 'Performance — last ' . $this->window . ' days';
    }

    public function getSubheading(): string|Htmlable|null
    {
        $ws = $this->workspace();
        if (! $ws) return null;

        $scope = $this->scope();

        // Name the brand scope FIRST. Every number below is computed from it,
        // and a screenshot of this page must be self-describing — "which brand
        // is this?" was previously unanswerable.
        $bits = [$ws->name, $scope->description()];

        // A mixed scope has no single clock. Say so instead of printing one
        // brand's timezone as if it governed every row.
        $bits[] = $scope->hasMixedTimezones()
            ? 'mixed timezones'
            : $this->brandTimezone();

        $bits[] = 'every number is a real reading or sourced upload, no fabricated metrics';

        return implode(' · ', $bits);
    }

    private function emptySummary(): array
    {
        return [
            'window_days' => $this->window,
            'since' => Carbon::now($this->brandTimezone())->subDays($this->window)->toDateString(),
            'published' => 0,
            'queued' => 0,
            'failed' => 0,
            'impressions' => 0,
            'reach' => 0,
            'likes' => 0,
            'comments' => 0,
            'shares' => 0,
            'saves' => 0,
            'video_views' => 0,
            'profile_visits' => 0,
            'url_clicks' => 0,
            'engagement_total' => 0,
            'cost_usd' => 0,
            'cost_per_post' => 0,
        ];
    }
}

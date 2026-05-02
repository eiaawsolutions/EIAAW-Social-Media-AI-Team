<?php

namespace App\Filament\Agency\Pages;

use App\Models\AiCost;
use App\Models\Brand;
use App\Models\PostMetric;
use App\Models\ScheduledPost;
use App\Models\Workspace;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * Performance — the receipt page. Real metrics from post_metrics, real
 * cost from ai_costs, real publish counts from scheduled_posts. Default
 * window is 30 days. Per-platform + per-pillar slices for the typical
 * "what's working" report.
 *
 * Stage 09 of SetupReadiness flips green when a published post has
 * platform_post_id (collected by MetricsCollector) OR a CSV upload exists.
 */
class Performance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationLabel = 'Performance';
    protected static ?string $title = 'Performance';
    protected static ?int $navigationSort = 10;
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

    public function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $ws = $this->workspace();
        if (! $ws) return $this->emptySummary();

        $since = Carbon::now()->subDays($this->window);
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

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
            'since' => $since->toDateString(),
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
        $since = Carbon::now()->subDays($this->window);
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

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
        $since = Carbon::now()->subDays($this->window);
        $brandIds = Brand::where('workspace_id', $ws->id)->pluck('id');

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
        return $ws->name . ' · every number is a real reading or sourced upload, no fabricated metrics';
    }

    private function emptySummary(): array
    {
        return [
            'window_days' => $this->window,
            'since' => Carbon::now()->subDays($this->window)->toDateString(),
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

<?php

namespace App\Filament\Pages;

use App\Services\Metricool\AccountGrowthService;
use App\Services\Metricool\MetricoolClient;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * HQ Account Growth — EIAAW's own social presence, live: follower count and
 * impressions over time, per network. This is the "Account" growth view
 * (mirrors Metricool's own account dashboard), distinct from the Agency
 * panel's Performance page which is per-post analytics + CSV.
 *
 * Data source: Metricool's account timeseries API (GET /stats/timeline/{metric})
 * via AccountGrowthService, scoped to EIAAW's internal brand blogId. Results
 * are cached ~5 min so the 60s poll stays live without hammering Metricool.
 *
 * Truthfulness Contract: only real Metricool readings are plotted. TikTok and
 * Threads have no Metricool account-timeline metric, so they appear as tiles
 * marked "no API data" — never a fabricated line. A network that 404s (not
 * connected / not on plan) shows "not available".
 *
 * Phase 1 = HQ only (this page). Once proven, the same AccountGrowthService is
 * dropped into a per-customer page in the Agency panel — it's already
 * brand-agnostic (forBrand()), so the rollout is a thin wrapper, not a rebuild.
 *
 * Admin (HQ) panel, super-admin only — same gate as Cost monitor.
 */
class AccountGrowth extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-trending-up';

    protected static ?string $navigationLabel = 'Account growth';

    protected static \UnitEnum|string|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4; // below Cost monitor (3)

    protected static ?string $title = 'Account growth';

    protected static ?string $slug = 'account-growth';

    protected string $view = 'filament.pages.account-growth';

    /** Window in days, bound from the picker. Clamped to [7,180]. */
    public int $window = 30;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function mount(): void
    {
        $this->window = max(7, min(180, (int) request()->integer('window', 30)));
    }

    public function getSubheading(): ?string
    {
        return 'EIAAW\'s own social growth, live from Metricool. Followers and impressions over time, per network. '
            .'Every number is a real reading from the platform — networks Metricool can\'t report (TikTok, Threads) are '
            .'marked plainly, never guessed. Refreshes every 60 seconds.';
    }

    /** Window options for the picker — common reporting ranges. */
    public function windowOptions(): array
    {
        return [
            7 => 'Last 7 days',
            14 => 'Last 14 days',
            30 => 'Last 30 days',
            60 => 'Last 60 days',
            90 => 'Last 90 days',
            180 => 'Last 180 days',
        ];
    }

    /**
     * Refresh = drop this brand+window's cache so the next render re-pulls live.
     * Mapping the HQ brand is operator work (Metricool onboarding); here we only
     * offer the refresh + a deep link to the onboarding console when unmapped.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $svc = app(AccountGrowthService::class);
                    $brand = $svc->hqBrand();
                    if ($brand && $brand->metricool_blog_id) {
                        $svc->forget((int) $brand->metricool_blog_id, $this->window);
                    }
                    Notification::make()
                        ->title('Refreshed')
                        ->body('Pulled the latest account timeseries from Metricool.')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * The full growth board — drives the Blade view. Resolves EIAAW's own brand
     * and asks AccountGrowthService for both dimensions over the window.
     *
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $svc = app(AccountGrowthService::class);
        $brand = $svc->hqBrand();

        if ($brand === null) {
            return [
                'brand' => null,
                'metricool_configured' => MetricoolClient::fromConfig() !== null,
                'onboarding_url' => MetricoolOnboarding::getUrl(),
                'growth' => null,
            ];
        }

        return [
            'brand' => [
                'id' => $brand->id,
                'name' => $brand->name,
                'blog_id' => $brand->metricool_blog_id,
                'timezone' => $brand->timezone ?: 'UTC',
            ],
            'metricool_configured' => MetricoolClient::fromConfig() !== null,
            'onboarding_url' => MetricoolOnboarding::getUrl(),
            'growth' => $svc->forBrand($brand, $this->window),
        ];
    }
}

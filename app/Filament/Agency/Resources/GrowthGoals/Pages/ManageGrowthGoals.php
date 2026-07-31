<?php

namespace App\Filament\Agency\Resources\GrowthGoals\Pages;

use App\Filament\Agency\Resources\GrowthGoals\GrowthGoalResource;
use App\Models\Brand;
use App\Models\BrandGrowthGoal;
use App\Services\Metricool\AccountGrowthService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManageGrowthGoals extends ManageRecords
{
    protected static string $resource = GrowthGoalResource::class;

    public function getSubheading(): ?string
    {
        return 'Set a target and the Growth Strategist biases your content plan, hooks, and CTAs toward reaching it. Progress is measured from real analytics — never estimated.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data): Model {
                    $user = auth()->user();
                    $workspaceId = $user?->current_workspace_id
                        ?? $user?->ownedWorkspaces()->value('id');

                    $brand = Brand::query()
                        ->whereKey($data['brand_id'] ?? null)
                        ->where('workspace_id', $workspaceId)
                        ->first();

                    if (! $brand) {
                        Notification::make()
                            ->title('Brand not found')
                            ->body('That brand is not in your workspace. Reload and try again.')
                            ->danger()
                            ->send();
                        $this->halt();
                    }

                    // Snapshot the REAL current value so progress measures from
                    // the true starting point, not assumed zero. Best-effort —
                    // a metric we can't read live starts at 0 (progress still
                    // honest: it measures gain over the window).
                    $data['workspace_id'] = $workspaceId;
                    $data['created_by_user_id'] = $user?->id;
                    $data['baseline_value'] = $this->snapshotBaseline($brand, $data['target_metric'], $data['platform'] ?? null);
                    $data['status'] = 'active';

                    // Is this target arithmetically reachable at the rate this
                    // brand actually moves? Snapshotted now, so a goal that was
                    // never closeable is labelled from day one instead of
                    // reporting "lagging" every week for its whole window —
                    // which is what prod did with a 1 -> 10,000 followers goal.
                    $feasibility = BrandGrowthGoal::feasibility(
                        (int) $data['baseline_value'],
                        (int) $data['target_value'],
                        Carbon::parse($data['window_starts_on']),
                        Carbon::parse($data['window_ends_on']),
                        $this->observedDailyRate($brand, $data['target_metric'], $data['platform'] ?? null),
                    );
                    $data['required_per_day'] = $feasibility['required_per_day'];
                    $data['observed_per_day'] = $feasibility['observed_per_day'];
                    $data['feasibility_verdict'] = $feasibility['verdict'];

                    $goal = BrandGrowthGoal::create($data);

                    $this->warnIfUnreachable($feasibility, (string) $data['target_metric']);

                    return $goal;
                }),
        ];
    }

    /**
     * Tell the operator plainly when the target they just set cannot be reached
     * at the rate the brand actually moves. Advisory, never blocking — the goal
     * is saved either way. Silence here is what let two 10,000-follower goals
     * sit on a 1-follower account for two months looking like ordinary work.
     *
     * @param  array{required_per_day:?float,observed_per_day:?float,verdict:string,multiple:?float}  $f
     */
    private function warnIfUnreachable(array $f, string $metric): void
    {
        if ($f['verdict'] === BrandGrowthGoal::FEASIBILITY_PLAUSIBLE
            || $f['verdict'] === BrandGrowthGoal::FEASIBILITY_UNKNOWN) {
            return;
        }

        $required = $f['required_per_day'] !== null ? rtrim(rtrim(number_format($f['required_per_day'], 2), '0'), '.') : '?';
        $observed = $f['observed_per_day'] !== null ? rtrim(rtrim(number_format($f['observed_per_day'], 2), '0'), '.') : '?';

        $body = "This target needs about {$required} {$metric}/day. Measured over the last 30 days this brand is running at about {$observed}/day.";
        $body .= $f['multiple'] !== null ? " That is roughly {$f['multiple']}x its current rate." : '';

        if ($f['verdict'] === BrandGrowthGoal::FEASIBILITY_INFEASIBLE) {
            Notification::make()
                ->title('Saved — but this target is out of reach')
                ->body($body.' The goal is saved, but the planner will aim for the largest honest gain rather than this number. Consider a target closer to what the account can actually do.')
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Saved — this is a stretch target')
            ->body($body.' Reachable only with a real change in approach, not just more posts.')
            ->warning()
            ->send();
    }

    /**
     * The brand's MEASURED daily rate for a metric over the last 30 days, or
     * null when we have no reading. Never estimated — an unmeasurable metric
     * yields an honest 'unknown' feasibility verdict rather than a guess.
     */
    private function observedDailyRate(Brand $brand, string $metric, ?string $platform): ?float
    {
        $days = 30;

        if (BrandGrowthGoal::isStockMetric($metric)) {
            if (! $platform) {
                return null; // account-wide follower goals have no single reading
            }
            try {
                $payload = app(AccountGrowthService::class)->forBrand($brand, $days);
                foreach ($payload['followers']['networks'] ?? [] as $row) {
                    if (($row['network'] ?? null) === $platform && ($row['status'] ?? '') === 'ok') {
                        return round((float) ($row['change'] ?? 0) / $days, 4);
                    }
                }
            } catch (\Throwable) {
                return null;
            }

            return null;
        }

        // engagement_rate is a level, not a flow — a "per day" rate for it is
        // not meaningful, so feasibility stays honestly unknown.
        if (in_array($metric, BrandGrowthGoal::RATIO_METRICS, true)) {
            return null;
        }

        $column = match ($metric) {
            'reach' => 'reach',
            'link_clicks' => 'url_clicks',
            'profile_visits' => 'profile_visits',
            default => null,
        };
        if ($column === null) {
            return null;
        }

        try {
            $latestIds = DB::table('post_metrics')
                ->select(DB::raw('MAX(id) as id'))
                ->where('brand_id', $brand->id)
                ->where('observed_at', '>=', now()->subDays($days))
                ->when($platform, fn ($q) => $q->where('platform', $platform))
                ->groupBy('scheduled_post_id')
                ->pluck('id');

            if ($latestIds->isEmpty()) {
                return null;
            }

            $values = DB::table('post_metrics')
                ->whereIn('id', $latestIds)
                ->whereNotNull($column)
                ->pluck($column);

            if ($values->isEmpty()) {
                return null; // no readings — unmeasurable, not zero
            }

            return round((float) $values->sum() / $days, 4);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Read the real current value of the target metric to snapshot as baseline.
     *
     * The baseline determines what "0% progress" means, so it must match how the
     * metric accumulates (BrandGrowthGoal's STOCK / FLOW / RATIO split):
     *
     *   STOCK (followers)      — the current level. A 5,000-follower goal set at
     *                            4,000 must read 0%, not 80%; crediting the
     *                            pre-existing base would make every goal look
     *                            nearly done on day one.
     *   RATIO (engagement_rate)— likewise a level, so likewise snapshotted. This
     *                            was previously lumped in with FLOW and defaulted
     *                            to 0, which gave a brand already at 4% against a
     *                            5% target an instant 80%.
     *   FLOW (reach, clicks,
     *         profile visits)  — 0 is correct: we are counting accumulation from
     *                            goal start, not a level.
     *
     * Best-effort — a metric we cannot read live starts at 0, and the progress
     * math still honestly measures gain over the window.
     */
    private function snapshotBaseline(Brand $brand, string $metric, ?string $platform): int
    {
        if (BrandGrowthGoal::isStockMetric($metric)) {
            if (! $platform) {
                return 0;
            }

            try {
                $payload = app(AccountGrowthService::class)->forBrand($brand, 30);
                $networks = $payload['followers']['networks'] ?? [];
                foreach ($networks as $row) {
                    if (($row['network'] ?? null) === $platform && ($row['status'] ?? '') === 'ok') {
                        return (int) ($row['headline'] ?? 0);
                    }
                }
            } catch (\Throwable) {
                // fall through to 0
            }

            return 0;
        }

        if (in_array($metric, BrandGrowthGoal::RATIO_METRICS, true)) {
            return $this->currentEngagementRatePercent($brand, $platform) ?? 0;
        }

        return 0;
    }

    /**
     * Mean engagement_rate over the last 30 days as whole percent (the unit the
     * operator's integer target is in — post_metrics stores it as a fraction).
     * Null when there are no readings.
     */
    private function currentEngagementRatePercent(Brand $brand, ?string $platform): ?int
    {
        try {
            $latestIds = DB::table('post_metrics')
                ->select(DB::raw('MAX(id) as id'))
                ->where('brand_id', $brand->id)
                ->where('observed_at', '>=', now()->subDays(30))
                ->when($platform, fn ($q) => $q->where('platform', $platform))
                ->groupBy('scheduled_post_id')
                ->pluck('id');

            if ($latestIds->isEmpty()) {
                return null;
            }

            $values = DB::table('post_metrics')
                ->whereIn('id', $latestIds)
                ->whereNotNull('engagement_rate')
                ->pluck('engagement_rate');

            if ($values->isEmpty()) {
                return null;
            }

            return (int) round($values->avg() * 100);
        } catch (\Throwable) {
            return null;
        }
    }
}

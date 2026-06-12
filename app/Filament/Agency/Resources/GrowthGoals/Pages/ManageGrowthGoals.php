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

                    return BrandGrowthGoal::create($data);
                }),
        ];
    }

    /**
     * Read the real current value of the target metric to snapshot as baseline.
     * Followers come from AccountGrowthService; other metrics start at 0 until a
     * first-party reading exists (the progress math still measures gain).
     */
    private function snapshotBaseline(Brand $brand, string $metric, ?string $platform): int
    {
        if ($metric !== 'followers' || ! $platform) {
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
}

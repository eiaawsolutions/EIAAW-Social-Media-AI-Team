<?php

namespace App\Filament\Agency\Resources\Brands\Pages;

use App\Filament\Agency\Resources\Brands\BrandResource;
use App\Services\Billing\PlanCaps;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageBrands extends ManageRecords
{
    protected static string $resource = BrandResource::class;

    public function getSubheading(): ?string
    {
        $ws = auth()->user()?->currentWorkspace;
        if (! $ws) {
            return 'The brands you publish for. Add one to start — each brand carries its own voice, palette, and platforms.';
        }
        $caps = app(PlanCaps::class)->capsFor($ws);
        return sprintf(
            'The brands you publish for. Each brand carries its own voice, palette, and platforms. Using %d of %s on your %s plan.',
            $ws->activeBrandsCount(),
            $caps['max_brands'] >= PHP_INT_MAX ? '∞' : $caps['max_brands'],
            ucfirst($ws->plan),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // Block creation past the plan cap. Filament's `visible`
                // hides the button outright when at-cap so the user sees
                // a clean upgrade nudge in the subheading instead of a
                // disabled button or a runtime "create failed" toast.
                ->visible(function (): bool {
                    $ws = auth()->user()?->currentWorkspace;
                    if (! $ws) return false;
                    return app(PlanCaps::class)->canAddBrand($ws);
                })
                ->before(function (): void {
                    // Defence-in-depth: ::visible() hides the button, but
                    // someone with HTTP could still POST. Re-check at create
                    // time and surface a notification.
                    $ws = auth()->user()?->currentWorkspace;
                    if (! $ws || ! app(PlanCaps::class)->canAddBrand($ws)) {
                        Notification::make()
                            ->title('Brand limit reached')
                            ->body('Your current plan is at its brand limit. Archive an unused brand or upgrade to add more.')
                            ->warning()
                            ->persistent()
                            ->send();
                        $this->halt();
                    }
                }),
        ];
    }
}

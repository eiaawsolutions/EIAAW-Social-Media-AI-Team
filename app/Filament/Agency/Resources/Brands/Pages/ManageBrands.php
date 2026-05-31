<?php

namespace App\Filament\Agency\Resources\Brands\Pages;

use App\Exceptions\BrandCreationRefused;
use App\Filament\Agency\Resources\Brands\BrandResource;
use App\Models\Brand;
use App\Services\Billing\PlanCaps;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;

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
                    // Defence-in-depth UX layer: ::visible() hides the button, but
                    // someone with HTTP could still POST. This cheap re-check gives
                    // a fast, friendly bounce in the common case. It is NOT the
                    // authoritative gate — that lives in createBrandOrFail()'s
                    // locked transaction (::using below), which is immune to the
                    // stale-relation / double-submit race that let a solo workspace
                    // accumulate two brands. See [[onboarding-split-brain-brands]].
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
                })
                // Authoritative create path. All cap + duplicate-name enforcement
                // happens atomically inside one locked transaction here, so two
                // rapid creates can't both slip past the cheap ::before check.
                ->using(function (array $data): Model {
                    $ws = auth()->user()?->currentWorkspace;
                    if (! $ws) {
                        Notification::make()
                            ->title('No workspace')
                            ->body('We could not resolve your workspace. Please reload and try again.')
                            ->danger()
                            ->send();
                        $this->halt();
                        // halt() throws; the return is unreachable but satisfies
                        // the declared return type for static analysis.
                        return new Brand();
                    }

                    try {
                        return app(PlanCaps::class)->createBrandOrFail($ws, $data);
                    } catch (BrandCreationRefused $e) {
                        Notification::make()
                            ->title($e->isDuplicateName() ? 'Brand already exists' : 'Brand limit reached')
                            ->body($e->getMessage())
                            ->warning()
                            ->persistent()
                            ->send();
                        $this->halt();
                        return new Brand();
                    }
                }),
        ];
    }
}

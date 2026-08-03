<?php

namespace App\Livewire;

use App\Services\Brands\BrandScope;
use Illuminate\Support\Collection;
use Livewire\Component;

/**
 * The global brand switcher that sits in the Agency topbar.
 *
 * One control, honoured by every page and table in the panel (see BrandScope).
 * It is a MULTI-select: the operator can look at all brands, one brand, or an
 * explicit subset — an agency comparing two clients does not have to click
 * through them one at a time.
 *
 * Renders only when the workspace has more than one brand, so Solo-tier
 * customers never see it.
 *
 * On change we do a hard page reload rather than trying to notify every other
 * Livewire component on the page. Scope is a cross-cutting concern — Filament
 * tables, custom page bodies, header widgets and subheadings all read it — and
 * a reload is the only way to guarantee none of them is left showing a
 * previous brand's data. Showing the wrong brand's numbers is precisely the
 * failure mode this feature exists to remove, so correctness wins over the
 * saved round-trip.
 */
class BrandScopeSwitcher extends Component
{
    /**
     * Currently selected brand ids. Empty = "All brands".
     *
     * @var array<int, int>
     */
    public array $selected = [];

    /** Whether the dropdown panel is expanded. */
    public bool $open = false;

    public function mount(): void
    {
        $this->selected = $this->scope()->selectedIds();
    }

    private function scope(): BrandScope
    {
        return app(BrandScope::class);
    }

    /** @return Collection<int, \App\Models\Brand> */
    public function getBrandsProperty(): Collection
    {
        return $this->scope()->available();
    }

    public function getLabelProperty(): string
    {
        return $this->scope()->label();
    }

    public function getShouldRenderProperty(): bool
    {
        return $this->scope()->shouldRender();
    }

    /** Toggle the panel open/closed. */
    public function toggleOpen(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Add/remove one brand from the selection.
     *
     * Deselecting the last remaining brand falls back to "All brands" rather
     * than an empty view — an empty scope would render every page blank with no
     * obvious way back, which reads as a broken app rather than a filter.
     */
    public function toggleBrand(int $brandId): void
    {
        $current = $this->scope()->selectedIds();

        // An "All brands" scope is stored as []; to subtract one brand from it
        // we first have to make the implicit set explicit.
        if ($current === []) {
            $current = $this->scope()->availableIds();
        }

        $next = in_array($brandId, $current, true)
            ? array_values(array_diff($current, [$brandId]))
            : [...$current, $brandId];

        if ($next === []) {
            $this->selectAll();

            return;
        }

        $this->apply($next);
    }

    /**
     * Scope to exactly one brand — the common case, one click.
     *
     * Named showOnly() rather than only() so it can never collide with a
     * framework method on Livewire\Component.
     */
    public function showOnly(int $brandId): void
    {
        $this->apply([$brandId]);
    }

    /** Reset to every brand in the workspace. */
    public function selectAll(): void
    {
        $this->apply([]);
    }

    /**
     * Persist the selection and reload so every surface re-queries under the
     * new scope.
     *
     * @param  array<int, int>  $ids
     */
    private function apply(array $ids): void
    {
        $this->scope()->set($ids);
        $this->selected = $this->scope()->selectedIds();
        $this->open = false;

        $this->js('window.location.reload()');
    }

    /**
     * Is this brand currently being shown? An "All brands" scope means every
     * brand is checked, even though the stored selection is empty.
     */
    public function isChecked(int $brandId): bool
    {
        return $this->selected === [] || in_array($brandId, $this->selected, true);
    }

    public function render()
    {
        return view('livewire.brand-scope-switcher');
    }
}

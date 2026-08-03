<?php

namespace App\Filament\Agency\Concerns;

use App\Services\Brands\BrandScope;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applies the global brand scope (the topbar switcher) to a Filament resource.
 *
 * Every Agency resource already isolates by WORKSPACE in getEloquentQuery().
 * This trait layers the operator's brand selection on top of that, so a
 * multi-brand tier (Studio / Agency / Enterprise / EIAAW Internal) can narrow
 * any list to one brand or a subset instead of reading a blended pile of rows
 * with nothing to distinguish them.
 *
 * Two pieces, both needed:
 *
 *   applyBrandScope()  — narrows the query to the selected brands.
 *   brandColumn()      — the "Brand" column, shown only when the visible rows
 *                        can actually span more than one brand. Without it a
 *                        multi-brand list is unreadable: two drafts sitting
 *                        next to each other give no clue which client they
 *                        belong to.
 *
 * SECURITY NOTE: this trait only ever NARROWS. BrandScope::brandIds() is
 * pre-intersected with the workspace's own brands, so a tampered session
 * cannot widen the result set — the workspace whereHas in each resource
 * remains the authoritative isolation boundary and is never removed.
 */
trait ScopesToSelectedBrands
{
    /**
     * Narrow a workspace-scoped query to the brands the operator has selected.
     *
     * A no-op when the scope is "All brands" — the caller's workspace
     * constraint already covers exactly that set, so we skip a redundant
     * `whereIn` over every brand id.
     *
     * @param  string  $column  the FK on the resource's own table
     */
    protected static function applyBrandScope(Builder $query, string $column = 'brand_id'): Builder
    {
        $scope = app(BrandScope::class);

        if ($scope->isAll()) {
            return $query;
        }

        return $query->whereIn($column, $scope->brandIds());
    }

    /**
     * A "Brand" column that appears only when it carries information.
     *
     * Hidden when the current scope resolves to a single brand (every row would
     * repeat the same value) and when the workspace only has one brand at all.
     */
    protected static function brandColumn(): Tables\Columns\TextColumn
    {
        return Tables\Columns\TextColumn::make('brand.name')
            ->label('Brand')
            ->badge()
            ->color('gray')
            ->sortable()
            ->searchable()
            ->toggleable()
            ->visible(fn (): bool => count(app(BrandScope::class)->brandIds()) > 1);
    }

    /**
     * Empty-state hint that names the active scope, so "no records" is never
     * read as "no data exists" when it actually means "none for this brand".
     */
    protected static function brandScopeEmptyStateHint(): ?string
    {
        $scope = app(BrandScope::class);

        if (! $scope->shouldRender() || $scope->isAll()) {
            return null;
        }

        return 'Showing '.$scope->description().' only — switch brands in the top bar to see the rest.';
    }
}

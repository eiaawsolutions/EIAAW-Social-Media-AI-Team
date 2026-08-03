<?php

namespace App\Services\Brands;

use App\Models\Brand;
use App\Models\Workspace;
use Illuminate\Support\Collection;

/**
 * The workspace-wide brand scope — which brand(s) the operator is currently
 * looking at. One session-backed selection, honoured by every page and table in
 * the Agency panel.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this, only the Brand-corpus page had a brand picker. Every other
 * surface either blended all brands together with no way to tell them apart
 * (Drafts, Scheduled posts, Live feed, Performance) or silently fell back to
 * `orderBy('id')->first()` — which meant multi-brand tiers (Studio 3 / Agency
 * 10 / Enterprise / EIAAW Internal) could never target brands #2..n at all:
 *
 *   - AutonomyLane always wrote brand #1's autonomy_settings row.
 *   - "Draft all undrafted entries" only ever fanned out brand #1's calendar.
 *   - Performance mixed scopes: per-post metrics aggregated EVERY brand while
 *     the growth + strategy cards showed ONE brand, on the same screen.
 *
 * SELECTION MODEL
 * ---------------
 * The selection is a SET of brand ids, so the operator can look at one brand, a
 * few brands, or all of them:
 *
 *   []          → "All brands" (the default; effective ids = every live brand)
 *   [7]         → a single brand
 *   [7, 8, 12]  → an explicit subset
 *
 * Empty-means-all is deliberate: a brand added later is automatically included
 * rather than silently missing from an operator's stale explicit list.
 *
 * ISOLATION INVARIANT (the security-relevant part)
 * ------------------------------------------------
 * Session data is attacker-influenceable, so the stored ids are NEVER trusted.
 * Every read intersects them with the brands that actually belong to the
 * operator's current workspace (see reconcile()). Ids that are stale, archived,
 * or belong to another tenant are dropped silently rather than widening a query.
 * That makes `brandIds()` safe to hand straight to a `whereIn` — it can only
 * ever narrow within the workspace, never escape it.
 *
 * The scope is also keyed PER WORKSPACE, so a super-admin moving between
 * workspaces never carries one tenant's brand selection into another.
 */
class BrandScope
{
    /** Session key prefix; the workspace id is appended so scopes never bleed. */
    public const SESSION_PREFIX = 'brand_scope.workspace_';

    /** Memoised per request — available() is a DB hit and is read many times. */
    private ?Collection $availableCache = null;

    private ?int $availableCacheWorkspaceId = null;

    // ---- Pure helpers (no session, no DB — unit-testable in isolation) ----

    /**
     * Intersect a stored selection with what the workspace actually owns.
     *
     * This is the isolation invariant in one function: anything not in
     * $availableIds is dropped, so a tampered session can only ever shrink the
     * result set. A selection that survives as "everything available" collapses
     * back to [] (= All brands) so the operator keeps picking up new brands.
     *
     * @param  array<int|string>  $sessionIds   raw, untrusted
     * @param  array<int>         $availableIds the workspace's live brand ids
     * @return array<int>                       validated, de-duped, sorted
     */
    public static function reconcile(array $sessionIds, array $availableIds): array
    {
        $wanted = [];
        foreach ($sessionIds as $id) {
            if (is_numeric($id)) {
                $wanted[] = (int) $id;
            }
        }

        $valid = array_values(array_unique(array_intersect($wanted, $availableIds)));
        sort($valid);

        // Selecting literally every brand is the same as "All brands". Collapse
        // so a brand created tomorrow is included instead of being invisible.
        if ($valid !== [] && count($valid) === count($availableIds)) {
            return [];
        }

        return $valid;
    }

    /**
     * The ids a query should actually filter on. An empty selection ("All")
     * expands to every available brand rather than to "no filter" — so callers
     * can always `whereIn()` and get workspace isolation for free.
     *
     * @param  array<int>  $selectedIds
     * @param  array<int>  $availableIds
     * @return array<int>
     */
    public static function effectiveIds(array $selectedIds, array $availableIds): array
    {
        return $selectedIds === [] ? array_values($availableIds) : $selectedIds;
    }

    // ---- Workspace / brand resolution ------------------------------------

    /**
     * The operator's active workspace. Mirrors the resolution order used across
     * the Agency panel (own-workspace only, for everyone including HQ staff).
     */
    public function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }

    /**
     * Every brand the operator may scope to — non-archived, own workspace,
     * alphabetical (the order the switcher renders).
     *
     * @return Collection<int, Brand>
     */
    public function available(): Collection
    {
        $ws = $this->workspace();
        if (! $ws) {
            return collect();
        }

        if ($this->availableCache !== null && $this->availableCacheWorkspaceId === $ws->id) {
            return $this->availableCache;
        }

        $this->availableCacheWorkspaceId = $ws->id;

        return $this->availableCache = Brand::query()
            ->where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);
    }

    /** @return array<int> */
    public function availableIds(): array
    {
        return $this->available()->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    // ---- Selection state --------------------------------------------------

    /** Session key for the current workspace, or null when there isn't one. */
    private function sessionKey(): ?string
    {
        $ws = $this->workspace();

        return $ws ? self::SESSION_PREFIX.$ws->id : null;
    }

    /**
     * The validated selection. [] means "All brands".
     *
     * @return array<int>
     */
    public function selectedIds(): array
    {
        $key = $this->sessionKey();
        if (! $key) {
            return [];
        }

        return self::reconcile(
            (array) session()->get($key, []),
            $this->availableIds(),
        );
    }

    /**
     * Replace the selection. Values are reconciled against the workspace before
     * they are written, so nothing invalid is ever persisted in the first place.
     *
     * @param  array<int|string>  $ids
     */
    public function set(array $ids): void
    {
        $key = $this->sessionKey();
        if (! $key) {
            return;
        }

        session()->put($key, self::reconcile($ids, $this->availableIds()));
    }

    /** Reset to "All brands". */
    public function clear(): void
    {
        $key = $this->sessionKey();
        if ($key) {
            session()->forget($key);
        }
    }

    /** True when no explicit subset is selected (i.e. showing every brand). */
    public function isAll(): bool
    {
        return $this->selectedIds() === [];
    }

    /**
     * The brand ids every scoped query filters on. Safe to pass directly to
     * `whereIn('brand_id', ...)` — always a subset of the operator's workspace.
     *
     * @return array<int>
     */
    public function brandIds(): array
    {
        return self::effectiveIds($this->selectedIds(), $this->availableIds());
    }

    /**
     * The one brand in scope, or null when the scope covers zero or many.
     *
     * Write surfaces (autonomy lane, corpus seeding, wizard stages) use this to
     * pre-select their own single-brand picker. They must NEVER silently act on
     * this value without showing the operator which brand it is — that ambiguity
     * is exactly the bug this class exists to kill.
     */
    public function single(): ?Brand
    {
        $ids = $this->brandIds();
        if (count($ids) !== 1) {
            return null;
        }

        return $this->available()->firstWhere('id', $ids[0]);
    }

    /**
     * The brands currently in scope, in display order.
     *
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        $ids = $this->brandIds();

        return $this->available()->whereIn('id', $ids)->values();
    }

    // ---- Presentation -----------------------------------------------------

    /**
     * Should the switcher render at all? A workspace with one brand (Solo tier,
     * or any tier that has only created one) gets no control — there is nothing
     * to choose between and the chrome would be pure noise.
     */
    public function shouldRender(): bool
    {
        return $this->available()->count() > 1;
    }

    /** Short label for the switcher trigger: "All brands" / a name / "3 brands". */
    public function label(): string
    {
        $selected = $this->selectedIds();

        if ($selected === []) {
            return 'All brands';
        }

        if (count($selected) === 1) {
            return (string) ($this->available()->firstWhere('id', $selected[0])?->name ?? 'All brands');
        }

        return count($selected).' brands';
    }

    /**
     * Human-readable scope for page subheadings — spells the brands out so a
     * screenshot of the page is self-describing ("which brand is this?" must
     * never be ambiguous). Falls back to a count past 3 names.
     */
    public function description(): string
    {
        $brands = $this->brands();

        if ($brands->isEmpty()) {
            return 'No brands yet';
        }

        if ($this->isAll()) {
            return $brands->count() === 1
                ? (string) $brands->first()->name
                : 'All '.$brands->count().' brands';
        }

        if ($brands->count() <= 3) {
            return $brands->pluck('name')->implode(' + ');
        }

        return $brands->count().' brands';
    }

    /**
     * The timezone to render dates in. Unambiguous only when the scope is a
     * single brand; a mixed scope falls back to the workspace's primary brand
     * and callers should label the timezone explicitly (see Performance/LiveFeed
     * subheadings) rather than implying every row shares it.
     */
    public function timezone(): string
    {
        $single = $this->single();
        if ($single) {
            return $single->timezone ?: 'UTC';
        }

        return $this->available()->first()?->timezone ?: 'UTC';
    }

    /** True when dates on a page may span brands in different timezones. */
    public function hasMixedTimezones(): bool
    {
        return $this->brands()
            ->map(fn (Brand $b) => $b->timezone ?: 'UTC')
            ->unique()
            ->count() > 1;
    }
}

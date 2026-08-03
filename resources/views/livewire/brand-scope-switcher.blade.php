{{--
    Global brand switcher — Agency topbar.

    Multi-select: "All brands", a single brand, or an explicit subset. Every
    page and table in the panel reads the resulting scope (see BrandScope).

    Hidden entirely when the workspace has only one brand — Solo tier, or any
    tier that hasn't added a second brand yet, gets no redundant chrome.

    Styles live in filament/agency/partials/brand-scope-styles.blade.php, which
    the panel injects into <head>. They cannot be pushed from here: a Livewire
    component renders after the layout's stacks are already flushed, and a
    <style> sibling would break the single-root-element requirement.
--}}
<div class="fi-brand-scope-root">
    @if ($this->shouldRender)
        @php
            $brands = $this->brands;
            $isAll = $this->selected === [];
        @endphp

        <div
            class="fi-brand-scope"
            x-data="{ open: @entangle('open') }"
            x-on:keydown.escape.window="open = false"
            x-on:click.outside="open = false"
        >
            <button
                type="button"
                class="fi-brand-scope-trigger"
                x-on:click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="true"
                title="Choose which brand's data every page shows"
            >
                <svg class="fi-brand-scope-icon" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path d="M10 2a1 1 0 0 1 .7.29l6 6A1 1 0 0 1 17 9v8a1 1 0 0 1-1 1h-4v-5H8v5H4a1 1 0 0 1-1-1V9a1 1 0 0 1 .3-.71l6-6A1 1 0 0 1 10 2Z" />
                </svg>
                <span class="fi-brand-scope-label">{{ $this->label }}</span>
                <svg class="fi-brand-scope-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" />
                </svg>
            </button>

            <div class="fi-brand-scope-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                <div class="fi-brand-scope-panel-head">Showing data for</div>

                <button
                    type="button"
                    class="fi-brand-scope-option @if ($isAll) is-active @endif"
                    wire:click="selectAll"
                >
                    <span class="fi-brand-scope-check" aria-hidden="true">@if ($isAll)&check;@endif</span>
                    <span class="fi-brand-scope-name">All brands</span>
                    <span class="fi-brand-scope-count">{{ $brands->count() }}</span>
                </button>

                <div class="fi-brand-scope-divider"></div>

                @foreach ($brands as $b)
                    @php $checked = $this->isChecked($b->id); @endphp
                    <div class="fi-brand-scope-row" wire:key="scope-row-{{ $b->id }}">
                        {{-- Checkbox area: add/remove this brand from the selection. --}}
                        <button
                            type="button"
                            class="fi-brand-scope-option @if ($checked) is-active @endif"
                            wire:click="toggleBrand({{ $b->id }})"
                        >
                            <span class="fi-brand-scope-check" aria-hidden="true">@if ($checked)&check;@endif</span>
                            <span class="fi-brand-scope-name">{{ $b->name }}</span>
                        </button>

                        {{-- One-click "just this brand" — the most common intent,
                             and the shortest path to an unambiguous single view. --}}
                        <button
                            type="button"
                            class="fi-brand-scope-solo"
                            wire:click="showOnly({{ $b->id }})"
                            title="Show only {{ $b->name }}"
                        >Only</button>
                    </div>
                @endforeach

                <div class="fi-brand-scope-foot">
                    Applies to every page — drafts, schedule, live feed and performance.
                </div>
            </div>
        </div>
    @endif
</div>

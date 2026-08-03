{{--
    Styles for the global brand switcher (livewire/brand-scope-switcher).

    Injected into <head> by AgencyPanelProvider rather than pushed from the
    component: a Livewire component renders after the layout's stacks have been
    flushed, so @push from there silently produces nothing.

    Colours come from Filament's CSS custom properties so the control tracks the
    panel theme (EIAAW deep teal #11766A maps to --primary-*) and dark mode
    without hardcoding hexes.
--}}
<style>
    [x-cloak] { display: none !important; }

    .fi-brand-scope { position: relative; display: inline-flex; }

    .fi-brand-scope-trigger {
        display: inline-flex;
        align-items: center;
        gap: .4rem;
        height: 2.25rem;
        padding: 0 .625rem;
        border: 1px solid rgb(var(--gray-300) / 1);
        border-radius: .5rem;
        background: rgb(var(--gray-50) / 1);
        font-size: .8125rem;
        font-weight: 500;
        line-height: 1;
        color: rgb(var(--gray-700) / 1);
        cursor: pointer;
        transition: background-color .15s, border-color .15s;
    }
    .fi-brand-scope-trigger:hover {
        background: rgb(var(--gray-100) / 1);
        border-color: rgb(var(--gray-400) / 1);
    }
    .fi-brand-scope-icon { width: 1rem; height: 1rem; color: rgb(var(--primary-600) / 1); flex: none; }
    .fi-brand-scope-caret { width: .875rem; height: .875rem; opacity: .5; flex: none; }
    .fi-brand-scope-label { max-width: 12rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .fi-brand-scope-panel {
        position: absolute;
        top: calc(100% + .375rem);
        inset-inline-start: 0;
        z-index: 50;
        min-width: 16rem;
        max-height: 22rem;
        overflow-y: auto;
        padding: .375rem;
        border: 1px solid rgb(var(--gray-200) / 1);
        border-radius: .625rem;
        background: white;
        box-shadow: 0 10px 25px -5px rgb(0 0 0 / .12), 0 4px 10px -6px rgb(0 0 0 / .1);
    }

    .fi-brand-scope-panel-head,
    .fi-brand-scope-foot {
        padding: .375rem .5rem;
        font-size: .6875rem;
        font-weight: 600;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: rgb(var(--gray-400) / 1);
    }
    .fi-brand-scope-foot {
        text-transform: none;
        letter-spacing: 0;
        font-weight: 400;
        border-top: 1px solid rgb(var(--gray-100) / 1);
        margin-top: .25rem;
        padding-top: .5rem;
    }

    .fi-brand-scope-row { display: flex; align-items: stretch; gap: .25rem; }
    .fi-brand-scope-row .fi-brand-scope-option { flex: 1 1 auto; }

    .fi-brand-scope-option {
        display: flex;
        align-items: center;
        gap: .5rem;
        width: 100%;
        padding: .4375rem .5rem;
        border: 0;
        border-radius: .375rem;
        background: transparent;
        font-size: .8125rem;
        color: rgb(var(--gray-700) / 1);
        text-align: start;
        cursor: pointer;
    }
    .fi-brand-scope-option:hover { background: rgb(var(--gray-100) / 1); }
    .fi-brand-scope-option.is-active { color: rgb(var(--primary-700) / 1); font-weight: 600; }

    .fi-brand-scope-check {
        flex: none;
        width: 1rem;
        height: 1rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: .25rem;
        border: 1px solid rgb(var(--gray-300) / 1);
        font-size: .75rem;
        line-height: 1;
    }
    .fi-brand-scope-option.is-active .fi-brand-scope-check {
        background: rgb(var(--primary-600) / 1);
        border-color: rgb(var(--primary-600) / 1);
        color: white;
    }

    .fi-brand-scope-name { flex: 1 1 auto; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .fi-brand-scope-count { flex: none; font-size: .6875rem; color: rgb(var(--gray-400) / 1); }

    .fi-brand-scope-solo {
        flex: none;
        align-self: center;
        padding: .1875rem .4375rem;
        border: 1px solid transparent;
        border-radius: .3125rem;
        background: transparent;
        font-size: .6875rem;
        font-weight: 600;
        color: rgb(var(--gray-400) / 1);
        cursor: pointer;
        opacity: 0;
        transition: opacity .12s;
    }
    .fi-brand-scope-row:hover .fi-brand-scope-solo { opacity: 1; }
    .fi-brand-scope-solo:hover {
        border-color: rgb(var(--primary-200) / 1);
        background: rgb(var(--primary-50) / 1);
        color: rgb(var(--primary-700) / 1);
    }
    /* Keyboard users never trigger :hover — the affordance must stay reachable. */
    .fi-brand-scope-solo:focus-visible { opacity: 1; }

    .fi-brand-scope-divider { height: 1px; background: rgb(var(--gray-100) / 1); margin: .25rem 0; }

    /* Scope banner shown on pages whose data spans more than one brand. */
    .fi-brand-scope-banner {
        display: flex;
        align-items: center;
        gap: .5rem;
        margin-bottom: 1rem;
        padding: .5rem .75rem;
        border: 1px solid rgb(var(--primary-200) / 1);
        border-radius: .5rem;
        background: rgb(var(--primary-50) / 1);
        font-size: .8125rem;
        color: rgb(var(--primary-900) / 1);
    }
    .fi-brand-scope-banner strong { font-weight: 600; }

    @media (max-width: 640px) {
        .fi-brand-scope-label { max-width: 7rem; }
        .fi-brand-scope-solo { opacity: 1; }
    }

    .dark .fi-brand-scope-trigger { background: rgb(var(--gray-800) / 1); border-color: rgb(var(--gray-700) / 1); color: rgb(var(--gray-200) / 1); }
    .dark .fi-brand-scope-trigger:hover { background: rgb(var(--gray-700) / 1); }
    .dark .fi-brand-scope-panel { background: rgb(var(--gray-900) / 1); border-color: rgb(var(--gray-700) / 1); }
    .dark .fi-brand-scope-option { color: rgb(var(--gray-200) / 1); }
    .dark .fi-brand-scope-option:hover { background: rgb(var(--gray-800) / 1); }
    .dark .fi-brand-scope-foot { border-color: rgb(var(--gray-800) / 1); }
    .dark .fi-brand-scope-divider { background: rgb(var(--gray-800) / 1); }
    .dark .fi-brand-scope-banner { background: rgb(var(--primary-950) / 1); border-color: rgb(var(--primary-800) / 1); color: rgb(var(--primary-100) / 1); }
</style>

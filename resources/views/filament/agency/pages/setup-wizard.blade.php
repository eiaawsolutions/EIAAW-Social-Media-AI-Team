<x-filament-panels::page>
    @push('styles')
        <style>
            :root {
                --eiaaw-bg: #FAF7F2;
                --eiaaw-bg-warm: #F3EDE0;
                --eiaaw-ink: #0F1A1D;
                --eiaaw-ink-2: #2A3438;
                --eiaaw-mute: #6B7A7F;
                --eiaaw-line: #D9CFBC;
                --eiaaw-line-soft: #E8DFCC;
                --eiaaw-primary: #1FA896;
                --eiaaw-primary-dark: #11766A;
                --eiaaw-primary-tint: #E5F4F1;
                --eiaaw-danger: #B4412B;
                --eiaaw-mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
            }
            .wizard-shell {
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line);
                border-radius: 16px;
                padding: 32px;
            }
            .wizard-progress {
                display: flex; align-items: baseline; gap: 16px;
                margin-bottom: 24px;
            }
            .wizard-progress-num {
                font-weight: 600; font-size: 56px;
                letter-spacing: -0.04em; color: var(--eiaaw-ink);
                line-height: 1;
            }
            .wizard-progress-meta {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: 0.12em;
                text-transform: uppercase; color: var(--eiaaw-mute);
            }
            .wizard-progress-bar {
                height: 6px; background: var(--eiaaw-line-soft); border-radius: 999px;
                overflow: hidden; margin-bottom: 28px;
            }
            .wizard-progress-bar > span {
                display: block; height: 100%; background: var(--eiaaw-primary);
                transition: width .6s cubic-bezier(.2,.7,.2,1);
            }
            .wizard-stage {
                display: grid;
                grid-template-columns: 28px 1fr auto;
                gap: 18px; align-items: start;
                padding: 18px 20px;
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-radius: 12px;
                margin-bottom: 10px;
                transition: transform .25s cubic-bezier(.2,.7,.2,1), border-color .25s;
            }
            .wizard-stage-done {
                background: var(--eiaaw-primary-tint);
                border-color: var(--eiaaw-primary);
            }
            .wizard-stage-blocked { opacity: .55; }
            .wizard-stage-todo {
                border-color: var(--eiaaw-ink);
                box-shadow: 0 8px 20px -10px rgba(15,26,29,.18);
            }
            .wizard-stage-todo:hover { transform: translateY(-1px); }
            .wizard-stage-icon {
                font-family: var(--eiaaw-mono);
                font-size: 18px; line-height: 24px;
                color: var(--eiaaw-mute);
                width: 28px; height: 28px;
                display: flex; align-items: center; justify-content: center;
                border-radius: 999px; background: white;
                border: 1px solid var(--eiaaw-line);
                margin-top: 2px;
            }
            .wizard-stage-done .wizard-stage-icon {
                color: white; background: var(--eiaaw-primary); border-color: var(--eiaaw-primary);
            }
            .wizard-stage-todo .wizard-stage-icon {
                color: white; background: var(--eiaaw-ink); border-color: var(--eiaaw-ink);
            }
            .wizard-stage-num {
                font-family: var(--eiaaw-mono); font-size: 11px;
                letter-spacing: 0.12em; text-transform: uppercase;
                color: var(--eiaaw-mute); margin-bottom: 4px;
            }
            .wizard-stage-label {
                font-weight: 500; font-size: 16px;
                letter-spacing: -0.01em; color: var(--eiaaw-ink);
                line-height: 1.3;
            }
            .wizard-stage-desc {
                font-size: 13px; line-height: 1.55;
                color: var(--eiaaw-ink-2); margin-top: 6px;
                max-width: 60ch;
            }
            .wizard-stage-evidence {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: .04em;
                color: var(--eiaaw-primary-dark);
                margin-top: 8px;
            }
            .wizard-stage-blocked-by {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: .04em;
                color: var(--eiaaw-mute);
                margin-top: 8px;
            }
            .wizard-cta {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 10px 18px; border-radius: 999px;
                background: var(--eiaaw-ink); color: var(--eiaaw-bg);
                font-size: 13px; font-weight: 500;
                text-decoration: none; cursor: pointer;
                border: 1px solid var(--eiaaw-ink);
                transition: all .25s cubic-bezier(.2,.7,.2,1);
                white-space: nowrap;
            }
            .wizard-cta:hover { background: var(--eiaaw-primary-dark); border-color: var(--eiaaw-primary-dark); transform: translateY(-1px); }
            .wizard-cta-ghost {
                background: transparent; color: var(--eiaaw-ink);
            }
            .wizard-cta-ghost:hover { background: var(--eiaaw-ink); color: var(--eiaaw-bg); }
            .wizard-cta-disabled {
                opacity: .35; cursor: not-allowed; pointer-events: none;
            }
            .wizard-empty {
                text-align: center; padding: 64px 24px;
            }
            .wizard-empty h3 {
                font-size: 28px; font-weight: 500;
                letter-spacing: -0.02em; color: var(--eiaaw-ink);
                max-width: 30ch; margin: 0 auto 12px;
            }
            .wizard-empty p {
                font-size: 15px; line-height: 1.6;
                color: var(--eiaaw-ink-2);
                max-width: 50ch; margin: 0 auto 28px;
            }
            .wizard-brand-switcher {
                display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap;
            }
            .wizard-brand-pill {
                padding: 6px 14px; border-radius: 999px;
                background: white; border: 1px solid var(--eiaaw-line);
                font-size: 12px; color: var(--eiaaw-ink-2);
                text-decoration: none;
                font-family: var(--eiaaw-mono); letter-spacing: .04em;
                transition: all .2s;
            }
            .wizard-brand-pill:hover { border-color: var(--eiaaw-ink); color: var(--eiaaw-ink); }
            .wizard-brand-pill-active {
                background: var(--eiaaw-ink); color: var(--eiaaw-bg); border-color: var(--eiaaw-ink);
            }
        </style>
    @endpush

    @if (! $this->workspaceReadiness() || ! $this->workspaceReadiness()->hasAnyBrand)
        {{-- Empty state: no brands yet --}}
        <div class="wizard-shell">
            <div class="wizard-empty">
                <h3>You haven't added a brand yet.</h3>
                <p>EIAAW Social Media Team works one brand at a time. Each brand gets its own voice synthesis, content calendar, and audit trail. You can add more brands once your first one is running.</p>
                <a href="{{ url('/agency/brands?action=create') }}" class="wizard-cta">
                    Add your first brand
                    <span aria-hidden="true">→</span>
                </a>
            </div>
        </div>
    @else
        <div class="wizard-shell">
            {{-- Brand switcher --}}
            @if ($this->workspaceReadiness()->brandCount > 1)
                <div class="wizard-brand-switcher">
                    @foreach ($this->workspaceReadiness()->brands as $br)
                        <a href="{{ url('/agency/setup-wizard?brand=' . $br->brand->id) }}"
                           class="wizard-brand-pill {{ $br->brand->id === $this->brandReadiness()?->brand->id ? 'wizard-brand-pill-active' : '' }}">
                            {{ $br->brand->name }} · {{ $br->percent }}%
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Progress hero --}}
            <div class="wizard-progress">
                <div class="wizard-progress-num">{{ $this->brandReadiness()->percent }}%</div>
                <div>
                    <div class="wizard-progress-meta">
                        {{ $this->brandReadiness()->doneStages }} / {{ $this->brandReadiness()->totalStages }} stages complete
                    </div>
                    <div style="font-size: 14px; color: var(--eiaaw-ink-2); margin-top: 4px;">
                        @if ($this->brandReadiness()->isComplete)
                            Every stage complete. The agents have everything they need to run.
                        @else
                            Next: <strong>{{ $this->brandReadiness()->nextActionable?->label ?? '—' }}</strong>
                        @endif
                    </div>
                </div>
            </div>

            <div class="wizard-progress-bar">
                <span style="width: {{ $this->brandReadiness()->percent }}%"></span>
            </div>

            {{-- Stage list --}}
            @foreach ($this->brandReadiness()->stages as $stage)
                <div class="wizard-stage {{ $this->statusClass($stage->status()) }}"
                     id="stage-{{ $stage->id }}"
                     @if ($focus === $stage->id) data-focused="true" @endif>
                    <div class="wizard-stage-icon">{{ $this->statusIcon($stage->status()) }}</div>
                    <div>
                        <div class="wizard-stage-num">Stage {{ str_pad((string) $stage->order, 2, '0', STR_PAD_LEFT) }}{{ $stage->skippable ? ' · optional' : '' }}</div>
                        <div class="wizard-stage-label">{{ $stage->label }}</div>
                        <div class="wizard-stage-desc">{{ $stage->description }}</div>

                        @if ($stage->evidence)
                            <div class="wizard-stage-evidence">→ {{ $stage->evidence }}</div>
                        @elseif ($stage->blockedBy)
                            <div class="wizard-stage-blocked-by">⤳ Complete <strong>{{ \Illuminate\Support\Str::headline($stage->blockedBy) }}</strong> first.</div>
                        @endif
                    </div>
                    <div>
                        @if ($stage->done)
                            <a href="{{ $stage->ctaUrl }}" class="wizard-cta wizard-cta-ghost">{{ $stage->ctaLabel }}</a>
                        @elseif ($stage->blockedBy)
                            <span class="wizard-cta wizard-cta-disabled">{{ $stage->ctaLabel }}</span>
                        @elseif (in_array($stage->id, ['brand_style', 'calendar_generated', 'first_draft_passed', 'post_scheduled']))
                            {{-- Stages with one-click agent triggers --}}
                            <button type="button"
                                    class="wizard-cta"
                                    wire:click="runStage('{{ $stage->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="runStage">
                                <span wire:loading.remove wire:target="runStage('{{ $stage->id }}')">
                                    {{ $stage->ctaLabel }}
                                    <span aria-hidden="true">→</span>
                                </span>
                                <span wire:loading wire:target="runStage('{{ $stage->id }}')">
                                    Working…
                                </span>
                            </button>
                        @else
                            {{-- Stages where the user takes action elsewhere (forms / OAuth / uploads) --}}
                            <a href="{{ $stage->ctaUrl }}" class="wizard-cta">
                                {{ $stage->ctaLabel }}
                                <span aria-hidden="true">→</span>
                            </a>
                        @endif
                    </div>
                </div>
            @endforeach

            <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--eiaaw-line-soft); font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute);">
                Readiness re-checks every 30 seconds · cached per brand · every claim is a real DB query
            </div>
        </div>

        @if ($focus)
            @push('scripts')
                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        const el = document.querySelector('[data-focused="true"]');
                        if (el) el.scrollIntoView({behavior: 'smooth', block: 'center'});
                    });
                </script>
            @endpush
        @endif
    @endif
</x-filament-panels::page>

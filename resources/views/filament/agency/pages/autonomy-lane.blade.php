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
                --eiaaw-mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;

                --lane-green: #1FA896;
                --lane-green-dark: #11766A;
                --lane-green-tint: #E5F4F1;
                --lane-amber: #C68B00;
                --lane-amber-dark: #8E6300;
                --lane-amber-tint: #FAF1DC;
                --lane-red: #B4412B;
                --lane-red-dark: #7E2C1B;
                --lane-red-tint: #F8E2DC;
            }
            .autonomy-shell {
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line);
                border-radius: 16px;
                padding: 32px;
            }
            .autonomy-meta {
                font-family: var(--eiaaw-mono);
                font-size: 11px;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
                margin-bottom: 8px;
            }
            .autonomy-heading {
                font-size: 22px; font-weight: 500;
                letter-spacing: -0.02em; color: var(--eiaaw-ink);
                margin: 0 0 8px;
            }
            .autonomy-lead {
                font-size: 14px; line-height: 1.55;
                color: var(--eiaaw-ink-2);
                max-width: 65ch;
                margin: 0 0 24px;
            }
            .lane-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
                gap: 14px;
            }
            .lane-card {
                position: relative;
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-radius: 14px;
                padding: 22px 22px 24px;
                cursor: pointer;
                transition: transform .25s cubic-bezier(.2,.7,.2,1), border-color .2s, box-shadow .25s;
                text-align: left;
                width: 100%;
                font-family: inherit;
            }
            .lane-card:hover { transform: translateY(-2px); box-shadow: 0 12px 28px -16px rgba(15,26,29,.18); }
            .lane-card[disabled] { opacity: .55; cursor: not-allowed; }
            .lane-card .lane-pill {
                display: inline-block;
                font-family: var(--eiaaw-mono);
                font-size: 10.5px; letter-spacing: .12em;
                text-transform: uppercase;
                padding: 3px 9px; border-radius: 999px;
                margin-bottom: 12px;
            }
            .lane-card[data-lane=green] .lane-pill { background: var(--lane-green-tint); color: var(--lane-green-dark); }
            .lane-card[data-lane=amber] .lane-pill { background: var(--lane-amber-tint); color: var(--lane-amber-dark); }
            .lane-card[data-lane=red]   .lane-pill { background: var(--lane-red-tint); color: var(--lane-red-dark); }

            .lane-card[data-current=true] {
                border-color: var(--eiaaw-ink);
                box-shadow: 0 8px 24px -10px rgba(15,26,29,.22);
            }
            .lane-card[data-current=true][data-lane=green] { border-color: var(--lane-green); }
            .lane-card[data-current=true][data-lane=amber] { border-color: var(--lane-amber); }
            .lane-card[data-current=true][data-lane=red]   { border-color: var(--lane-red); }

            .lane-card h3 {
                font-size: 17px; font-weight: 600;
                letter-spacing: -0.01em; color: var(--eiaaw-ink);
                margin: 0 0 6px;
            }
            .lane-card p {
                font-size: 13px; line-height: 1.55;
                color: var(--eiaaw-ink-2); margin: 0;
            }
            .lane-card ul {
                margin: 12px 0 0; padding: 0;
                list-style: none;
                font-size: 12.5px; color: var(--eiaaw-ink-2);
                line-height: 1.6;
            }
            .lane-card ul li::before {
                content: '· ';
                color: var(--eiaaw-mute);
                font-weight: 700;
            }
            .lane-current-badge {
                position: absolute;
                top: 14px; right: 14px;
                font-family: var(--eiaaw-mono);
                font-size: 10px; letter-spacing: .12em;
                text-transform: uppercase;
                background: var(--eiaaw-ink);
                color: var(--eiaaw-bg);
                padding: 3px 9px; border-radius: 999px;
            }
        </style>
    @endpush

    @php
        $brand = $this->resolveBrand();
        $current = $this->currentLane;
    @endphp

    <div class="autonomy-shell">
        @if (! $brand)
            <div class="autonomy-meta">No brand</div>
            <h2 class="autonomy-heading">Create a brand first</h2>
            <p class="autonomy-lead">
                Autonomy is decided per-brand. Add your first brand on the Brands page, then return here.
            </p>
            <a href="{{ url('/agency/brands?action=create') }}" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px; border-radius: 999px; background: var(--eiaaw-ink); color: var(--eiaaw-bg); font-size: 13px; font-weight: 500; text-decoration: none; border: 1px solid var(--eiaaw-ink);">
                Add a brand
                <span aria-hidden="true">→</span>
            </a>
        @else
            <div class="autonomy-meta">{{ $brand->name }} · Default autonomy lane</div>
            <h2 class="autonomy-heading">
                @if ($current)
                    Default lane is <strong style="text-transform: capitalize;">{{ $current }}</strong>
                @else
                    Pick a default lane
                @endif
            </h2>
            <p class="autonomy-lead">
                The lane decides how a draft moves from <strong>Writer</strong> → <strong>Scheduled</strong>.
                You can override per-platform later (e.g. <em>green on Threads, red on LinkedIn</em>).
                Compliance still runs on every draft regardless of lane — autonomy controls the human-in-the-loop step,
                not the brand-voice or factual-grounding checks.
            </p>

            <div class="lane-grid">
                @php
                    $lanes = [
                        'green' => [
                            'pill' => 'Auto · 0 humans',
                            'title' => 'Green — auto-publish',
                            'lead' => 'Compliance passes → straight to scheduled. Use when you trust the corpus + brand voice and want maximum cadence.',
                            'fits' => [
                                'Threads, X, Twitter — fast cadence platforms',
                                'Brands with mature corpus (50+ historical posts)',
                                'Internal experiments where speed > caution',
                            ],
                        ],
                        'amber' => [
                            'pill' => 'Approve · 1 human',
                            'title' => 'Amber — 1 human approves',
                            'lead' => 'Compliance passes → goes to drafts queue → 1 reviewer clicks Approve. The default for most agencies.',
                            'fits' => [
                                'Most B2B brands and client work',
                                'Instagram, Facebook, LinkedIn',
                                'New brands still establishing voice',
                            ],
                        ],
                        'red' => [
                            'pill' => 'Approve · 2 humans',
                            'title' => 'Red — 2 humans approve',
                            'lead' => 'Compliance passes → 2 separate reviewers must click Approve. Use for regulated, sensitive, or executive content.',
                            'fits' => [
                                'Regulated industries (BFSI, healthcare, legal)',
                                'CEO / executive social posts',
                                'Crisis and PR-sensitive moments',
                            ],
                        ],
                    ];
                @endphp

                @foreach ($lanes as $lane => $info)
                    <button type="button"
                            class="lane-card"
                            data-lane="{{ $lane }}"
                            data-current="{{ $current === $lane ? 'true' : 'false' }}"
                            wire:click="pickLane('{{ $lane }}')"
                            wire:loading.attr="disabled"
                            wire:target="pickLane">
                        @if ($current === $lane)
                            <span class="lane-current-badge">Current</span>
                        @endif
                        <span class="lane-pill">{{ $info['pill'] }}</span>
                        <h3>{{ $info['title'] }}</h3>
                        <p>{{ $info['lead'] }}</p>
                        <ul>
                            @foreach ($info['fits'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    </button>
                @endforeach
            </div>

            <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--eiaaw-line-soft); font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute);">
                Per-platform overrides ship in v1.1 · Compliance runs on every lane
            </div>
        @endif
    </div>
</x-filament-panels::page>

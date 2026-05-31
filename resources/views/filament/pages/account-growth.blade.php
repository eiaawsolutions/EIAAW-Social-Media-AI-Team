<x-filament-panels::page>
    @push('styles')
        <style>
            .ag-wrap { display: grid; gap: 22px; }
            .ag-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
            .ag-muted { color: var(--gray-500, #6b7280); }

            .ag-card {
                background: var(--gray-50, #f9fafb);
                border: 1px solid var(--gray-200, #e5e7eb);
                border-radius: 16px;
                padding: 22px 24px;
            }
            .dark .ag-card { background: rgba(255,255,255,.02); border-color: rgba(255,255,255,.08); }

            .ag-head { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 18px; }
            .ag-head h3 { font-size: 14px; font-weight: 800; letter-spacing: .02em; margin: 0; }
            .ag-total {
                font-size: 30px; font-weight: 800; line-height: 1;
                font-variant-numeric: tabular-nums; letter-spacing: -.01em;
            }
            .ag-total-label { font-size: 11px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--gray-500,#6b7280); }

            /* Per-network number tiles — the coloured boxes from the reference. */
            .ag-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 10px; margin-bottom: 18px; }
            .ag-tile {
                border-radius: 12px; padding: 12px 14px; color: #fff;
                display: flex; flex-direction: column; gap: 2px; min-height: 76px;
                box-shadow: 0 1px 2px rgba(0,0,0,.06);
            }
            .ag-tile-num { font-size: 24px; font-weight: 800; line-height: 1.1; font-variant-numeric: tabular-nums; }
            .ag-tile-net { font-size: 12px; font-weight: 600; opacity: .92; }
            .ag-tile-chg { font-size: 11px; font-weight: 700; opacity: .95; margin-top: 2px; }
            .ag-tile-na { color: var(--gray-600,#4b5563); background: var(--gray-100,#f3f4f6); border: 1px dashed var(--gray-300,#d1d5db); box-shadow: none; }
            .dark .ag-tile-na { color: var(--gray-300,#d1d5db); background: rgba(255,255,255,.03); border-color: rgba(255,255,255,.12); }
            .ag-tile-na .ag-tile-num { font-size: 14px; font-weight: 700; }
            .ag-na-note { font-size: 10px; opacity: .8; line-height: 1.25; margin-top: 2px; }

            .ag-chart-box { position: relative; height: 300px; }
            .ag-empty {
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                gap: 8px; height: 300px; text-align: center; color: var(--gray-500,#6b7280);
                border: 1px dashed var(--gray-300,#d1d5db); border-radius: 12px;
            }
            .dark .ag-empty { border-color: rgba(255,255,255,.12); }

            .ag-banner {
                background: rgba(17,118,106,.08); border: 1px solid rgba(17,118,106,.3);
                border-radius: 12px; padding: 16px 18px; font-size: 13px;
            }
            .dark .ag-banner { background: rgba(17,118,106,.14); border-color: rgba(17,118,106,.4); }
            .ag-banner-warn { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.35); color: #92400e; }
            .dark .ag-banner-warn { color: #fde68a; }

            .ag-legend { display: flex; flex-wrap: wrap; gap: 12px 18px; margin-top: 14px; font-size: 12px; }
            .ag-legend-item { display: inline-flex; align-items: center; gap: 6px; }
            .ag-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; }

            .ag-pill {
                display: inline-block; font-size: 10px; font-weight: 700; letter-spacing: .04em;
                padding: 2px 8px; border-radius: 999px;
                background: rgba(17,118,106,.12); color: #11766A;
            }
            .dark .ag-pill { background: rgba(17,118,106,.25); color: #5eead4; }
        </style>
    @endpush

    @php($b = $this->board())

    <div class="ag-wrap" wire:poll.60s>

        {{-- Not configured / not mapped — teach the next action (Simplicity principle) --}}
        @if (! $b['metricool_configured'])
            <div class="ag-banner ag-banner-warn">
                <strong>Metricool isn’t wired in this environment.</strong>
                The shared account token (<code>METRICOOL_API_TOKEN</code> → Infisical handle) and
                <code>METRICOOL_USER_ID</code> aren’t resolved here, so there’s no live account data to read.
            </div>
        @elseif ($b['brand'] === null)
            <div class="ag-banner ag-banner-warn">
                <strong>EIAAW’s own brand isn’t mapped to Metricool yet.</strong>
                Map the internal brand’s <code>blogId</code> in the
                <a href="{{ $b['onboarding_url'] }}" class="font-semibold underline">Platform onboarding</a>
                console, then this dashboard fills in automatically.
            </div>
        @endif

        @if ($b['brand'] !== null && $b['growth'] !== null)
            @php($g = $b['growth'])

            {{-- Toolbar: brand + window picker --}}
            <div class="ag-toolbar">
                <span class="text-sm font-semibold">{{ $b['brand']['name'] }}</span>
                <span class="ag-pill">blogId {{ $b['brand']['blog_id'] }}</span>
                <span class="text-xs ag-muted">·</span>
                <label for="ag-window" class="text-sm font-medium ag-muted">Window</label>
                <select
                    id="ag-window"
                    wire:model.live="window"
                    class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5 text-sm"
                >
                    @foreach ($this->windowOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <span class="text-xs ag-muted">
                    {{ \Illuminate\Support\Carbon::parse($g['from'])->format('M j, Y') }}
                    – {{ \Illuminate\Support\Carbon::parse($g['to'])->format('M j, Y') }}
                    · {{ $b['brand']['timezone'] }}
                </span>
            </div>

            {{-- The two dimensions: Followers (stock) then Impressions (flow) --}}
            @foreach (['followers' => 'net new over window', 'impressions' => 'total over window'] as $dimKey => $totalCaption)
                @php($dim = $g[$dimKey])
                <div class="ag-card">
                    <div class="ag-head">
                        <h3>{{ $dim['title'] }}</h3>
                        <div style="margin-left:auto; text-align:right;">
                            <div class="ag-total">{{ number_format($dim['total']) }}</div>
                            <div class="ag-total-label">
                                {{ $dimKey === 'followers' ? 'followers across networks' : 'impressions, last ' . $g['window_days'] . ' days' }}
                            </div>
                        </div>
                    </div>

                    {{-- Per-network number tiles (coloured = real data; dashed = honest gap) --}}
                    <div class="ag-tiles">
                        @foreach ($dim['networks'] as $net)
                            @if ($net['status'] === 'ok')
                                <div class="ag-tile" style="background: {{ $net['color'] }};">
                                    <span class="ag-tile-num">{{ number_format($net['headline']) }}</span>
                                    <span class="ag-tile-net">{{ $net['label'] }}</span>
                                    @if ($net['change'] !== null)
                                        <span class="ag-tile-chg">
                                            {{ $net['change'] >= 0 ? '▲ +' : '▼ ' }}{{ number_format($net['change']) }}
                                        </span>
                                    @endif
                                </div>
                            @else
                                <div class="ag-tile ag-tile-na">
                                    <span class="ag-tile-net" style="opacity:1;">{{ $net['label'] }}</span>
                                    <span class="ag-na-note">
                                        @switch($net['status'])
                                            @case('not_available') Not connected / not on plan @break
                                            @case('error') Couldn’t reach Metricool @break
                                            @default No data in this window
                                        @endswitch
                                    </span>
                                </div>
                            @endif
                        @endforeach
                    </div>

                    {{-- Timeseries chart. wire:ignore keeps Livewire from wiping the
                         canvas on the 60s poll (which keeps the tiles/totals above live);
                         the wire:key carries the window so a window change replaces this
                         node and re-fires x-init to rebuild the chart with the new range. --}}
                    @if ($dim['has_data'])
                        <div class="ag-chart-box" wire:ignore wire:key="ag-chart-{{ $dimKey }}-{{ $g['window_days'] }}">
                            <canvas
                                id="ag-chart-{{ $dimKey }}-{{ $g['window_days'] }}"
                                x-data
                                x-init="window.eiaawRenderGrowthChart(
                                    'ag-chart-{{ $dimKey }}-{{ $g['window_days'] }}',
                                    @js($dim['axis']),
                                    @js(collect($dim['networks'])->where('status','ok')->map(fn($n)=>[
                                        'label'=>$n['label'],'color'=>$n['color'],'data'=>$n['series'],
                                    ])->values()),
                                    '{{ $dimKey === 'followers' ? 'line' : 'area' }}'
                                )"
                            ></canvas>
                        </div>
                        <div class="ag-legend">
                            @foreach (collect($dim['networks'])->where('status','ok') as $net)
                                <span class="ag-legend-item">
                                    <span class="ag-dot" style="background: {{ $net['color'] }};"></span>
                                    {{ $net['label'] }}
                                </span>
                            @endforeach
                        </div>
                    @else
                        <div class="ag-empty">
                            <span class="text-sm font-semibold">No {{ strtolower($dim['title']) }} reported in this window</span>
                            <span class="text-xs">Metricool returned no account timeseries for any connected network over the selected range.</span>
                        </div>
                    @endif
                </div>
            @endforeach

            {{-- Honest footer about the platforms Metricool can't report --}}
            @if (count($g['unsupported']) > 0)
                <div class="ag-banner">
                    <strong>Why some platforms aren’t charted:</strong>
                    Metricool’s account-timeline API doesn’t expose follower/impression history for
                    @foreach ($g['unsupported'] as $i => $u){{ $u['label'] }}@if ($i < count($g['unsupported']) - 1), @endif @endforeach.
                    Per-post engagement for those is still tracked on the
                    <span class="ag-muted">Performance</span> page — we show only real readings here, never estimates.
                </div>
            @endif
        @endif

        <p class="text-xs ag-muted">
            Live from Metricool’s account analytics (<code>/stats/timeline</code>), cached ~5&nbsp;min, auto-refreshing every 60&nbsp;s.
            Every figure is a real platform reading. This is EIAAW’s own growth (Phase 1); per-customer views roll out next.
        </p>
    </div>

    {{-- Chart.js + a single render helper, loaded once. wire:ignore keeps Livewire from
         wiping the canvas on poll; the helper destroys+rebuilds any prior chart instance. --}}
    @once
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
                window.__eiaawGrowthCharts = window.__eiaawGrowthCharts || {};
                window.eiaawRenderGrowthChart = function (canvasId, axis, series, mode) {
                    var el = document.getElementById(canvasId);
                    if (!el || typeof Chart === 'undefined') return;

                    if (window.__eiaawGrowthCharts[canvasId]) {
                        window.__eiaawGrowthCharts[canvasId].destroy();
                    }

                    var datasets = series.map(function (s) {
                        return {
                            label: s.label,
                            data: s.data,                 // nulls allowed = honest gaps
                            borderColor: s.color,
                            backgroundColor: mode === 'area' ? (s.color + '22') : s.color,
                            fill: mode === 'area',
                            tension: 0.35,
                            spanGaps: true,               // bridge missing readings, don't plot 0
                            pointRadius: 2,
                            pointHoverRadius: 4,
                            borderWidth: 2,
                        };
                    });

                    window.__eiaawGrowthCharts[canvasId] = new Chart(el.getContext('2d'), {
                        type: 'line',
                        data: { labels: axis, datasets: datasets },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                legend: { display: false },
                                tooltip: { callbacks: {
                                    label: function (c) {
                                        var v = c.parsed.y;
                                        return c.dataset.label + ': ' + (v == null ? '—' : v.toLocaleString());
                                    }
                                } }
                            },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 11 } } },
                                y: { beginAtZero: false, ticks: { font: { size: 11 },
                                    callback: function (v) { return Number(v).toLocaleString(); } } }
                            }
                        }
                    });
                };
            </script>
        @endpush
    @endonce
</x-filament-panels::page>

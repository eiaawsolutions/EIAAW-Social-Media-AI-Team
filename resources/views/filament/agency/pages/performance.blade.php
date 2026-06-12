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
                --eiaaw-mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
            }
            .perf-shell { background: var(--eiaaw-bg); border: 1px solid var(--eiaaw-line); border-radius: 16px; padding: 24px; }
            .perf-window { display: flex; gap: 6px; margin-bottom: 22px; }
            .perf-window button {
                font-family: var(--eiaaw-mono); font-size: 11px;
                letter-spacing: .12em; text-transform: uppercase;
                padding: 6px 12px; border-radius: 999px; cursor: pointer;
                background: white; color: var(--eiaaw-ink-2);
                border: 1px solid var(--eiaaw-line);
            }
            .perf-window button.active { background: var(--eiaaw-ink); color: var(--eiaaw-bg); border-color: var(--eiaaw-ink); }
            .perf-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 18px; }
            .perf-tile { background: white; border: 1px solid var(--eiaaw-line); border-radius: 12px; padding: 14px 16px; }
            .perf-tile-label { font-family: var(--eiaaw-mono); font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute); margin-bottom: 6px; }
            .perf-tile-num { font-size: 26px; font-weight: 600; letter-spacing: -0.02em; color: var(--eiaaw-ink); line-height: 1; }
            .perf-tile-sub { font-size: 11px; color: var(--eiaaw-mute); margin-top: 4px; font-family: var(--eiaaw-mono); }
            .perf-section-title { font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute); margin: 24px 0 10px; }
            .perf-table { width: 100%; border-collapse: collapse; background: white; border: 1px solid var(--eiaaw-line); border-radius: 12px; overflow: hidden; }
            .perf-table th { font-family: var(--eiaaw-mono); font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute); text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--eiaaw-line-soft); background: var(--eiaaw-bg); }
            .perf-table td { padding: 10px 14px; font-size: 13px; color: var(--eiaaw-ink-2); border-bottom: 1px solid var(--eiaaw-line-soft); }
            .perf-table tr:last-child td { border-bottom: 0; }
            .perf-table td.num { font-family: var(--eiaaw-mono); text-align: right; }
            .perf-empty { font-size: 13.5px; color: var(--eiaaw-mute); text-align: center; padding: 28px 12px; background: white; border: 1px dashed var(--eiaaw-line); border-radius: 12px; }
            .perf-cost-row { display: flex; gap: 18px; align-items: baseline; padding-top: 18px; border-top: 1px solid var(--eiaaw-line-soft); margin-top: 18px; font-family: var(--eiaaw-mono); font-size: 11.5px; color: var(--eiaaw-mute); letter-spacing: .04em; }
            .perf-cost-row strong { color: var(--eiaaw-ink-2); font-weight: 600; }

            /* Account growth section (followers + impressions over time, per network) */
            .perf-growth { margin-bottom: 26px; }
            .perf-growth-block { margin-bottom: 18px; }
            .perf-growth-head { display: flex; align-items: baseline; gap: 12px; margin-bottom: 10px; }
            .perf-growth-head h4 { margin: 0; font-size: 13px; font-weight: 700; color: var(--eiaaw-ink); }
            .perf-growth-total { margin-left: auto; text-align: right; }
            .perf-growth-total .n { font-size: 22px; font-weight: 700; color: var(--eiaaw-ink); line-height: 1; font-variant-numeric: tabular-nums; }
            .perf-growth-total .l { font-family: var(--eiaaw-mono); font-size: 10px; letter-spacing: .1em; text-transform: uppercase; color: var(--eiaaw-mute); }
            .perf-net-tiles { display: grid; grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); gap: 8px; margin-bottom: 12px; }
            .perf-net { border-radius: 10px; padding: 10px 12px; color: #fff; min-height: 64px; display: flex; flex-direction: column; gap: 2px; }
            .perf-net .num { font-size: 20px; font-weight: 700; line-height: 1.1; font-variant-numeric: tabular-nums; }
            .perf-net .net { font-size: 11px; font-weight: 600; opacity: .92; }
            .perf-net .chg { font-size: 10px; font-weight: 700; opacity: .95; }
            .perf-net-na { color: var(--eiaaw-mute); background: white; border: 1px dashed var(--eiaaw-line); }
            .perf-net-na .net { opacity: 1; color: var(--eiaaw-ink-2); }
            .perf-net-na .na { font-size: 9.5px; line-height: 1.25; opacity: .85; margin-top: 2px; }
            .perf-chart-box { position: relative; height: 240px; background: white; border: 1px solid var(--eiaaw-line); border-radius: 12px; padding: 10px; }
            .perf-growth-note { font-family: var(--eiaaw-mono); font-size: 10.5px; letter-spacing: .04em; color: var(--eiaaw-mute); margin-top: 8px; }
            /* Calm, on-brand outage notice (warm paper, NOT a red/amber alert) shown
               when Metricool analytics is unreachable — reassures rather than alarms. */
            .perf-growth-outage { display: flex; gap: 12px; align-items: flex-start; background: white; border: 1px dashed var(--eiaaw-line); border-radius: 12px; padding: 16px 18px; }
            .perf-growth-outage .ico { font-size: 18px; line-height: 1.3; color: #11766A; flex: none; }
            .perf-growth-outage .msg { font-size: 13px; line-height: 1.5; color: var(--eiaaw-ink-2); }
            .perf-growth-outage .msg strong { display: block; color: var(--eiaaw-ink); font-weight: 600; margin-bottom: 2px; }
            .perf-growth-outage .msg em { font-style: normal; font-weight: 600; color: var(--eiaaw-ink-2); }
        </style>
    @endpush

    @php
        $g = $this->growth();
        $gs = $this->growthStrategy();
        $s = $this->summary();
        $platforms = $this->perPlatform();
        $top = $this->topPosts();
        $hasMetrics = $s['impressions'] > 0 || $s['likes'] > 0 || $s['comments'] > 0;
    @endphp

    <div class="perf-shell">
        <div class="perf-window">
            <button type="button" wire:click="setWindow(7)"  class="{{ $this->window === 7  ? 'active' : '' }}">7 days</button>
            <button type="button" wire:click="setWindow(30)" class="{{ $this->window === 30 ? 'active' : '' }}">30 days</button>
            <button type="button" wire:click="setWindow(90)" class="{{ $this->window === 90 ? 'active' : '' }}">90 days</button>
        </div>

        {{-- ── Account growth (followers + impressions over time, per network) ── --}}
        @if ($g['brand'] !== null && $g['data'] !== null)
            @php
                // Metricool down RIGHT NOW = every attempted network on BOTH dimensions
                // errored (reachable=false on both). Distinct from "this brand has no
                // networks connected" (which is not_available, reachable=true). In an
                // outage we collapse the wall of red tiles into ONE calm banner — the
                // real post metrics below are unaffected and stay fully visible.
                $growthUnreachable = ($g['data']['followers']['reachable'] ?? true) === false
                    && ($g['data']['impressions']['reachable'] ?? true) === false;
            @endphp
            <div class="perf-growth">
                <div class="perf-section-title">Account growth · live from Metricool</div>

                @if ($growthUnreachable)
                    <div class="perf-growth-outage" role="status">
                        <span class="ico" aria-hidden="true">⟳</span>
                        <div class="msg">
                            <strong>Metricool analytics is temporarily slow to respond.</strong>
                            Your followers and impressions history is safe and will reappear
                            automatically — there’s nothing to do on your end. Use
                            <em>Refresh growth</em> to retry now. Your post counts below are unaffected.
                        </div>
                    </div>
                @else
                @foreach (['followers', 'impressions'] as $dimKey)
                    @php($dim = $g['data'][$dimKey])
                    <div class="perf-growth-block">
                        <div class="perf-growth-head">
                            <h4>{{ $dim['title'] }}</h4>
                            <div class="perf-growth-total">
                                <div class="n">{{ number_format($dim['total']) }}</div>
                                <div class="l">{{ $dimKey === 'followers' ? 'followers across networks' : 'impressions, last ' . $g['data']['window_days'] . ' days' }}</div>
                            </div>
                        </div>

                        <div class="perf-net-tiles">
                            @foreach ($dim['networks'] as $net)
                                @if ($net['status'] === 'ok')
                                    <div class="perf-net" style="background: {{ $net['color'] }};">
                                        <span class="num">{{ number_format($net['headline']) }}</span>
                                        <span class="net">{{ $net['label'] }}</span>
                                        @if ($net['change'] !== null)
                                            <span class="chg">{{ $net['change'] >= 0 ? '▲ +' : '▼ ' }}{{ number_format($net['change']) }}</span>
                                        @endif
                                    </div>
                                @else
                                    <div class="perf-net perf-net-na">
                                        <span class="net">{{ $net['label'] }}</span>
                                        <span class="na">
                                            @switch($net['status'])
                                                @case('not_available') Not connected / not on plan @break
                                                @case('error') Temporarily unavailable @break
                                                @default No data in this window
                                            @endswitch
                                        </span>
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @if ($dim['has_data'])
                            @php($historyDays = count($dim['axis']))
                            <div class="perf-chart-box" wire:ignore wire:key="perf-growth-{{ $dimKey }}-{{ $g['data']['window_days'] }}">
                                <canvas
                                    id="perf-growth-{{ $dimKey }}-{{ $g['data']['window_days'] }}"
                                    x-data
                                    x-init="window.eiaawRenderGrowthChart(
                                        'perf-growth-{{ $dimKey }}-{{ $g['data']['window_days'] }}',
                                        @js($dim['axis']),
                                        @js(collect($dim['networks'])->where('status','ok')->map(fn($n)=>['label'=>$n['label'],'color'=>$n['color'],'data'=>$n['series']])->values()),
                                        '{{ $dimKey === 'followers' ? 'line' : 'area' }}',
                                        {{ $historyDays <= 2 ? 'true' : 'false' }}
                                    )"
                                ></canvas>
                            </div>

                            {{-- Sparse-history note: a follower line needs several daily readings to
                                 show a trend. Metricool only starts recording follower counts from the
                                 day the account is connected, so a freshly-connected brand has 1–2
                                 points and the line is truthfully flat. Explain it rather than fake a slope. --}}
                            @if ($dimKey === 'followers' && $historyDays <= 2)
                                <div class="perf-growth-note" style="margin-top:8px;">
                                    Follower history builds one reading per day from when each account was connected — Metricool has {{ $historyDays }} day{{ $historyDays === 1 ? '' : 's' }} so far, so each network shows as a point, not yet a trend line. The curve fills in as the days accrue.
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach

                <div class="perf-growth-note">
                    {{ $g['brand']['name'] }} · blogId {{ $g['brand']['blog_id'] }} · impressions summed from per-post analytics · cached ~5&nbsp;min (use “Refresh growth”) · real readings only, networks we can’t read are marked plainly
                </div>
                @endif {{-- /growthUnreachable --}}
            </div>
        @elseif (! $g['configured'])
            {{-- Metricool not wired in this env — quiet, no scary banner on the customer page --}}
        @endif

        {{-- ── Growth strategy (computed from this brand's own real performance) ── --}}
        @if ($gs['brief'] !== null)
            @php($b = $gs['brief'])
            <div class="perf-growth" style="margin-top:18px;">
                <div class="perf-section-title">Growth strategy · from your own performance</div>

                @if ($b['summary'] !== '')
                    <div class="perf-growth-note" style="margin-bottom:12px; font-size:0.95em;">{{ $b['summary'] }}</div>
                @endif

                <div class="perf-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr));">
                    @if (! empty($b['best_times']))
                        <div class="perf-tile" style="text-align:left;">
                            <div class="perf-tile-label">Best posting times</div>
                            @foreach ($b['best_times'] as $platform => $slots)
                                <div class="perf-tile-sub" style="margin-top:4px;"><strong>{{ ucfirst($platform) }}</strong>: {{ $slots }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($b['hooks']))
                        <div class="perf-tile" style="text-align:left;">
                            <div class="perf-tile-label">Winning hooks</div>
                            @foreach ($b['hooks'] as $h)
                                @php($hWin = $h['win_rate'] !== null ? ' · '.$h['win_rate'].'% win' : '')
                                <div class="perf-tile-sub" style="margin-top:4px;">{{ str_replace('_', ' ', $h['hook']).$hWin }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if (! empty($b['follower_velocity']))
                        <div class="perf-tile" style="text-align:left;">
                            <div class="perf-tile-label">Follower momentum</div>
                            @foreach ($b['follower_velocity'] as $v)
                                <div class="perf-tile-sub" style="margin-top:4px;"><strong>{{ $v['label'] }}</strong>: {{ $v['direction'] }}</div>
                            @endforeach
                        </div>
                    @endif

                    @if (($b['cta_lift']['has_signal'] ?? false))
                        <div class="perf-tile">
                            <div class="perf-tile-label">CTA lift</div>
                            <div class="perf-tile-num">{{ $b['cta_lift']['lift_pct'] > 0 ? '+' : '' }}{{ $b['cta_lift']['lift_pct'] }}%</div>
                            <div class="perf-tile-sub">link clicks with a CTA</div>
                        </div>
                    @endif
                </div>

                @if (! empty($b['goal_progress']))
                    <div class="perf-section-title" style="margin-top:16px; font-size:0.95em;">Goal progress</div>
                    @foreach ($b['goal_progress'] as $gp)
                        @php
                            $gpScope = $gp['platform'] ? ' ('.ucfirst($gp['platform']).')' : '';
                            $gpProgress = $gp['progress_pct'] !== null
                                ? $gp['progress_pct'].'% there'
                                : 'progress builds as readings arrive';
                            $gpBy = ! empty($gp['window_ends_on']) ? ' · by '.$gp['window_ends_on'] : '';
                        @endphp
                        <div class="perf-growth-note" style="margin-top:4px;">
                            <strong>{{ str_replace('_', ' ', $gp['target_metric']).$gpScope }}</strong>
                            → target {{ number_format($gp['target_value']) }} · {{ $gpProgress }}{{ $gpBy }}
                        </div>
                    @endforeach
                @endif

                <div class="perf-growth-note" style="margin-top:12px;">
                    Computed from {{ $b['post_count'] }} of your published posts · updated {{ $b['updated_at'] }} · real readings only — the AI uses this to plan your calendar, hooks, and CTAs.
                </div>
            </div>
        @endif

        <div class="perf-section-title">Posts — last {{ $this->window }} days</div>

        <div class="perf-grid">
            <div class="perf-tile">
                <div class="perf-tile-label">Published</div>
                <div class="perf-tile-num">{{ number_format($s['published']) }}</div>
                <div class="perf-tile-sub">{{ $s['queued'] }} queued · {{ $s['failed'] }} failed</div>
            </div>
            <div class="perf-tile">
                <div class="perf-tile-label">Impressions</div>
                <div class="perf-tile-num">{{ number_format($s['impressions']) }}</div>
                <div class="perf-tile-sub">reach {{ number_format($s['reach']) }}</div>
            </div>
            <div class="perf-tile">
                <div class="perf-tile-label">Engagement</div>
                <div class="perf-tile-num">{{ number_format($s['engagement_total']) }}</div>
                <div class="perf-tile-sub">{{ $s['likes'] }} like · {{ $s['comments'] }} cmt · {{ $s['shares'] }} shr · {{ $s['saves'] }} sav</div>
            </div>
            <div class="perf-tile">
                <div class="perf-tile-label">Profile visits</div>
                <div class="perf-tile-num">{{ number_format($s['profile_visits']) }}</div>
                <div class="perf-tile-sub">url clicks {{ number_format($s['url_clicks']) }}</div>
            </div>
            <div class="perf-tile">
                <div class="perf-tile-label">Video views</div>
                <div class="perf-tile-num">{{ number_format($s['video_views']) }}</div>
                <div class="perf-tile-sub">v1.1: per-platform breakdown</div>
            </div>
        </div>

        <div class="perf-section-title">Per platform</div>
        @if (empty($platforms))
            <div class="perf-empty">
                @if ($hasMetrics)
                    No platform breakdown for this window.
                @else
                    No metrics yet. Blotato's status endpoint doesn't echo platform analytics for most networks (Meta, LinkedIn, YouTube, TikTok, Threads). LinkedIn's personal-profile read API is closed — auto-pull requires migrating to a Company Page + Marketing Developer Platform approval. Until then, use <strong>Upload metrics CSV</strong> in the page header — export from each platform's native analytics, paste the post URL, upload. Empty cells stay empty (no fabricated zeros).
                @endif
            </div>
        @else
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Posts</th>
                        <th class="num">Impressions</th>
                        <th class="num">Engagement</th>
                        <th class="num">Eng. rate</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($platforms as $p)
                        <tr>
                            <td style="text-transform: capitalize;">{{ $p['platform'] }}</td>
                            <td>{{ $p['posts'] }}</td>
                            <td class="num">{{ number_format($p['impressions']) }}</td>
                            <td class="num">{{ number_format($p['engagement']) }}</td>
                            <td class="num">{{ $p['engagement_rate'] !== null ? number_format($p['engagement_rate'] * 100, 2) . '%' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="perf-section-title">Top performers (10)</div>
        @if (empty($top))
            <div class="perf-empty">No top posts yet.</div>
        @else
            <table class="perf-table">
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Caption</th>
                        <th class="num">Imp.</th>
                        <th class="num">Likes</th>
                        <th class="num">Cmts</th>
                        <th class="num">Shares</th>
                        <th class="num">Saves</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($top as $t)
                        <tr>
                            <td style="text-transform: capitalize;">{{ $t['platform'] }}</td>
                            <td>
                                @if ($t['url'])
                                    <a href="{{ $t['url'] }}" target="_blank" rel="noopener" style="color: var(--eiaaw-primary-dark);">{{ $t['preview'] }}…</a>
                                @else
                                    {{ $t['preview'] }}…
                                @endif
                            </td>
                            <td class="num">{{ $t['impressions'] !== null ? number_format($t['impressions']) : '—' }}</td>
                            <td class="num">{{ $t['likes'] !== null ? number_format($t['likes']) : '—' }}</td>
                            <td class="num">{{ $t['comments'] !== null ? number_format($t['comments']) : '—' }}</td>
                            <td class="num">{{ $t['shares'] !== null ? number_format($t['shares']) : '—' }}</td>
                            <td class="num">{{ $t['saves'] !== null ? number_format($t['saves']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="perf-cost-row">
            <span>since · <strong>{{ $s['since'] }}</strong></span>
            <span style="margin-left: auto;">every number sourced from post_metrics · no fabricated data</span>
        </div>
    </div>

    {{-- Chart.js + the growth-chart render helper (shared shape: nulls = honest gaps).
         wire:ignore on each canvas box keeps Livewire from wiping it on update; the
         wire:key carries the window so a window change rebuilds the chart. --}}
    @once
        @push('scripts')
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
            <script>
                window.__eiaawGrowthCharts = window.__eiaawGrowthCharts || {};
                window.eiaawRenderGrowthChart = function (canvasId, axis, series, mode, forceMarkers) {
                    var el = document.getElementById(canvasId);
                    if (!el || typeof Chart === 'undefined') return;
                    if (window.__eiaawGrowthCharts[canvasId]) {
                        window.__eiaawGrowthCharts[canvasId].destroy();
                    }
                    // With only 1–2 readings a line has nothing to slope between; make the
                    // real points clearly visible as dots instead of a misleading flat line.
                    var pointR = forceMarkers ? 5 : 2;
                    var datasets = series.map(function (s) {
                        return {
                            label: s.label,
                            data: s.data,
                            borderColor: s.color,
                            backgroundColor: mode === 'area' ? (s.color + '22') : s.color,
                            fill: mode === 'area',
                            tension: 0.35,
                            spanGaps: true,
                            pointRadius: pointR,
                            pointHoverRadius: pointR + 2,
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
                                legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                                tooltip: { callbacks: {
                                    label: function (c) {
                                        var v = c.parsed.y;
                                        return c.dataset.label + ': ' + (v == null ? '—' : v.toLocaleString());
                                    }
                                } }
                            },
                            scales: {
                                x: { grid: { display: false }, ticks: { maxTicksLimit: 8, font: { size: 10 } } },
                                y: { beginAtZero: false, ticks: { font: { size: 10 },
                                    callback: function (v) { return Number(v).toLocaleString(); } } }
                            }
                        }
                    });
                };
            </script>
        @endpush
    @endonce
</x-filament-panels::page>

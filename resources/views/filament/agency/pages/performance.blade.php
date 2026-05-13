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
        </style>
    @endpush

    @php
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
                    No metrics yet. Blotato's status endpoint doesn't echo platform analytics for most networks (Meta, LinkedIn, YouTube, TikTok, Threads), so until v1.1 first-party OAuth pulls land, use <strong>Upload metrics CSV</strong> in the page header — export from each platform's native analytics, paste the post URL, upload. Empty cells stay empty (no fabricated zeros).
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
            <span>AI cost · <strong>${{ number_format($s['cost_usd'], 2) }}</strong></span>
            <span>cost / post · <strong>${{ number_format($s['cost_per_post'], 4) }}</strong></span>
            <span>since · <strong>{{ $s['since'] }}</strong></span>
            <span style="margin-left: auto;">every number sourced from post_metrics + ai_costs · no fabricated data</span>
        </div>
    </div>
</x-filament-panels::page>

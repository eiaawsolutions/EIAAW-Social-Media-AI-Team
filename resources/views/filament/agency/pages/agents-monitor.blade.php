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
                --status-stuck: #B4412B;
                --status-stuck-tint: #F8E2DC;
                --status-failing: #C68B00;
                --status-failing-tint: #FAF1DC;
                --status-active: #11766A;
                --status-active-tint: #E5F4F1;
                --status-healthy: #2A3438;
                --status-healthy-tint: #ECECEC;
                --status-idle: #6B7A7F;
                --status-idle-tint: #F1ECE1;
            }
            .agents-shell {
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line);
                border-radius: 16px;
                padding: 28px 28px 32px;
            }
            .agents-meta {
                font-family: var(--eiaaw-mono);
                font-size: 11px;
                letter-spacing: .12em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
                display: flex; align-items: center; gap: 14px;
                margin-bottom: 12px;
            }
            .agents-meta .agents-refresh {
                margin-left: auto;
                background: var(--eiaaw-ink);
                color: var(--eiaaw-bg);
                border: none;
                padding: 6px 14px;
                border-radius: 999px;
                font-family: var(--eiaaw-mono);
                font-size: 10px;
                letter-spacing: .12em;
                text-transform: uppercase;
                cursor: pointer;
            }
            .agents-meta .agents-refresh:hover { background: var(--eiaaw-primary-dark); }
            .agents-banner {
                font-size: 12.5px;
                color: var(--eiaaw-ink-2);
                background: var(--status-failing-tint);
                border: 1px solid #E6CFA0;
                border-radius: 10px;
                padding: 10px 14px;
                margin-bottom: 18px;
            }
            .agents-banner.ok {
                background: var(--status-active-tint);
                border-color: #B5DDD3;
            }
            .agents-list {
                display: flex; flex-direction: column; gap: 12px;
            }
            .agent-row {
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-left: 4px solid var(--eiaaw-line);
                border-radius: 12px;
                padding: 16px 18px 18px;
                display: grid;
                grid-template-columns: 32px 1fr auto;
                gap: 12px 16px;
                align-items: start;
            }
            .agent-row[data-status=stuck]   { border-left-color: var(--status-stuck); }
            .agent-row[data-status=failing] { border-left-color: var(--status-failing); }
            .agent-row[data-status=active]  { border-left-color: var(--status-active); }
            .agent-row[data-status=healthy] { border-left-color: var(--status-healthy); }
            .agent-row[data-status=idle]    { border-left-color: var(--status-idle); }

            .agent-order {
                font-family: var(--eiaaw-mono);
                font-size: 11px;
                letter-spacing: .08em;
                color: var(--eiaaw-mute);
                background: var(--eiaaw-bg-warm);
                border-radius: 6px;
                padding: 4px 0;
                text-align: center;
                font-weight: 600;
            }
            .agent-main h3 {
                font-size: 16px; font-weight: 600;
                letter-spacing: -0.01em; color: var(--eiaaw-ink);
                margin: 0 0 4px;
                display: flex; align-items: center; gap: 10px;
            }
            .agent-main h3 .role-tag {
                font-family: var(--eiaaw-mono);
                font-size: 10.5px;
                letter-spacing: .1em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
                font-weight: 500;
            }
            .agent-headline {
                font-size: 13.5px;
                color: var(--eiaaw-ink-2);
                margin: 0 0 6px;
                line-height: 1.5;
            }
            .agent-detail {
                font-family: var(--eiaaw-mono);
                font-size: 12px;
                color: #5B4B2E;
                background: #FBF5E6;
                border: 1px solid #ECDFB7;
                border-radius: 8px;
                padding: 8px 10px;
                white-space: pre-wrap;
                word-break: break-word;
                margin: 8px 0 10px;
            }
            .agent-next {
                font-size: 13px; color: var(--eiaaw-ink);
                margin: 0; padding: 8px 12px 8px 30px;
                background: var(--eiaaw-primary-tint);
                border-radius: 8px;
                position: relative;
                line-height: 1.5;
            }
            .agent-next::before {
                content: '→';
                position: absolute; left: 12px; top: 8px;
                color: var(--eiaaw-primary-dark);
                font-weight: 600;
            }
            .agent-aside {
                display: flex; flex-direction: column; gap: 6px;
                align-items: flex-end;
                min-width: 180px;
            }
            .status-pill {
                font-family: var(--eiaaw-mono);
                font-size: 10.5px;
                letter-spacing: .12em;
                text-transform: uppercase;
                padding: 4px 10px;
                border-radius: 999px;
                font-weight: 600;
            }
            .status-pill[data-status=stuck]   { color: var(--status-stuck);   background: var(--status-stuck-tint); }
            .status-pill[data-status=failing] { color: var(--status-failing); background: var(--status-failing-tint); }
            .status-pill[data-status=active]  { color: var(--status-active);  background: var(--status-active-tint); }
            .status-pill[data-status=healthy] { color: var(--status-healthy); background: var(--status-healthy-tint); }
            .status-pill[data-status=idle]    { color: var(--status-idle);    background: var(--status-idle-tint); }

            .agent-stats {
                display: flex; gap: 14px; flex-wrap: wrap;
                font-family: var(--eiaaw-mono);
                font-size: 11px;
                color: var(--eiaaw-mute);
                letter-spacing: .04em;
            }
            .agent-stats .stat strong { color: var(--eiaaw-ink-2); font-weight: 600; }
            .agent-empty {
                text-align: center; padding: 32px 0;
                color: var(--eiaaw-mute);
                font-size: 13px;
            }
            @media (max-width: 720px) {
                .agent-row { grid-template-columns: 28px 1fr; }
                .agent-aside { grid-column: 1 / -1; align-items: flex-start; }
            }
        </style>
    @endpush

    <div class="agents-shell">
        <div class="agents-meta">
            <span>EIAAW · Agents pipeline</span>
            <span>· Updated {{ \Illuminate\Support\Carbon::parse($generatedAt)->diffForHumans() }}</span>
            <button type="button" class="agents-refresh" wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh">Refresh</button>
        </div>

        @if (! $horizonInfo['available'])
            <div class="agents-banner">
                <strong>Horizon is unreachable.</strong> Live queue depth and in-flight jobs aren't available — we're showing audit-log derived state only.
                <br>
                <span style="font-family: var(--eiaaw-mono); font-size: 11.5px; color: var(--eiaaw-mute);">{{ $horizonInfo['error'] }}</span>
            </div>
        @else
            <div class="agents-banner ok">
                <strong>Horizon connected.</strong> Queue depth and in-flight job counts are live. Pipeline state combines audit_log + pipeline_runs + Horizon.
            </div>
        @endif

        @if (empty($rows))
            <div class="agent-empty">
                No agents discovered in <code>app/Agents/</code>. Drop an Agent class in there and refresh.
            </div>
        @else
            <div class="agents-list">
                @foreach ($rows as $i => $row)
                    <div class="agent-row" data-status="{{ $row['status'] }}">
                        <div class="agent-order">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</div>

                        <div class="agent-main">
                            <h3>
                                {{ $row['label'] }}
                                <span class="role-tag">{{ $row['role'] }}</span>
                            </h3>
                            <p class="agent-headline">{{ $row['reason_headline'] }}</p>

                            @if (! empty($row['reason_detail']))
                                <div class="agent-detail">{{ $row['reason_detail'] }}</div>
                            @endif

                            <p class="agent-next">{{ $row['next_action'] }}</p>
                        </div>

                        <div class="agent-aside">
                            <span class="status-pill" data-status="{{ $row['status'] }}">{{ strtoupper($row['status']) }}</span>
                            <div class="agent-stats">
                                <span class="stat"><strong>{{ $row['runs_24h'] }}</strong> runs / 24h</span>
                                @if ($row['failed_24h'] > 0)
                                    <span class="stat"><strong>{{ $row['failed_24h'] }}</strong> failed</span>
                                @endif
                                @if (! is_null($row['p50_latency_ms']))
                                    <span class="stat"><strong>{{ number_format($row['p50_latency_ms']) }}ms</strong> p50</span>
                                @endif
                                @if ($row['blocked_pipelines'] > 0)
                                    <span class="stat"><strong>{{ $row['blocked_pipelines'] }}</strong> blocked</span>
                                @endif
                                @if ($row['stuck_pipelines'] > 0)
                                    <span class="stat"><strong>{{ $row['stuck_pipelines'] }}</strong> stuck</span>
                                @endif
                                @if (! empty($row['queue_depth']))
                                    <span class="stat" title="Queue: {{ $row['queue_name'] }}">
                                        Queue <strong>{{ $row['queue_depth']['pending'] ?? 0 }}</strong> pending
                                    </span>
                                @endif
                            </div>
                            @if (! empty($row['last_run_at']))
                                <span class="agent-stats">
                                    <span class="stat">Last run {{ \Illuminate\Support\Carbon::parse($row['last_run_at'])->diffForHumans() }}</span>
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--eiaaw-line-soft); font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute);">
            Cross-workspace · Super-admin only · Status derived from audit_log (24h) + pipeline_runs + Horizon
        </div>
    </div>
</x-filament-panels::page>

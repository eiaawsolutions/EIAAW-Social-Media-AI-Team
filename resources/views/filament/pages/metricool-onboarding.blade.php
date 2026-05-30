<x-filament-panels::page>
    @php($board = $this->getBoard())
    @php($configured = $this->metricoolConfigured())

    @push('styles')
        <style>
            .bo-grid { display: grid; gap: 20px; }
            .bo-card {
                background: var(--gray-50, #f9fafb);
                border: 1px solid var(--gray-200, #e5e7eb);
                border-radius: 12px;
                padding: 20px 22px;
            }
            .dark .bo-card { background: rgba(255,255,255,.02); border-color: rgba(255,255,255,.08); }
            .bo-step {
                display: grid; grid-template-columns: 30px 1fr; gap: 14px;
                padding: 12px 0; border-bottom: 1px dashed var(--gray-200, #e5e7eb);
            }
            .dark .bo-step { border-color: rgba(255,255,255,.08); }
            .bo-step:last-child { border-bottom: 0; }
            .bo-step-num {
                width: 26px; height: 26px; border-radius: 999px;
                background: #11766a; color: #f4fbf9;
                display: flex; align-items: center; justify-content: center;
                font-weight: 700; font-size: 13px; font-family: ui-monospace, monospace;
            }
            .bo-step-title { font-weight: 600; font-size: 14px; }
            .bo-step-desc { font-size: 13px; color: var(--gray-500, #6b7280); margin-top: 3px; line-height: 1.55; }
            .bo-cmd {
                position: relative;
                background: #0f1a1d; color: #d6e7e3;
                border-radius: 10px; padding: 14px 16px;
                font-family: ui-monospace, 'JetBrains Mono', monospace;
                font-size: 12.5px; line-height: 1.7;
                white-space: pre; overflow-x: auto;
                margin-top: 10px;
            }
            .bo-cmd-label { font-size: 11px; color: var(--gray-500,#6b7280); margin-top: 12px; font-weight: 600; }
            .bo-copy {
                position: absolute; top: 10px; right: 10px;
                background: rgba(255,255,255,.1); color: #fff;
                border: 0; border-radius: 6px; padding: 4px 10px;
                font-size: 11px; cursor: pointer; font-family: ui-sans-serif, system-ui;
            }
            .bo-copy:hover { background: rgba(255,255,255,.2); }
            .bo-ws-head {
                display: flex; align-items: center; justify-content: space-between;
                gap: 12px; flex-wrap: wrap;
            }
            .bo-ws-name { font-weight: 600; font-size: 15px; }
            .bo-ws-meta { font-size: 12px; color: var(--gray-500, #6b7280); }
            .bo-pill {
                font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 999px;
                font-family: ui-monospace, monospace; text-transform: uppercase; letter-spacing: .04em;
            }
            .bo-pill-not_mapped { background: #fef3c7; color: #92400e; }
            .bo-pill-mapped { background: #e0e7ff; color: #3730a3; }
            .bo-pill-link_sent { background: #dbeafe; color: #1e40af; }
            .dark .bo-pill-not_mapped { background: rgba(245,158,11,.15); color: #fcd34d; }
            .dark .bo-pill-mapped { background: rgba(99,102,241,.15); color: #c7d2fe; }
            .dark .bo-pill-link_sent { background: rgba(59,130,246,.15); color: #93c5fd; }
            .bo-empty {
                text-align: center; padding: 40px 20px; color: var(--gray-500, #6b7280);
            }
            .bo-inline-code {
                font-family: ui-monospace, monospace; font-size: 12px;
                background: var(--gray-100, #f3f4f6); padding: 1px 6px; border-radius: 4px;
            }
            .dark .bo-inline-code { background: rgba(255,255,255,.06); }
            .bo-warn {
                background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;
                border-radius: 10px; padding: 12px 16px; font-size: 13px; line-height: 1.5;
            }
            .dark .bo-warn { background: rgba(239,68,68,.1); border-color: rgba(239,68,68,.25); color: #fca5a5; }
        </style>
    @endpush

    <div class="bo-grid">

        @unless ($configured)
            <div class="bo-warn">
                <strong>Heads up:</strong> the shared Metricool account isn't configured in this environment
                (<span class="bo-inline-code">METRICOOL_API_TOKEN</span> / <span class="bo-inline-code">METRICOOL_USER_ID</span>).
                You can still map brands and mark links sent, but <strong>Detect now</strong> won't work until the token resolves.
            </div>
        @endunless

        {{-- ─────────── The static playbook ─────────── --}}
        <div class="bo-card">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--gray-500,#6b7280); margin-bottom: 8px;">
                The 5-minute onboarding (do this when a brand appears in the queue below)
            </div>

            <div class="bo-step">
                <div class="bo-step-num">1</div>
                <div>
                    <div class="bo-step-title">Create the brand in Metricool</div>
                    <div class="bo-step-desc">
                        Open <a href="{{ \App\Filament\Pages\MetricoolOnboarding::METRICOOL_APP_URL }}" target="_blank" rel="noopener" style="text-decoration: underline;">app.metricool.com</a>
                        (our <strong>single shared agency account</strong> — no new signup per client). Create or locate this client's brand and
                        note its <span class="bo-inline-code">blogId</span> — the numeric id in the brand's URL/settings. It is
                        <strong>not a secret</strong>; one shared token covers every brand.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">2</div>
                <div>
                    <div class="bo-step-title">Map the brand to its blogId</div>
                    <div class="bo-step-desc">
                        Each brand card below shows a ready-to-paste command — swap
                        <span class="bo-inline-code">PASTE_BLOG_ID_HERE</span> for the blogId from step&nbsp;1 and run it on the server.
                        Nothing goes into Infisical: the blogId is a plain argument, not a key.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">3</div>
                <div>
                    <div class="bo-step-title">Mint &amp; send the connect-link</div>
                    <div class="bo-step-desc">
                        In Metricool → <strong>Connections → Share</strong>, generate a connect-link for this brand
                        (it expires in <strong>71 hours</strong>) and send it to the customer. Then run the brand's
                        <span class="bo-inline-code">--mark-link-sent</span> command so the queue tracks it. Metricool has no API for the
                        link itself, so this mint is the one manual click.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">4</div>
                <div>
                    <div class="bo-step-title">Detect — and the customer takes it from here</div>
                    <div class="bo-step-desc">
                        The customer opens the link and connects their own Instagram / TikTok / LinkedIn / etc. inside Metricool, then clicks
                        "I've connected — check now" in their app. You can also press <strong>Detect now</strong> on the card below —
                        it reads Metricool's live profile and stamps the brand connected, unblocking their panel.
                    </div>
                </div>
            </div>
        </div>

        {{-- ─────────── The live queue ─────────── --}}
        <div>
            <div style="display:flex; align-items:baseline; justify-content:space-between; margin-bottom: 12px;">
                <h2 style="font-size: 18px; font-weight: 700;">
                    Waiting on HQ
                    @if (count($board['queue']) > 0)
                        <span style="color: var(--gray-500,#6b7280); font-weight: 500;">· {{ count($board['queue']) }}</span>
                    @endif
                </h2>
                <span class="bo-ws-meta">{{ $board['connected_count'] }} brand(s) already connected</span>
            </div>

            @if (count($board['queue']) === 0)
                <div class="bo-card bo-empty">
                    <div style="font-size: 15px; font-weight: 600; color: var(--gray-700,#374151);">Nothing waiting. 🎉</div>
                    <div style="margin-top: 6px;">Every customer brand is connected in Metricool. New brands appear here automatically with the right command for their stage.</div>
                </div>
            @else
                <div class="bo-grid">
                    @foreach ($board['queue'] as $b)
                        <div class="bo-card">
                            <div class="bo-ws-head">
                                <div>
                                    <div class="bo-ws-name">
                                        {{ $b['brand_name'] }}
                                        <span class="bo-ws-meta">· brand#{{ $b['brand_id'] }}</span>
                                    </div>
                                    <div class="bo-ws-meta">
                                        {{ $b['workspace_name'] ?? 'unknown workspace' }} · ws#{{ $b['workspace_id'] }}{{ $b['plan'] ? ' · ' . ucfirst($b['plan']) : '' }} ·
                                        Owner: {{ $b['owner_email'] ?? 'unknown' }}
                                        @if ($b['state'] === 'link_sent' && $b['link_sent_at'])
                                            · Link sent {{ $b['link_sent_at'] }}
                                        @endif
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <span class="bo-pill bo-pill-{{ $b['state'] }}">{{ str_replace('_', ' ', $b['state']) }}</span>
                                </div>
                            </div>

                            <div class="bo-ws-meta" style="margin-top: 10px;">
                                @if ($b['blog_id'])
                                    Metricool blogId: <span class="bo-inline-code">{{ $b['blog_id'] }}</span>
                                @else
                                    Not mapped to a Metricool brand yet
                                @endif
                            </div>

                            @foreach ($b['commands'] as $i => $cmd)
                                <div class="bo-cmd-label">{{ $cmd['label'] }}</div>
                                <div class="bo-cmd" id="cmd-{{ $b['brand_id'] }}-{{ $i }}">{{ $cmd['command'] }}<button
                                        type="button" class="bo-copy"
                                        onclick="(function(btn){const t=document.getElementById('cmd-{{ $b['brand_id'] }}-{{ $i }}').childNodes[0].textContent;navigator.clipboard.writeText(t).then(()=>{btn.textContent='Copied';setTimeout(()=>btn.textContent='Copy',1500);});})(this)"
                                    >Copy</button></div>
                            @endforeach

                            <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap;">
                                <x-filament::button
                                    size="sm"
                                    wire:click="detect({{ $b['brand_id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="detect({{ $b['brand_id'] }})"
                                    color="success"
                                    :disabled="! $configured || ! $b['blog_id']"
                                >
                                    <span wire:loading.remove wire:target="detect({{ $b['brand_id'] }})">Detect now</span>
                                    <span wire:loading wire:target="detect({{ $b['brand_id'] }})">Reading profile…</span>
                                </x-filament::button>

                                @if ($b['owner_email'])
                                    <x-filament::button size="sm" color="gray" tag="a" :href="'mailto:' . $b['owner_email']">
                                        Email owner
                                    </x-filament::button>
                                @endif
                            </div>

                            <div class="bo-step-desc" style="margin-top: 10px;">
                                <strong>Next:</strong> {{ $b['next'] }}
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div style="font-family: ui-monospace, monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--gray-400,#9ca3af);">
            Super-admin only · one shared Metricool account, one token (in Infisical), N brands · the blogId is not a secret · isolation is by always scoping calls to the right blogId
        </div>
    </div>
</x-filament-panels::page>

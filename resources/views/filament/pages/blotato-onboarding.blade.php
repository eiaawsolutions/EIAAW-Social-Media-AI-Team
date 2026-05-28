<x-filament-panels::page>
    @php($board = $this->getBoard())

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
                background: #f59e0b; color: #1c1917;
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
            .bo-pill-requested { background: #fef3c7; color: #92400e; }
            .bo-pill-credentialed { background: #dbeafe; color: #1e40af; }
            .dark .bo-pill-requested { background: rgba(245,158,11,.15); color: #fcd34d; }
            .dark .bo-pill-credentialed { background: rgba(59,130,246,.15); color: #93c5fd; }
            .bo-empty {
                text-align: center; padding: 40px 20px; color: var(--gray-500, #6b7280);
            }
            .bo-inline-code {
                font-family: ui-monospace, monospace; font-size: 12px;
                background: var(--gray-100, #f3f4f6); padding: 1px 6px; border-radius: 4px;
            }
            .dark .bo-inline-code { background: rgba(255,255,255,.06); }
        </style>
    @endpush

    <div class="bo-grid">

        {{-- ─────────── The static playbook ─────────── --}}
        <div class="bo-card">
            <div style="font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--gray-500,#6b7280); margin-bottom: 8px;">
                The 5-minute onboarding (do this when a workspace appears in the queue below)
            </div>

            <div class="bo-step">
                <div class="bo-step-num">1</div>
                <div>
                    <div class="bo-step-title">Create the Blotato account</div>
                    <div class="bo-step-desc">
                        Go to <a href="{{ \App\Filament\Pages\BlotatoOnboarding::BLOTATO_LOGIN_URL }}" target="_blank" rel="noopener" style="text-decoration: underline;">my.blotato.com</a>,
                        sign up a NEW account using the suggested email shown on the workspace card (e.g. <span class="bo-inline-code">ws42@eiaawsolutions.com</span>).
                        Set a temporary password and write it down — you'll paste it into the command in step&nbsp;3.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">2</div>
                <div>
                    <div class="bo-step-title">Put the API key in Infisical</div>
                    <div class="bo-step-desc">
                        In Blotato → settings → API, copy the <span class="bo-inline-code">blt_…</span> key.
                        In the Infisical UI, create a secret at the path shown on the workspace card
                        (<span class="bo-inline-code">{{ \App\Filament\Pages\BlotatoOnboarding::INFISICAL_PROJECT }}/{{ \App\Filament\Pages\BlotatoOnboarding::INFISICAL_ENV }}/BLOTATO_API_KEY_WS_&lt;id&gt;</span>)
                        and paste the key as the value. <strong>The key never goes in this app or in chat</strong> — only into Infisical.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">3</div>
                <div>
                    <div class="bo-step-title">Run the generated command</div>
                    <div class="bo-step-desc">
                        Each workspace below has a ready-to-paste command. Copy it, swap
                        <span class="bo-inline-code">PASTE_TEMP_PASSWORD_HERE</span> for the password from step&nbsp;1, and run it on the server.
                        It verifies the key works, then emails the customer their Blotato login + temp password.
                    </div>
                </div>
            </div>

            <div class="bo-step">
                <div class="bo-step-num">4</div>
                <div>
                    <div class="bo-step-title">Done — the customer takes it from here</div>
                    <div class="bo-step-desc">
                        They log in to Blotato, connect their own Instagram / TikTok / LinkedIn, then click "Verify connection" in their app.
                        You can also press <strong>Verify now</strong> on the card below to confirm the handoff worked without waiting for them.
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
                <span class="bo-ws-meta">{{ $board['connected_count'] }} workspace(s) already connected</span>
            </div>

            @if (count($board['queue']) === 0)
                <div class="bo-card bo-empty">
                    <div style="font-size: 15px; font-weight: 600; color: var(--gray-700,#374151);">Nothing waiting. 🎉</div>
                    <div style="margin-top: 6px;">No customer has requested Blotato setup that isn't already connected. When one does, they'll appear here with a ready-to-run command.</div>
                </div>
            @else
                <div class="bo-grid">
                    @foreach ($board['queue'] as $ws)
                        <div class="bo-card">
                            <div class="bo-ws-head">
                                <div>
                                    <div class="bo-ws-name">{{ $ws['name'] }} <span class="bo-ws-meta">· ws#{{ $ws['id'] }} · {{ ucfirst($ws['plan']) }}</span></div>
                                    <div class="bo-ws-meta">
                                        Owner: {{ $ws['owner_email'] ?? 'unknown' }} ·
                                        Requested {{ $ws['requested_at'] ?? 'recently' }}
                                        @if ($ws['state'] === 'credentialed' && $ws['credentialed_at'])
                                            · Credentials sent {{ $ws['credentialed_at'] }}
                                        @endif
                                    </div>
                                </div>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    @if ($ws['state'] === 'credentialed')
                                        <span class="bo-pill bo-pill-credentialed">credentialed · awaiting verify</span>
                                    @else
                                        <span class="bo-pill bo-pill-requested">requested</span>
                                    @endif
                                </div>
                            </div>

                            <div class="bo-ws-meta" style="margin-top: 10px;">
                                Suggested Blotato email: <span class="bo-inline-code">{{ $ws['blotato_email'] }}</span>
                                &nbsp;·&nbsp; Infisical path: <span class="bo-inline-code">{{ $ws['secret_path'] }}</span>
                            </div>

                            <div class="bo-cmd" id="cmd-{{ $ws['id'] }}">{{ $ws['command'] }}<button
                                    type="button" class="bo-copy"
                                    onclick="(function(btn){const t=document.getElementById('cmd-{{ $ws['id'] }}').childNodes[0].textContent;navigator.clipboard.writeText(t).then(()=>{btn.textContent='Copied';setTimeout(()=>btn.textContent='Copy',1500);});})(this)"
                                >Copy</button></div>

                            <div style="margin-top: 14px; display:flex; gap:10px; flex-wrap:wrap;">
                                <x-filament::button
                                    size="sm"
                                    wire:click="verify({{ $ws['id'] }})"
                                    wire:loading.attr="disabled"
                                    wire:target="verify({{ $ws['id'] }})"
                                    color="success"
                                >
                                    <span wire:loading.remove wire:target="verify({{ $ws['id'] }})">Verify now</span>
                                    <span wire:loading wire:target="verify({{ $ws['id'] }})">Pinging…</span>
                                </x-filament::button>

                                @if ($ws['owner_email'])
                                    <x-filament::button size="sm" color="gray" tag="a" :href="'mailto:' . $ws['owner_email']">
                                        Email owner
                                    </x-filament::button>
                                @endif
                            </div>

                            @if ($ws['state'] === 'requested')
                                <div class="bo-step-desc" style="margin-top: 10px;">
                                    <strong>Next:</strong> do steps 1–3 above, then run the command. It emails the customer and flips this card to "credentialed".
                                </div>
                            @else
                                <div class="bo-step-desc" style="margin-top: 10px;">
                                    <strong>Next:</strong> the customer has their credentials. They connect socials in Blotato then verify in their app — or press <strong>Verify now</strong> once they say they're connected.
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <div style="font-family: ui-monospace, monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--gray-400,#9ca3af);">
            Super-admin only · raw API keys never touch this app — Infisical holds the values, this page only builds the handle path
        </div>
    </div>
</x-filament-panels::page>

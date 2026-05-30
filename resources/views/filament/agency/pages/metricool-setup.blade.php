<x-filament-panels::page>
    @push('styles')
        <style>
            .ps-shell { background:#FAF7F2; border:1px solid #D9CFBC; border-radius:16px; padding:32px; }
            .ps-eyebrow { font-family:'JetBrains Mono',SFMono-Regular,Menlo,monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#11766A; }
            .ps-title { font-size:30px; font-weight:600; letter-spacing:-.025em; color:#0F1A1D; margin:14px 0 12px; line-height:1.15; }
            .ps-lead { font-size:15px; line-height:1.6; color:#2A3438; max-width:60ch; }
            .ps-brandcard { background:white; border:1px solid #D9CFBC; border-radius:12px; padding:22px; margin-top:18px; }
            .ps-brandcard-connected { background:#E5F4F1; border-color:#11766A; }
            .ps-brandcard-pending { background:#F3EDE0; }
            .ps-brandhead { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
            .ps-brandname { font-size:18px; font-weight:600; color:#0F1A1D; }
            .ps-badge { font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:.08em; text-transform:uppercase; padding:4px 10px; border-radius:999px; border:1px solid #D9CFBC; color:#6B7A7F; background:#FAF7F2; }
            .ps-badge-connected { color:#0B5C53; background:#CFEAE4; border-color:#11766A; }
            .ps-cta { display:inline-flex; align-items:center; gap:8px; background:#0F1A1D; color:#FAF7F2; padding:11px 20px; border-radius:999px; font-size:14px; font-weight:500; text-decoration:none; border:0; cursor:pointer; transition:transform .15s, background .15s; }
            .ps-cta:hover { background:#11766A; transform:translateY(-1px); }
            .ps-cta-ghost { background:transparent; color:#0F1A1D; border:1px solid #D9CFBC; }
            .ps-cta-ghost:hover { border-color:#0F1A1D; }
            .ps-cta[disabled] { opacity:.55; cursor:wait; }
            .ps-step { display:grid; grid-template-columns:28px 1fr; gap:12px; margin-bottom:12px; }
            .ps-step-num { width:26px; height:26px; border-radius:999px; background:#0F1A1D; color:#fff; display:flex; align-items:center; justify-content:center; font-family:'JetBrains Mono',monospace; font-size:12px; font-weight:600; }
            .ps-step-num-done { background:#11766A; }
            .ps-step-title { font-size:14px; font-weight:500; color:#0F1A1D; }
            .ps-step-desc { font-size:13px; color:#6B7A7F; margin-top:2px; line-height:1.55; }
            .ps-nets { margin-top:12px; display:flex; gap:8px; flex-wrap:wrap; }
            .ps-net { font-size:12px; font-family:'JetBrains Mono',monospace; padding:3px 9px; border-radius:6px; background:#CFEAE4; color:#0B5C53; text-transform:capitalize; }
            .ps-actions { margin-top:18px; display:flex; gap:12px; flex-wrap:wrap; }
            .ps-foot { margin-top:24px; padding-top:16px; border-top:1px dashed #D9CFBC; font-family:'JetBrains Mono',monospace; font-size:11px; letter-spacing:.12em; text-transform:uppercase; color:#6B7A7F; }
        </style>
    @endpush

    <div class="ps-shell">
        <div class="ps-eyebrow">Platform setup &middot; connect your accounts</div>
        <h2 class="ps-title">Connect your social accounts.</h2>
        <p class="ps-lead">
            EIAAW publishes and reads metrics through Metricool. For each brand, we set up a secure space and send you a
            link to connect your social accounts &mdash; <strong>no Metricool login needed</strong>. Once connected, we can
            publish and pull real performance numbers automatically.
        </p>

        @forelse ($brands as $brand)
            @php
                $state = $brand['state'];
                $cardClass = $state === 'connected' ? 'ps-brandcard-connected' : ($state === 'not_mapped' ? '' : 'ps-brandcard-pending');
            @endphp
            <div class="ps-brandcard {{ $cardClass }}">
                <div class="ps-brandhead">
                    <div class="ps-brandname">{{ $brand['name'] }}</div>
                    <div class="ps-badge {{ $state === 'connected' ? 'ps-badge-connected' : '' }}">
                        @switch($state)
                            @case('connected') Connected @break
                            @case('link_sent') Link sent &middot; awaiting you @break
                            @case('mapped') Ready to connect @break
                            @default Not set up @break
                        @endswitch
                    </div>
                </div>

                @if ($state === 'connected')
                    <p class="ps-lead" style="margin-top:12px;">
                        This brand is live. We can publish to your connected accounts and read their metrics.
                    </p>
                    @if (! empty($brand['networks']))
                        <div class="ps-nets">
                            @foreach ($brand['networks'] as $net)
                                <span class="ps-net">{{ $net }}</span>
                            @endforeach
                        </div>
                    @endif
                    <div class="ps-actions">
                        <a href="{{ url('/agency/platforms') }}" class="ps-cta ps-cta-ghost">Manage connections</a>
                        <button type="button" wire:click="checkConnection({{ $brand['id'] }})" wire:loading.attr="disabled" class="ps-cta ps-cta-ghost">
                            <span wire:loading.remove wire:target="checkConnection({{ $brand['id'] }})">Re-check</span>
                            <span wire:loading wire:target="checkConnection({{ $brand['id'] }})">Checking…</span>
                        </button>
                    </div>

                @elseif ($state === 'not_mapped')
                    <p class="ps-lead" style="margin-top:12px;">
                        We haven't set up this brand in Metricool yet. Request setup and we'll create your secure space and
                        email you a link to connect your accounts &mdash; usually within 1 business day.
                    </p>
                    <div class="ps-actions">
                        <button type="button" wire:click="requestSetup({{ $brand['id'] }})" wire:loading.attr="disabled" class="ps-cta">
                            <span wire:loading.remove wire:target="requestSetup({{ $brand['id'] }})">Request setup <span aria-hidden="true">&rarr;</span></span>
                            <span wire:loading wire:target="requestSetup({{ $brand['id'] }})">Notifying our team…</span>
                        </button>
                    </div>

                @else
                    {{-- mapped or link_sent --}}
                    <p class="ps-lead" style="margin-top:12px;">
                        @if ($state === 'link_sent')
                            We've sent you a link to connect your accounts. Open it, connect the socials you publish to, then click
                            <strong>I've connected &mdash; check now</strong>.
                        @else
                            Your space is ready. We'll send you a secure connection link shortly. When you've connected your
                            accounts, click <strong>I've connected &mdash; check now</strong>.
                        @endif
                    </p>
                    <div style="margin-top:16px;">
                        <div class="ps-step">
                            <div class="ps-step-num ps-step-num-done">1</div>
                            <div>
                                <div class="ps-step-title">Open the connection link we emailed you</div>
                                <div class="ps-step-desc">It's secure and expires after 71 hours. No Metricool account needed. Can't find it? Email eiaawsolutions@gmail.com.</div>
                            </div>
                        </div>
                        <div class="ps-step">
                            <div class="ps-step-num">2</div>
                            <div>
                                <div class="ps-step-title">Connect the accounts you publish to</div>
                                <div class="ps-step-desc">Instagram, Facebook, LinkedIn, TikTok, YouTube, Threads, X, Pinterest &mdash; each is a quick authorise.</div>
                            </div>
                        </div>
                        <div class="ps-step">
                            <div class="ps-step-num">3</div>
                            <div>
                                <div class="ps-step-title">Come back and check the connection</div>
                                <div class="ps-step-desc">We read your connected accounts live &mdash; no waiting on us.</div>
                            </div>
                        </div>
                    </div>
                    <div class="ps-actions">
                        <button type="button" wire:click="checkConnection({{ $brand['id'] }})" wire:loading.attr="disabled" class="ps-cta">
                            <span wire:loading.remove wire:target="checkConnection({{ $brand['id'] }})">I've connected &mdash; check now <span aria-hidden="true">&rarr;</span></span>
                            <span wire:loading wire:target="checkConnection({{ $brand['id'] }})">Checking Metricool…</span>
                        </button>
                    </div>
                @endif
            </div>
        @empty
            <div class="ps-brandcard">
                <p class="ps-lead">No brands yet. Create a brand in the setup wizard first, then come back here to connect its social accounts.</p>
                <div class="ps-actions">
                    <a href="{{ url('/agency/setup-wizard') }}" class="ps-cta">Go to setup wizard <span aria-hidden="true">&rarr;</span></a>
                </div>
            </div>
        @endforelse

        <div class="ps-foot">
            One connection per brand &middot; status read live from Metricool when you check &middot; no Metricool login required
        </div>
    </div>
</x-filament-panels::page>

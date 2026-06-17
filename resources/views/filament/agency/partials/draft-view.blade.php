@php
    /** @var \App\Models\Draft $draft */
    $checks = $draft->complianceChecks()->orderBy('id')->get();
@endphp

<div style="font-family: 'Inter', sans-serif; line-height: 1.5;">
    <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">
        {{ ucfirst($draft->platform) }} · {{ str_replace('_', ' ', $draft->status) }}
        @if ($draft->lane)
            · lane: <span style="color: {{ ['green' => '#11766A', 'amber' => '#8E6300', 'red' => '#7E2C1B'][$draft->lane] ?? '#6B7A7F' }};">{{ $draft->lane }}</span>
        @endif
    </div>

    @php
        $assetUrl = trim((string) ($draft->asset_url ?? ''));
        $isVideo = $assetUrl !== '' && (
            str_ends_with(strtolower($assetUrl), '.mp4')
            || str_ends_with(strtolower($assetUrl), '.mov')
            || str_ends_with(strtolower($assetUrl), '.webm')
            || str_contains($assetUrl, '/video/')
        );
        $draftPlaceholderId = 'draft-asset-missing-' . $draft->id;
    @endphp
    @if ($assetUrl !== '')
        <div style="margin-bottom: 14px;">
            @if ($isVideo)
                <video src="{{ $assetUrl }}"
                       controls
                       playsinline
                       onerror="document.getElementById('{{ $draftPlaceholderId }}').style.display='flex'; this.style.display='none';"
                       style="max-width: 100%; max-height: 480px; border-radius: 10px; border: 1px solid #D9CFBC; display: block; background: #000;"></video>
            @else
                <img src="{{ $assetUrl }}"
                     alt="Draft asset"
                     onerror="document.getElementById('{{ $draftPlaceholderId }}').style.display='flex'; this.style.display='none';"
                     style="max-width: 100%; max-height: 420px; border-radius: 10px; border: 1px solid #D9CFBC; display: block;" />
            @endif
            <div id="{{ $draftPlaceholderId }}"
                 style="display: none; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 6px; min-height: 160px; padding: 24px; border-radius: 10px; border: 1px dashed #D9CFBC; background: #FAF7F2;">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#B59B6B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="9" cy="9" r="2"></circle>
                    <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>
                </svg>
                <div style="font-size: 13px; font-weight: 600; color: #2A3438;">Media preview unavailable</div>
                <div style="font-size: 12px; color: #6B7A7F;">The post text below is unaffected.</div>
            </div>
        </div>
    @endif

    <div style="background: #FAF7F2; border: 1px solid #D9CFBC; border-radius: 10px; padding: 18px 20px; margin-bottom: 18px; white-space: pre-wrap; font-size: 14px; color: #0F1A1D;">{{ $draft->body }}</div>

    @if (! empty($draft->hashtags))
        <div style="margin-bottom: 16px;">
            <div style="font-family: 'JetBrains Mono', monospace; font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">Hashtags</div>
            <div style="font-size: 13px; color: #2A3438;">
                @foreach ($draft->hashtags as $h)
                    <span style="display: inline-block; padding: 2px 8px; background: #E5F4F1; color: #11766A; border-radius: 999px; margin-right: 4px; margin-bottom: 4px; font-size: 12px;">#{{ $h }}</span>
                @endforeach
            </div>
        </div>
    @endif

    @if ($checks->isNotEmpty())
        <div style="margin-bottom: 16px;">
            <div style="font-family: 'JetBrains Mono', monospace; font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">Compliance</div>
            <div style="border: 1px solid #E8DFCC; border-radius: 8px; overflow: hidden;">
                @foreach ($checks as $c)
                    <div style="padding: 8px 12px; background: {{ $loop->odd ? 'white' : '#FAF7F2' }}; font-size: 12.5px;">
                        <div style="display: flex; justify-content: space-between; gap: 12px;">
                            <span style="font-family: 'JetBrains Mono', monospace; color: #2A3438;">{{ \Illuminate\Support\Str::headline($c->check_type) }}</span>
                            <span style="font-family: 'JetBrains Mono', monospace; color: #6B7A7F;">{{ number_format((float) $c->score, 2) }}</span>
                            <span style="color: {{ ['pass' => '#11766A', 'warning' => '#8E6300', 'error' => '#8E6300'][$c->result] ?? '#7E2C1B' }}; font-weight: 600;">{{ strtoupper($c->result) }}</span>
                        </div>
                        @if ($c->result !== 'pass' && filled($c->reason))
                            <div style="margin-top: 4px; font-size: 11.5px; color: #6B7A7F; line-height: 1.45;">{{ $c->reason }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if (! empty($draft->grounding_sources))
        <div style="margin-bottom: 16px;">
            <div style="font-family: 'JetBrains Mono', monospace; font-size: 10.5px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">Grounding ({{ count($draft->grounding_sources) }})</div>
            <ol style="margin: 0; padding-left: 18px; font-size: 12.5px; color: #2A3438;">
                @foreach ($draft->grounding_sources as $g)
                    <li style="margin-bottom: 6px;">
                        <span style="font-family: 'JetBrains Mono', monospace; color: #6B7A7F;">[{{ $g['source_type'] ?? '?' }}@if (! empty($g['source_id'])):{{ $g['source_id'] }}@endif]</span>
                        <span style="color: #2A3438;">{{ \Illuminate\Support\Str::limit($g['claim'] ?? '', 100) }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
    @endif

    <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #6B7A7F; padding-top: 12px; border-top: 1px solid #E8DFCC;">
        {{ $draft->model_id ?? '?' }} · v{{ $draft->prompt_version ?? '?' }} · {{ $draft->input_tokens ?? 0 }}+{{ $draft->output_tokens ?? 0 }} tok · ${{ number_format((float) ($draft->cost_usd ?? 0), 4) }} · {{ $draft->latency_ms ?? 0 }}ms
    </div>
</div>

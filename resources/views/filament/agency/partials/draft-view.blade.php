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
                    <div style="padding: 8px 12px; display: flex; justify-content: space-between; gap: 12px; background: {{ $loop->odd ? 'white' : '#FAF7F2' }}; font-size: 12.5px;">
                        <span style="font-family: 'JetBrains Mono', monospace; color: #2A3438;">{{ $c->check_type }}</span>
                        <span style="font-family: 'JetBrains Mono', monospace; color: #6B7A7F;">{{ number_format((float) $c->score, 2) }}</span>
                        <span style="color: {{ $c->result === 'pass' ? '#11766A' : '#7E2C1B' }}; font-weight: 600;">{{ strtoupper($c->result) }}</span>
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

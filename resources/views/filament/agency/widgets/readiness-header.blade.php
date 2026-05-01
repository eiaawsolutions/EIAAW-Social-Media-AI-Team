<x-filament-widgets::widget>
    @if (! $readiness || ! $readiness->hasAnyBrand)
        <a href="{{ url('/agency/setup-wizard') }}"
           style="display:flex;align-items:center;gap:18px;padding:18px 22px;background:#0F1A1D;color:#FAF7F2;border-radius:14px;text-decoration:none;font-family:'Inter',system-ui,sans-serif;">
            <div style="width:40px;height:40px;border-radius:50%;background:#1FA896;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;font-family:'JetBrains Mono',monospace;">0%</div>
            <div style="flex:1;">
                <div style="font-size:14px;font-weight:500;letter-spacing:-0.01em;">Welcome to EIAAW Social Media Team.</div>
                <div style="font-size:12px;color:rgba(250,247,242,0.7);font-family:'JetBrains Mono',monospace;letter-spacing:0.04em;margin-top:2px;">
                    Add your first brand to start the agents.
                </div>
            </div>
            <div style="font-size:13px;font-weight:500;letter-spacing:-0.005em;">Add brand →</div>
        </a>
    @else
        @php
            $primary = $readiness->nextActionableBrand() ?? $readiness->primaryBrand;
            $next = $primary?->nextActionable;
            $allComplete = $primary?->isComplete ?? false;
            $href = $next?->ctaUrl ?? url('/agency/setup-wizard?brand='.$primary->brand->id);
            $color = $allComplete ? '#1FA896' : '#0F1A1D';
            $textColor = '#FAF7F2';
        @endphp
        <a href="{{ $href }}"
           style="display:flex;align-items:center;gap:18px;padding:18px 22px;background:{{ $color }};color:{{ $textColor }};border-radius:14px;text-decoration:none;font-family:'Inter',system-ui,sans-serif;">
            <div style="width:48px;height:48px;border-radius:50%;background:rgba(31,168,150,{{ $allComplete ? '0' : '0.95' }});color:white;display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;font-family:'JetBrains Mono',monospace;{{ $allComplete ? 'border:2px solid #FAF7F2;' : '' }}">
                {{ $primary->percent }}%
            </div>
            <div style="flex:1;">
                <div style="font-size:14px;font-weight:500;letter-spacing:-0.01em;">
                    {{ $primary->brand->name }}
                    <span style="font-family:'JetBrains Mono',monospace;font-size:11px;letter-spacing:0.06em;color:rgba(250,247,242,0.55);margin-left:8px;">
                        {{ $primary->doneStages }} / {{ $primary->totalStages }} STAGES
                    </span>
                </div>
                <div style="font-size:12px;color:rgba(250,247,242,0.75);margin-top:3px;">
                    @if ($allComplete)
                        Every stage complete. Agents are running.
                    @else
                        Next: {{ $next?->label ?? '—' }}
                    @endif
                </div>
            </div>
            <div style="font-size:13px;font-weight:500;letter-spacing:-0.005em;">
                @if ($allComplete) View brand →
                @else {{ $next?->ctaLabel ?? 'Continue setup' }} →
                @endif
            </div>
        </a>
    @endif
</x-filament-widgets::widget>

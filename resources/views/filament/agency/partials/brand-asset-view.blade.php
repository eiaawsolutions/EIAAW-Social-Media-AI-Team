@php
    /** @var \App\Models\BrandAsset $asset */
    $isVideo = $asset->media_type === 'video';
    $url = $asset->displayUrl();
    // Single-record context (a modal) — a disk stat here is fine. If the bytes
    // are gone (e.g. uploaded to an ephemeral disk that was wiped on redeploy)
    // show an honest placeholder instead of a broken-image glyph.
    $hasBytes = $url !== null && $asset->bytesAvailable();
    $placeholderId = 'asset-missing-' . $asset->id;
@endphp

<div style="font-family: 'Inter', sans-serif; line-height: 1.5;">
    <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">
        {{ $asset->media_type }} · {{ $asset->source }}
        @if ($asset->brand_approved)
            · <span style="color: #11766A;">approved</span>
        @endif
    </div>

    @if ($url !== null && $hasBytes)
        @if ($isVideo)
            <video src="{{ $url }}"
                   controls playsinline
                   onerror="document.getElementById('{{ $placeholderId }}').style.display='flex'; this.style.display='none';"
                   style="max-width: 100%; max-height: 480px; border-radius: 10px; border: 1px solid #D9CFBC; display: block; background: #000; margin-bottom: 14px;"></video>
        @else
            <img src="{{ $url }}"
                 alt="{{ $asset->original_filename }}"
                 onerror="document.getElementById('{{ $placeholderId }}').style.display='flex'; this.style.display='none';"
                 style="max-width: 100%; max-height: 480px; border-radius: 10px; border: 1px solid #D9CFBC; display: block; margin-bottom: 14px;" />
        @endif
    @endif

    {{-- Honest placeholder: shown up-front when the bytes are gone, OR swapped
         in by the onerror handler above if the URL 404s at render time. --}}
    <div id="{{ $placeholderId }}"
         style="{{ ($url !== null && $hasBytes) ? 'display: none;' : 'display: flex;' }} flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 8px; min-height: 200px; padding: 28px 24px; border-radius: 10px; border: 1px dashed #D9CFBC; background: #FAF7F2; margin-bottom: 14px;">
        <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#B59B6B" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
            <circle cx="9" cy="9" r="2"></circle>
            <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"></path>
        </svg>
        <div style="font-size: 14px; font-weight: 600; color: #2A3438;">Preview unavailable</div>
        <div style="font-size: 12.5px; color: #6B7A7F; max-width: 320px;">
            This asset’s file is no longer in storage, so it can’t be shown. Please re-upload it from the Asset library and we’ll keep it from here.
        </div>
    </div>

    @if ($asset->description)
        <div style="font-size: 14px; color: #2A3438; margin-bottom: 12px;">{{ $asset->description }}</div>
    @endif

    @if ($asset->isCustomised())
        <div style="background: #FCF6E8; border: 1px solid #E8DFCC; border-radius: 10px; padding: 12px 14px; margin-bottom: 14px;">
            <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #B26A00; margin-bottom: 6px;">
                Customised post · reserved (agents won't reuse)
            </div>
            <div style="font-size: 13px; color: #2A3438;">
                @if ($asset->scheduled_post_for)
                    Scheduled for
                    <strong>{{ $asset->scheduled_post_for->setTimezone($asset->brand?->timezone ?: 'UTC')->format('D, M j Y · g:i A') }}</strong>
                    ({{ $asset->brand?->timezone ?: 'UTC' }})
                @endif
                @if (! empty($asset->scheduled_platforms))
                    <div style="margin-top: 4px; color: #6B7A7F;">
                        Platforms: {{ implode(', ', (array) $asset->scheduled_platforms) }}
                    </div>
                @endif
                <div style="margin-top: 4px; color: #6B7A7F;">
                    Narrative: {{ $asset->narrative_source === 'ai_writer' ? 'AI-written (reviewed)' : 'hand-written' }}
                </div>
            </div>
        </div>
    @endif

    @if (! empty($asset->tags))
        <div style="margin-bottom: 14px;">
            @foreach ($asset->tags as $tag)
                <span style="display: inline-block; padding: 2px 8px; background: #E5F4F1; color: #11766A; border-radius: 999px; margin-right: 4px; margin-bottom: 4px; font-size: 11.5px; font-family: 'JetBrains Mono', monospace;">{{ $tag }}</span>
            @endforeach
        </div>
    @endif

    <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; color: #6B7A7F; padding-top: 12px; border-top: 1px solid #E8DFCC;">
        {{ $asset->original_filename ?? '?' }} · {{ $asset->mime_type ?? '?' }} ·
        {{ $asset->file_size_bytes ? round($asset->file_size_bytes / 1024 / 1024, 1) . ' MB' : '?' }} ·
        used {{ $asset->use_count }} time(s){{ $asset->last_used_at ? ', last ' . $asset->last_used_at->diffForHumans() : '' }}
    </div>
</div>

@php
    /** @var \App\Models\BrandAsset $asset */
    $isVideo = $asset->media_type === 'video';
@endphp

<div style="font-family: 'Inter', sans-serif; line-height: 1.5;">
    <div style="font-family: 'JetBrains Mono', monospace; font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: #6B7A7F; margin-bottom: 6px;">
        {{ $asset->media_type }} · {{ $asset->source }}
        @if ($asset->brand_approved)
            · <span style="color: #11766A;">approved</span>
        @endif
    </div>

    @if ($isVideo)
        <video src="{{ $asset->public_url }}"
               controls playsinline
               style="max-width: 100%; max-height: 480px; border-radius: 10px; border: 1px solid #D9CFBC; display: block; background: #000; margin-bottom: 14px;"></video>
    @else
        <img src="{{ $asset->public_url }}"
             alt="{{ $asset->original_filename }}"
             style="max-width: 100%; max-height: 480px; border-radius: 10px; border: 1px solid #D9CFBC; display: block; margin-bottom: 14px;" />
    @endif

    @if ($asset->description)
        <div style="font-size: 14px; color: #2A3438; margin-bottom: 12px;">{{ $asset->description }}</div>
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

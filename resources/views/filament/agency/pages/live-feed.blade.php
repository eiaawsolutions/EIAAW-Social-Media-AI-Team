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
            .lf-shell {
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line);
                border-radius: 16px;
                padding: 24px;
            }
            .lf-tabs {
                display: flex; gap: 6px; flex-wrap: wrap;
                margin-bottom: 22px;
            }
            .lf-tabs button {
                font-family: var(--eiaaw-mono); font-size: 11px;
                letter-spacing: .12em; text-transform: uppercase;
                padding: 6px 12px; border-radius: 999px; cursor: pointer;
                background: white; color: var(--eiaaw-ink-2);
                border: 1px solid var(--eiaaw-line);
                display: inline-flex; align-items: center; gap: 6px;
            }
            .lf-tabs button.active { background: var(--eiaaw-ink); color: var(--eiaaw-bg); border-color: var(--eiaaw-ink); }
            .lf-tabs .lf-tab-count { font-size: 10px; opacity: .65; }

            .lf-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
                gap: 14px;
            }
            .lf-card {
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-radius: 12px;
                overflow: hidden;
                display: flex; flex-direction: column;
                text-decoration: none; color: inherit;
                transition: transform .2s, border-color .2s, box-shadow .2s;
            }
            .lf-card:hover {
                transform: translateY(-2px);
                border-color: var(--eiaaw-ink);
                box-shadow: 0 12px 28px -16px rgba(15,26,29,.18);
            }
            .lf-media {
                width: 100%; aspect-ratio: 1 / 1;
                background: var(--eiaaw-bg-warm);
                display: flex; align-items: center; justify-content: center;
                overflow: hidden;
            }
            .lf-media img, .lf-media video {
                width: 100%; height: 100%; object-fit: cover;
                display: block;
            }
            .lf-media-empty {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: .12em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
            }
            .lf-body {
                padding: 12px 14px 14px;
                display: flex; flex-direction: column;
                gap: 8px; flex: 1;
            }
            .lf-meta {
                display: flex; justify-content: space-between; align-items: baseline;
                font-family: var(--eiaaw-mono); font-size: 10.5px;
                letter-spacing: .12em; text-transform: uppercase;
                color: var(--eiaaw-mute);
            }
            .lf-platform-pill {
                display: inline-block;
                padding: 2px 8px; border-radius: 999px;
                font-size: 10px;
            }
            .lf-platform-instagram { background: #FCE7F3; color: #BE185D; }
            .lf-platform-facebook { background: #DBEAFE; color: #1E3A8A; }
            .lf-platform-linkedin { background: #E0F2FE; color: #0C4A6E; }
            .lf-platform-tiktok { background: #1F2937; color: #F9FAFB; }
            .lf-platform-threads { background: #1F2937; color: #F9FAFB; }
            .lf-platform-x { background: #111827; color: #F9FAFB; }
            .lf-platform-youtube { background: #FEE2E2; color: #991B1B; }
            .lf-platform-pinterest { background: #FEE2E2; color: #991B1B; }
            .lf-caption {
                font-size: 13px; color: var(--eiaaw-ink-2);
                line-height: 1.45;
                display: -webkit-box;
                -webkit-line-clamp: 4;
                -webkit-box-orient: vertical;
                overflow: hidden;
                word-break: break-word;
            }
            .lf-foot {
                font-family: var(--eiaaw-mono); font-size: 10.5px;
                color: var(--eiaaw-primary-dark);
                display: flex; justify-content: space-between; align-items: center;
                padding-top: 6px;
                border-top: 1px solid var(--eiaaw-line-soft);
            }
            .lf-card.is-publishing {
                border-style: dashed;
                border-color: var(--eiaaw-line);
                background: var(--eiaaw-bg-warm);
                cursor: default;
            }
            .lf-card.is-publishing:hover { transform: none; box-shadow: none; }
            .lf-publishing-pill {
                display: inline-flex; align-items: center; gap: 6px;
                font-family: var(--eiaaw-mono); font-size: 10px;
                letter-spacing: .12em; text-transform: uppercase;
                color: var(--eiaaw-ink-2);
                background: white;
                border: 1px solid var(--eiaaw-line);
                padding: 2px 8px; border-radius: 999px;
            }
            .lf-publishing-pill::before {
                content: ''; width: 6px; height: 6px; border-radius: 50%;
                background: #F59E0B;
                animation: lf-pulse 1.4s ease-in-out infinite;
            }
            @keyframes lf-pulse { 0%,100%{opacity:.35} 50%{opacity:1} }
            .lf-card.is-unverified {
                border-style: dashed;
                border-color: #D9CFBC;
                background: white;
                cursor: not-allowed;
            }
            .lf-card.is-unverified:hover { transform: none; box-shadow: none; border-color: #D9CFBC; }
            .lf-card.is-unverified .lf-media { opacity: .55; }
            .lf-unverified-pill {
                display: inline-flex; align-items: center; gap: 6px;
                font-family: var(--eiaaw-mono); font-size: 10px;
                letter-spacing: .12em; text-transform: uppercase;
                color: #92400E;
                background: #FEF3C7;
                border: 1px solid #FDE68A;
                padding: 2px 8px; border-radius: 999px;
            }
            .lf-empty {
                text-align: center;
                padding: 64px 24px;
                background: white;
                border: 1px dashed var(--eiaaw-line);
                border-radius: 14px;
            }
            .lf-empty h3 {
                font-size: 18px; font-weight: 500;
                color: var(--eiaaw-ink); margin: 0 0 8px;
            }
            .lf-empty p {
                font-size: 13px; color: var(--eiaaw-ink-2); margin: 0;
                max-width: 50ch; margin: 0 auto;
            }
        </style>
    @endpush

    @php
        $tz = $this->brandTimezone();
        $platformCounts = $this->platformCounts();
        $total = $this->totalLive();
        $posts = $this->posts();
    @endphp

    <div class="lf-shell" wire:poll.30s>
        <div class="lf-tabs">
            <button type="button"
                    wire:click="setPlatform(null)"
                    class="{{ $this->platformFilter === null ? 'active' : '' }}">
                All <span class="lf-tab-count">{{ $total }}</span>
            </button>
            @foreach ($platformCounts as $platform => $count)
                <button type="button"
                        wire:click="setPlatform('{{ $platform }}')"
                        class="{{ $this->platformFilter === $platform ? 'active' : '' }}">
                    {{ $platform }} <span class="lf-tab-count">{{ $count }}</span>
                </button>
            @endforeach
        </div>

        @if ($posts->isEmpty())
            <div class="lf-empty">
                <h3>No live posts yet.</h3>
                <p>
                    Once a scheduled post publishes via Blotato, it appears here. Schedule a draft from
                    <strong>/agency/drafts</strong> and the cron worker handles the rest.
                </p>
            </div>
        @else
            <div class="lf-grid">
                @foreach ($posts as $post)
                    @php
                        $draft = $post->draft;
                        $platform = $draft?->platform ?? '?';
                        $assetUrl = (string) ($draft?->asset_url ?? '');
                        $isVideo = $assetUrl !== '' && (
                            str_ends_with(strtolower($assetUrl), '.mp4')
                            || str_ends_with(strtolower($assetUrl), '.mov')
                            || str_ends_with(strtolower($assetUrl), '.webm')
                            || str_contains($assetUrl, '/video/')
                        );
                        $caption = trim((string) ($draft?->body ?? ''));
                        $isPublishing = $post->status === 'submitted';
                        $stamp = $isPublishing ? $post->submitted_at : $post->published_at;
                        $stampLocal = $stamp?->copy()->setTimezone($tz);
                        $clickHref = $isPublishing ? null : $this->clickUrl($post);
                        $hasUrl = ! empty($clickHref);
                        // Published row but no verified permalink — Blotato
                        // hasn't confirmed platform-side delivery yet. Show
                        // it but don't pretend the click goes anywhere.
                        $isUnverified = ! $isPublishing && ! $hasUrl;
                        $cardDisabled = $isPublishing || $isUnverified;
                    @endphp
                    <a class="lf-card {{ $isPublishing ? 'is-publishing' : '' }} {{ $isUnverified ? 'is-unverified' : '' }}"
                       href="{{ $hasUrl ? $clickHref : '#' }}"
                       target="{{ $hasUrl ? '_blank' : '_self' }}"
                       rel="{{ $hasUrl ? 'noopener noreferrer' : '' }}"
                       @if ($cardDisabled) onclick="return false;" @endif>
                        <div class="lf-media">
                            @if ($assetUrl !== '' && $isVideo)
                                <video src="{{ $assetUrl }}" muted loop playsinline preload="metadata"
                                       onmouseover="this.play()" onmouseout="this.pause()"></video>
                            @elseif ($assetUrl !== '')
                                <img src="{{ $assetUrl }}" alt="post asset" loading="lazy" />
                            @else
                                <span class="lf-media-empty">text-only</span>
                            @endif
                        </div>
                        <div class="lf-body">
                            <div class="lf-meta">
                                <span class="lf-platform-pill lf-platform-{{ $platform }}">{{ $platform }}</span>
                                <span>{{ $stampLocal?->format('M j · H:i') ?? '—' }}</span>
                            </div>
                            <div class="lf-caption">{{ $caption !== '' ? $caption : '(no caption)' }}</div>
                            <div class="lf-foot">
                                <span>#{{ $post->id }} · {{ $post->brand?->name ?? '?' }}</span>
                                @if ($isPublishing)
                                    <span class="lf-publishing-pill">publishing</span>
                                @elseif ($isUnverified)
                                    <span class="lf-unverified-pill" title="Awaiting platform confirmation — Blotato has not returned a permalink yet">unverified</span>
                                @else
                                    <span>{{ $hasUrl ? 'view live →' : '' }}</span>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>

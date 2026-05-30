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

            .lf-filters {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
                gap: 10px;
                margin-bottom: 16px;
                padding: 12px 14px;
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-radius: 12px;
                align-items: end;
            }
            .lf-filters label {
                display: block;
                font-family: var(--eiaaw-mono);
                font-size: 10px;
                letter-spacing: .14em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
                margin-bottom: 4px;
            }
            .lf-filters input,
            .lf-filters select {
                width: 100%;
                padding: 6px 10px;
                font-size: 13px;
                border: 1px solid var(--eiaaw-line);
                border-radius: 8px;
                background: var(--eiaaw-bg);
                color: var(--eiaaw-ink);
            }
            .lf-filters input:focus,
            .lf-filters select:focus {
                outline: none;
                border-color: var(--eiaaw-primary);
                background: white;
            }
            .lf-filters .lf-reset {
                font-family: var(--eiaaw-mono);
                font-size: 10px;
                letter-spacing: .14em;
                text-transform: uppercase;
                padding: 7px 12px;
                border-radius: 8px;
                background: var(--eiaaw-bg);
                color: var(--eiaaw-ink-2);
                border: 1px solid var(--eiaaw-line);
                cursor: pointer;
            }
            .lf-filters .lf-reset:hover { background: var(--eiaaw-bg-warm); }

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
            /* ---- Graphical per-post metrics panel ---- */
            .lf-metrics {
                display: flex; flex-direction: column; gap: 8px;
                padding: 8px 0 2px;
                border-top: 1px solid var(--eiaaw-line-soft);
            }
            /* Row 1: headline chips (icon + compact number + label) */
            .lf-chips {
                display: flex; align-items: stretch; gap: 6px;
            }
            .lf-chip {
                flex: 1 1 0; min-width: 0;
                display: flex; flex-direction: column; gap: 1px;
                padding: 5px 7px;
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line-soft);
                border-radius: 8px;
            }
            .lf-chip-top {
                display: flex; align-items: center; gap: 4px;
                color: var(--eiaaw-mute);
            }
            .lf-chip-top svg { width: 11px; height: 11px; flex: none; }
            .lf-chip-lbl {
                font-family: var(--eiaaw-mono); font-size: 8.5px;
                letter-spacing: .1em; text-transform: uppercase;
                white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
            }
            .lf-chip-val {
                font-family: var(--eiaaw-mono); font-size: 13px;
                font-weight: 600; color: var(--eiaaw-ink); line-height: 1.1;
            }
            .lf-chip-val.is-dash { color: var(--eiaaw-mute); font-weight: 600; }
            .lf-chip-lead .lf-chip-top { color: var(--eiaaw-primary-dark); }
            .lf-chip-lead { border-color: var(--eiaaw-primary); background: #ECFBF7; }

            /* Row 2: stacked engagement spark bar + engagement-rate donut */
            .lf-eng {
                display: flex; align-items: center; gap: 9px;
            }
            .lf-bar {
                flex: 1 1 auto; min-width: 0;
                height: 7px; border-radius: 999px; overflow: hidden;
                background: var(--eiaaw-line-soft);
                display: flex;
            }
            .lf-bar span { height: 100%; display: block; }
            .lf-bar .seg-likes { background: #EC4899; }
            .lf-bar .seg-comments { background: #1FA896; }
            .lf-bar .seg-shares { background: #6366F1; }
            .lf-bar .seg-saves { background: #F59E0B; }
            .lf-bar-empty { background: var(--eiaaw-line-soft); }
            .lf-eng-rate {
                flex: none;
                display: inline-flex; align-items: center; gap: 5px;
                font-family: var(--eiaaw-mono); font-size: 10.5px;
                font-weight: 600; color: var(--eiaaw-ink-2);
            }
            .lf-donut { width: 16px; height: 16px; flex: none; transform: rotate(-90deg); }
            .lf-donut-track { stroke: var(--eiaaw-line-soft); }
            .lf-donut-fill { stroke: var(--eiaaw-primary); stroke-linecap: round; }

            /* Legend / breakdown caption (only the parts that have a reading) */
            .lf-eng-legend {
                display: flex; flex-wrap: wrap; gap: 4px 10px;
                font-family: var(--eiaaw-mono); font-size: 8.5px;
                letter-spacing: .08em; text-transform: uppercase;
                color: var(--eiaaw-mute);
            }
            .lf-eng-legend span { display: inline-flex; align-items: center; gap: 4px; }
            .lf-eng-legend i {
                width: 7px; height: 7px; border-radius: 2px; display: inline-block;
            }
            .lf-eng-legend .dot-likes { background: #EC4899; }
            .lf-eng-legend .dot-comments { background: #1FA896; }
            .lf-eng-legend .dot-shares { background: #6366F1; }
            .lf-eng-legend .dot-saves { background: #F59E0B; }

            /* Truthful no-data state */
            .lf-metrics-pending {
                display: flex; align-items: center; gap: 6px;
                padding: 7px 0 2px;
                border-top: 1px solid var(--eiaaw-line-soft);
                font-family: var(--eiaaw-mono); font-size: 9.5px;
                letter-spacing: .1em; text-transform: uppercase;
                color: var(--eiaaw-mute);
            }
            .lf-metrics-pending::before {
                content: ''; width: 5px; height: 5px; border-radius: 50%;
                background: var(--eiaaw-line); flex: none;
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
        $latestMetrics = $this->latestMetricsFor($posts);

        // Platforms where the lead counter is "views" instead of "impressions".
        $videoFirstPlatforms = ['tiktok', 'youtube', 'youtube_shorts'];

        // Compact number formatter — 12,400 -> "12.4K", 2,100,000 -> "2.1M".
        $fmtCount = function (?int $n): string {
            if ($n === null) return '—';
            if ($n < 1000) return (string) $n;
            if ($n < 10000) return number_format($n / 1000, 1) . 'K';
            if ($n < 1000000) return number_format($n / 1000) . 'K';
            return number_format($n / 1000000, 1) . 'M';
        };
    @endphp

    <div class="lf-shell" wire:poll.30s>
        <div class="lf-filters">
            <div>
                <label for="lf-search">Search captions</label>
                <input id="lf-search" type="search" wire:model.live.debounce.400ms="search" placeholder="text in caption..." />
            </div>
            <div>
                <label for="lf-status">Status</label>
                <select id="lf-status" wire:model.live="statusFilter">
                    <option value="">All</option>
                    <option value="published">Published</option>
                    <option value="publishing">Publishing</option>
                    <option value="unverified">Unverified</option>
                </select>
            </div>
            <div>
                <label for="lf-from">From</label>
                <input id="lf-from" type="date" wire:model.live="dateFrom" />
            </div>
            <div>
                <label for="lf-until">To</label>
                <input id="lf-until" type="date" wire:model.live="dateUntil" />
            </div>
            <div>
                <button type="button" class="lf-reset" wire:click="resetFilters">Reset</button>
            </div>
        </div>

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
                            @php
                                $metric = (! $isPublishing && ! $isUnverified) ? ($latestMetrics[$post->id] ?? null) : null;
                                $isVideoFirst = in_array($platform, $videoFirstPlatforms, true);
                                $leadVal = $isVideoFirst ? ($metric?->video_views) : ($metric?->impressions);
                                // Fall back to the other reach measure if the lead is null but the
                                // alternate exists (e.g. an IG reel where reach is null but views isn't).
                                if ($metric && $leadVal === null) {
                                    $leadVal = $isVideoFirst ? $metric->impressions : $metric->video_views;
                                }
                                $leadLbl = $isVideoFirst ? 'views' : 'reach';

                                // A "reading" exists if ANY real counter is present. Dormant rows
                                // (every counter NULL) are treated as no-data → pending state.
                                $hasAnyMetric = $metric && (
                                    $metric->impressions !== null || $metric->video_views !== null
                                    || $metric->reach !== null
                                    || $metric->likes !== null || $metric->comments !== null
                                    || $metric->shares !== null || $metric->saves !== null
                                );

                                // Stacked engagement bar: proportions of likes/comments/shares/saves.
                                // NULLs count as 0 for the proportion but are NOT shown as a reading.
                                $eLikes = (int) ($metric?->likes ?? 0);
                                $eComments = (int) ($metric?->comments ?? 0);
                                $eShares = (int) ($metric?->shares ?? 0);
                                $eSaves = (int) ($metric?->saves ?? 0);
                                $eTotal = $eLikes + $eComments + $eShares + $eSaves;
                                $pct = fn (int $part): float => $eTotal > 0 ? round($part / $eTotal * 100, 2) : 0.0;

                                // Engagement rate stored as a fraction (e.g. 0.0483). Render as %.
                                $engRate = $metric?->engagement_rate !== null ? (float) $metric->engagement_rate : null;
                                $engPct = $engRate !== null ? round($engRate * 100, 1) : null;
                                // Donut dash: circumference for r=6 ≈ 37.7. Cap fill at 100%.
                                $donutC = 37.7;
                                $donutFill = $engPct !== null ? min($engPct, 100) / 100 * $donutC : 0;

                                $tooltip = null;
                                if ($metric) {
                                    $age = $metric->observed_at?->diffForHumans(['parts' => 1, 'short' => true]);
                                    $tooltip = 'Updated ' . ($age ?? '—') . ' · source: ' . $metric->source;
                                }
                            @endphp
                            @if ($hasAnyMetric)
                                <div class="lf-metrics" title="{{ $tooltip }}">
                                    <div class="lf-chips">
                                        <div class="lf-chip lf-chip-lead">
                                            <span class="lf-chip-top">
                                                @if ($isVideoFirst)
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="6 4 20 12 6 20 6 4" fill="currentColor" stroke="none"/></svg>
                                                @else
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                                @endif
                                                <span class="lf-chip-lbl">{{ $leadLbl }}</span>
                                            </span>
                                            <span class="lf-chip-val {{ $leadVal === null ? 'is-dash' : '' }}">{{ $fmtCount($leadVal) }}</span>
                                        </div>
                                        <div class="lf-chip">
                                            <span class="lf-chip-top">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.6l-1-1a5.5 5.5 0 1 0-7.8 7.8l1 1L12 21l7.8-7.6 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                                                <span class="lf-chip-lbl">likes</span>
                                            </span>
                                            <span class="lf-chip-val {{ $metric->likes === null ? 'is-dash' : '' }}">{{ $fmtCount($metric->likes) }}</span>
                                        </div>
                                        <div class="lf-chip">
                                            <span class="lf-chip-top">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 8.5 8.5 0 0 1-3.8-.9L3 21l1.9-5.2A8.4 8.4 0 0 1 12 3a8.4 8.4 0 0 1 9 8.5z"/></svg>
                                                <span class="lf-chip-lbl">comments</span>
                                            </span>
                                            <span class="lf-chip-val {{ $metric->comments === null ? 'is-dash' : '' }}">{{ $fmtCount($metric->comments) }}</span>
                                        </div>
                                    </div>

                                    @if ($eTotal > 0 || $engPct !== null)
                                        <div class="lf-eng">
                                            @if ($eTotal > 0)
                                                <div class="lf-bar" aria-label="engagement breakdown">
                                                    @if ($eLikes > 0)<span class="seg-likes" style="width: {{ $pct($eLikes) }}%"></span>@endif
                                                    @if ($eComments > 0)<span class="seg-comments" style="width: {{ $pct($eComments) }}%"></span>@endif
                                                    @if ($eShares > 0)<span class="seg-shares" style="width: {{ $pct($eShares) }}%"></span>@endif
                                                    @if ($eSaves > 0)<span class="seg-saves" style="width: {{ $pct($eSaves) }}%"></span>@endif
                                                </div>
                                            @else
                                                <div class="lf-bar lf-bar-empty" aria-hidden="true"></div>
                                            @endif
                                            @if ($engPct !== null)
                                                <span class="lf-eng-rate" title="Engagement rate">
                                                    <svg class="lf-donut" viewBox="0 0 16 16">
                                                        <circle class="lf-donut-track" cx="8" cy="8" r="6" fill="none" stroke-width="2.5"/>
                                                        <circle class="lf-donut-fill" cx="8" cy="8" r="6" fill="none" stroke-width="2.5"
                                                                stroke-dasharray="{{ $donutFill }} {{ $donutC }}"/>
                                                    </svg>
                                                    {{ $engPct }}%
                                                </span>
                                            @endif
                                        </div>

                                        @if ($eTotal > 0)
                                            <div class="lf-eng-legend">
                                                @if ($eLikes > 0)<span><i class="dot-likes"></i>{{ $fmtCount($eLikes) }} likes</span>@endif
                                                @if ($eComments > 0)<span><i class="dot-comments"></i>{{ $fmtCount($eComments) }} cmts</span>@endif
                                                @if ($eShares > 0)<span><i class="dot-shares"></i>{{ $fmtCount($eShares) }} shares</span>@endif
                                                @if ($eSaves > 0)<span><i class="dot-saves"></i>{{ $fmtCount($eSaves) }} saves</span>@endif
                                            </div>
                                        @endif
                                    @endif
                                </div>
                            @elseif (! $isPublishing && ! $isUnverified)
                                <div class="lf-metrics-pending"
                                     title="Metrics are collected automatically once the platform reports engagement, or you can upload a CSV on the Performance page.">
                                    metrics pending
                                </div>
                            @endif
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

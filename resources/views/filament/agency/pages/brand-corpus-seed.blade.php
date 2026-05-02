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
                --eiaaw-primary-tint: #E5F4F1;
                --eiaaw-mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
            }
            .corpus-shell {
                background: var(--eiaaw-bg);
                border: 1px solid var(--eiaaw-line);
                border-radius: 16px;
                padding: 32px;
            }
            .corpus-meta {
                font-family: var(--eiaaw-mono);
                font-size: 11px;
                letter-spacing: 0.12em;
                text-transform: uppercase;
                color: var(--eiaaw-mute);
                margin-bottom: 8px;
            }
            .corpus-progress {
                display: flex;
                align-items: baseline;
                gap: 12px;
                margin-bottom: 24px;
            }
            .corpus-progress-num {
                font-weight: 600; font-size: 40px;
                letter-spacing: -0.04em; color: var(--eiaaw-ink);
                line-height: 1;
            }
            .corpus-card {
                background: white;
                border: 1px solid var(--eiaaw-line);
                border-radius: 12px;
                padding: 22px 24px;
                margin-bottom: 18px;
            }
            .corpus-card h3 {
                font-size: 16px; font-weight: 500;
                letter-spacing: -0.01em; color: var(--eiaaw-ink);
                margin: 0 0 6px;
            }
            .corpus-card p.lead {
                font-size: 13px; line-height: 1.55;
                color: var(--eiaaw-ink-2); margin: 0 0 16px;
                max-width: 65ch;
            }
            .corpus-textarea {
                width: 100%;
                min-height: 220px;
                padding: 14px 16px;
                border: 1px solid var(--eiaaw-line);
                border-radius: 10px;
                font-size: 13.5px; line-height: 1.55;
                font-family: inherit;
                color: var(--eiaaw-ink);
                background: var(--eiaaw-bg);
                resize: vertical;
            }
            .corpus-textarea:focus {
                outline: none;
                border-color: var(--eiaaw-ink);
                box-shadow: 0 0 0 3px var(--eiaaw-line-soft);
            }
            .corpus-cta {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 10px 18px; border-radius: 999px;
                background: var(--eiaaw-ink); color: var(--eiaaw-bg);
                font-size: 13px; font-weight: 500;
                text-decoration: none; cursor: pointer;
                border: 1px solid var(--eiaaw-ink);
                transition: all .25s cubic-bezier(.2,.7,.2,1);
                white-space: nowrap;
            }
            .corpus-cta:hover { background: var(--eiaaw-primary-dark); border-color: var(--eiaaw-primary-dark); transform: translateY(-1px); }
            .corpus-cta-ghost {
                background: transparent; color: var(--eiaaw-ink);
            }
            .corpus-cta-ghost:hover { background: var(--eiaaw-ink); color: var(--eiaaw-bg); }
            .corpus-tip {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: .04em;
                color: var(--eiaaw-mute);
                margin-top: 12px;
            }
            .corpus-divider {
                font-family: var(--eiaaw-mono);
                font-size: 11px; letter-spacing: .12em;
                text-transform: uppercase; color: var(--eiaaw-mute);
                text-align: center;
                margin: 8px 0 14px;
            }
        </style>
    @endpush

    @php
        $brand = $this->resolveBrand();
        $existing = $this->existingCount();
        $threshold = $this->readinessThreshold();
        $remaining = max(0, $threshold - $existing);
    @endphp

    <div class="corpus-shell">
        @if (! $brand)
            <div class="corpus-meta">No brand</div>
            <h2 style="font-size: 22px; font-weight: 500; letter-spacing: -0.02em; color: var(--eiaaw-ink); margin: 0 0 8px;">
                Create a brand first
            </h2>
            <p style="font-size: 14px; color: var(--eiaaw-ink-2); max-width: 60ch; margin: 0 0 24px;">
                The corpus belongs to a brand. Add your first brand on the Brands page, then return here.
            </p>
            <a href="{{ url('/agency/brands?action=create') }}" class="corpus-cta">
                Add a brand
                <span aria-hidden="true">→</span>
            </a>
        @else
            <div class="corpus-meta">{{ $brand->name }} · Corpus seeding</div>
            <div class="corpus-progress">
                <div class="corpus-progress-num">{{ $existing }}</div>
                <div style="font-size: 14px; color: var(--eiaaw-ink-2);">
                    items indexed · {{ $threshold }} needed for stage 03
                    @if ($remaining === 0)
                        <strong style="color: var(--eiaaw-primary-dark);"> · stage complete</strong>
                    @else
                        · <strong>{{ $remaining }}</strong> to go
                    @endif
                </div>
            </div>

            {{-- Path 1: paste real historical posts --}}
            <div class="corpus-card">
                <h3>Paste your historical posts</h3>
                <p class="lead">
                    Drop in 5+ of your strongest past captions — one post per blank-line block.
                    Each becomes a real corpus row, embedded into your brand's vector store, and
                    used by the Writer for grounded captions and by Compliance for dedup checks.
                </p>

                <textarea
                    wire:model="pasteText"
                    class="corpus-textarea"
                    placeholder="The five tools we ditched this quarter, and what we replaced them with…&#10;&#10;Most ‘AI strategy’ decks are slideware. Here’s what working AI ops looked like for us in April…&#10;&#10;You don’t need a 12-person growth team. You need one operator with a calendar and a brand voice. Here’s the receipt…"
                ></textarea>

                <div style="margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button"
                            class="corpus-cta"
                            wire:click="savePaste"
                            wire:loading.attr="disabled"
                            wire:target="savePaste">
                        <span wire:loading.remove wire:target="savePaste">
                            Save & embed posts
                            <span aria-hidden="true">→</span>
                        </span>
                        <span wire:loading wire:target="savePaste">Embedding…</span>
                    </button>
                    <span class="corpus-tip">Tip — paste your top performers, not your latest. Quality &gt; recency.</span>
                </div>
            </div>

            <div class="corpus-divider">— or, if you have nothing to paste yet —</div>

            {{-- Path 2: website chunks fallback --}}
            <div class="corpus-card">
                <h3>Use my website if I have nothing yet</h3>
                <p class="lead">
                    We'll fetch <strong>{{ $brand->website_url ?: '(no URL set)' }}</strong>, split it into paragraph
                    chunks, and embed each one. Lower-quality grounding than real posts (it's marketing copy, not voice
                    in the wild) — but it unblocks the wizard so the Writer has something to retrieve against.
                    You can always paste real posts later and the corpus grows.
                </p>

                <button type="button"
                        class="corpus-cta corpus-cta-ghost"
                        wire:click="seedFromWebsite"
                        wire:loading.attr="disabled"
                        wire:target="seedFromWebsite"
                        @if (empty($brand->website_url)) disabled style="opacity: .35; cursor: not-allowed;" @endif>
                    <span wire:loading.remove wire:target="seedFromWebsite">
                        Seed from website
                        <span aria-hidden="true">↻</span>
                    </span>
                    <span wire:loading wire:target="seedFromWebsite">Scraping & embedding…</span>
                </button>
            </div>

            <div style="margin-top: 24px; padding-top: 18px; border-top: 1px solid var(--eiaaw-line-soft); font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em; text-transform: uppercase; color: var(--eiaaw-mute);">
                Every row is real · embeddings via Voyage-3 · cost logged per workspace
            </div>
        @endif
    </div>
</x-filament-panels::page>

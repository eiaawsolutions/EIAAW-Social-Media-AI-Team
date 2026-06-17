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
            .corpus-field-label {
                display: block;
                font-size: 12px; font-weight: 500;
                letter-spacing: .01em; color: var(--eiaaw-ink-2);
                margin: 14px 0 6px;
            }
            .corpus-input {
                width: 100%;
                padding: 10px 12px;
                border: 1px solid var(--eiaaw-line);
                border-radius: 9px;
                font-size: 13.5px; line-height: 1.5;
                font-family: inherit;
                color: var(--eiaaw-ink);
                background: var(--eiaaw-bg);
            }
            .corpus-input:focus {
                outline: none;
                border-color: var(--eiaaw-ink);
                box-shadow: 0 0 0 3px var(--eiaaw-line-soft);
            }
            textarea.corpus-input { min-height: 72px; resize: vertical; }
            .corpus-loc-row {
                display: grid;
                grid-template-columns: 1.2fr 1fr 1.4fr auto auto;
                gap: 8px;
                align-items: center;
                margin-bottom: 8px;
            }
            @media (max-width: 720px) {
                .corpus-loc-row { grid-template-columns: 1fr 1fr; }
            }
            .corpus-loc-primary {
                display: inline-flex; align-items: center; gap: 6px;
                font-size: 11px; color: var(--eiaaw-mute);
                white-space: nowrap;
            }
            .corpus-loc-remove {
                border: none; background: transparent;
                color: var(--eiaaw-mute); cursor: pointer;
                font-size: 18px; line-height: 1; padding: 4px 8px;
                border-radius: 8px;
            }
            .corpus-loc-remove:hover { background: var(--eiaaw-line-soft); color: var(--eiaaw-ink); }
            .corpus-add-loc {
                display: inline-flex; align-items: center; gap: 6px;
                font-size: 12px; color: var(--eiaaw-primary-dark);
                background: transparent; border: 1px dashed var(--eiaaw-line);
                border-radius: 999px; padding: 7px 14px; cursor: pointer;
                margin-top: 4px;
            }
            .corpus-add-loc:hover { border-color: var(--eiaaw-primary); background: var(--eiaaw-primary-tint); }
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

            {{-- Business facts: operator-supplied locations + target audience.
                 Authoritative ground truth injected above the AI voice. --}}
            <div class="corpus-card">
                <h3>Tell us about your business</h3>
                <p class="lead">
                    Where you operate and who you're trying to reach. The Writer and Strategist
                    treat these as <strong>ground truth</strong> — they ground every caption and
                    plan in these locations and this audience, overriding anything inferred from
                    your website. Optional, but it sharpens the voice considerably.
                </p>

                <label class="corpus-field-label" for="bf-industry">Industry</label>
                <select id="bf-industry" class="corpus-input" wire:model="industry">
                    <option value="">Select your industry…</option>
                    @foreach (\App\Support\Compliance\IndustryCatalog::industries() as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
                <p style="font-size:12px;color:var(--eiaaw-mute);margin:4px 0 10px;">
                    Used to apply the advertising &amp; industry laws for your business's country to every post —
                    the AI plans, writes, and checks each post against the rules for this industry in the
                    jurisdiction of your primary location below.
                </p>

                <span class="corpus-field-label">Business locations</span>
                @forelse ($locations as $i => $loc)
                    <div class="corpus-loc-row" wire:key="loc-{{ $i }}">
                        <input type="text" class="corpus-input"
                               wire:model="locations.{{ $i }}.area"
                               placeholder="City / area (e.g. Kuala Lumpur)">
                        <input type="text" class="corpus-input"
                               wire:model="locations.{{ $i }}.country"
                               placeholder="Country">
                        <input type="text" class="corpus-input"
                               wire:model="locations.{{ $i }}.notes"
                               placeholder="Notes (e.g. flagship outlet)">
                        <label class="corpus-loc-primary">
                            <input type="checkbox" wire:model="locations.{{ $i }}.is_primary">
                            Primary
                        </label>
                        <button type="button" class="corpus-loc-remove"
                                wire:click="removeLocation({{ $i }})"
                                title="Remove location" aria-label="Remove location">&times;</button>
                    </div>
                @empty
                    <p style="font-size:12.5px;color:var(--eiaaw-mute);margin:0 0 8px;">
                        No locations yet — add the places your business operates from or serves.
                    </p>
                @endforelse
                <button type="button" class="corpus-add-loc" wire:click="addLocation">
                    <span aria-hidden="true">+</span> Add location
                </button>

                <label class="corpus-field-label" for="bf-audience-desc">Who is your target audience?</label>
                <textarea id="bf-audience-desc" class="corpus-input"
                          wire:model="audienceDescription"
                          placeholder="e.g. Time-poor urban professionals 25–40 who treat good coffee as a daily ritual and discover places on Instagram."></textarea>

                <label class="corpus-field-label" for="bf-audience-segments">Audience segments</label>
                <input id="bf-audience-segments" type="text" class="corpus-input"
                       wire:model="audienceSegmentsText"
                       placeholder="Comma-separated, e.g. Young professionals, Remote workers, Café-hoppers">

                <label class="corpus-field-label" for="bf-audience-geo">Geographic focus</label>
                <input id="bf-audience-geo" type="text" class="corpus-input"
                       wire:model="audienceGeoFocus"
                       placeholder="e.g. Klang Valley, Malaysia">

                <div style="margin-top: 16px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button"
                            class="corpus-cta"
                            wire:click="saveBrandFacts"
                            wire:loading.attr="disabled"
                            wire:target="saveBrandFacts">
                        <span wire:loading.remove wire:target="saveBrandFacts">
                            Save business details
                            <span aria-hidden="true">→</span>
                        </span>
                        <span wire:loading wire:target="saveBrandFacts">Saving…</span>
                    </button>
                    <span class="corpus-tip">Applies to every future post — edit any time.</span>
                </div>
            </div>

            {{-- Company / brand profile: authoritative grounding text + optional
                 archival source file. The pasted text is what the AI reads; the
                 file is stored durably for the operator's record only. --}}
            <div class="corpus-card">
                <h3>Your company / brand profile</h3>
                <p class="lead">
                    Paste your company or brand profile — <strong>positioning, products, brand voice,
                    and who you serve</strong>. The Writer and Strategist treat this as
                    <strong>authoritative ground truth</strong>, weighted above the AI-inferred voice and
                    surviving every voice refresh. Optionally attach the source document for your records —
                    <em>only the pasted text grounds the AI; the file is archived, not read.</em>
                </p>

                <label class="corpus-field-label" for="bf-company-profile">Profile text (this is what the AI reads)</label>
                <textarea id="bf-company-profile" class="corpus-textarea"
                          wire:model="companyProfile"
                          placeholder="e.g. ACME Coffee is a Klang Valley specialty-coffee brand…&#10;&#10;Positioning: the third-wave roaster for time-poor professionals who still want a real cup.&#10;&#10;Flagship products: single-origin pour-overs, the house cold brew, a monthly subscription box.&#10;&#10;Brand voice: warm, precise, never salesy — we talk like a knowledgeable barista, not an ad.&#10;&#10;Target audience: urban professionals 25–40 across the Klang Valley who discover places on Instagram."></textarea>

                <div style="margin-top: 14px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button"
                            class="corpus-cta"
                            wire:click="saveBrandFacts"
                            wire:loading.attr="disabled"
                            wire:target="saveBrandFacts">
                        <span wire:loading.remove wire:target="saveBrandFacts">
                            Save profile text
                            <span aria-hidden="true">→</span>
                        </span>
                        <span wire:loading wire:target="saveBrandFacts">Saving…</span>
                    </button>
                    <span class="corpus-tip">Saved with your business details · the AI reads this text.</span>
                </div>

                <label class="corpus-field-label" for="bf-profile-file">Optional: attach the source document (archived for your records)</label>
                <input id="bf-profile-file" type="file" class="corpus-input"
                       wire:model="profileFile"
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.txt,.md,.rtf,.odt,.csv,.xls,.xlsx">

                @error('profileFile')
                    <span class="corpus-tip" style="color:#b4413c;">{{ $message }}</span>
                @enderror

                @if ($brand->company_profile_file)
                    <div class="corpus-tip" style="margin-top:8px;">
                        Current file:
                        <a href="{{ $brand->company_profile_file['url'] }}" target="_blank" rel="noopener"
                           style="color:var(--eiaaw-primary-dark);text-decoration:underline;">
                            {{ $brand->company_profile_file['filename'] }}
                        </a>
                        ({{ number_format((($brand->company_profile_file['size'] ?? 0) / 1024), 0) }} KB)
                    </div>
                @endif

                <div style="margin-top: 12px;">
                    <button type="button"
                            class="corpus-cta corpus-cta-ghost"
                            wire:click="saveCompanyProfileFile"
                            wire:loading.attr="disabled"
                            wire:target="profileFile,saveCompanyProfileFile">
                        <span wire:loading.remove wire:target="profileFile,saveCompanyProfileFile">Upload document</span>
                        <span wire:loading wire:target="profileFile,saveCompanyProfileFile">Uploading…</span>
                    </button>
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

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
                --eiaaw-danger: #7E2C1B;
                --eiaaw-mono: 'JetBrains Mono', 'SFMono-Regular', Menlo, monospace;
            }
            .rw-grid { display: grid; grid-template-columns: 1fr; gap: 20px; }
            @media (min-width: 1024px) { .rw-grid { grid-template-columns: 1.15fr .85fr; } }
            .rw-card {
                background: white; border: 1px solid var(--eiaaw-line);
                border-radius: 14px; padding: 22px 24px;
            }
            .rw-card.warm { background: var(--eiaaw-bg); }
            .rw-meta {
                font-family: var(--eiaaw-mono); font-size: 11px; letter-spacing: .12em;
                text-transform: uppercase; color: var(--eiaaw-mute); margin-bottom: 10px;
            }
            .rw-h3 { font-size: 15px; font-weight: 600; color: var(--eiaaw-ink); margin: 0 0 12px; }
            .rw-textarea {
                width: 100%; min-height: 220px; padding: 14px 16px;
                border: 1px solid var(--eiaaw-line); border-radius: 10px;
                font-size: 14px; line-height: 1.55; font-family: inherit;
                color: var(--eiaaw-ink); background: var(--eiaaw-bg); resize: vertical;
            }
            .rw-textarea:focus { outline: none; border-color: var(--eiaaw-ink); box-shadow: 0 0 0 3px var(--eiaaw-line-soft); }
            .rw-input {
                width: 100%; padding: 10px 14px; border: 1px solid var(--eiaaw-line);
                border-radius: 10px; font-size: 13.5px; font-family: inherit;
                color: var(--eiaaw-ink); background: var(--eiaaw-bg);
            }
            .rw-input:focus { outline: none; border-color: var(--eiaaw-ink); box-shadow: 0 0 0 3px var(--eiaaw-line-soft); }
            .rw-counter { font-family: var(--eiaaw-mono); font-size: 11px; color: var(--eiaaw-mute); margin-top: 6px; }
            .rw-counter.over { color: var(--eiaaw-danger); font-weight: 600; }
            .rw-cta {
                display: inline-flex; align-items: center; gap: 8px; padding: 10px 18px;
                border-radius: 999px; background: var(--eiaaw-ink); color: var(--eiaaw-bg);
                font-size: 13px; font-weight: 500; cursor: pointer; border: 1px solid var(--eiaaw-ink);
                transition: all .25s cubic-bezier(.2,.7,.2,1); white-space: nowrap;
            }
            .rw-cta:hover { background: var(--eiaaw-primary-dark); border-color: var(--eiaaw-primary-dark); transform: translateY(-1px); }
            .rw-cta.ghost { background: transparent; color: var(--eiaaw-ink); }
            .rw-cta.ghost:hover { background: var(--eiaaw-ink); color: var(--eiaaw-bg); }
            .rw-chip {
                display: inline-flex; align-items: center; gap: 6px; padding: 7px 13px;
                border-radius: 999px; background: white; color: var(--eiaaw-ink-2);
                font-size: 12.5px; font-weight: 500; cursor: pointer;
                border: 1px solid var(--eiaaw-line); transition: all .2s;
            }
            .rw-chip:hover { border-color: var(--eiaaw-primary-dark); color: var(--eiaaw-primary-dark); background: var(--eiaaw-primary-tint); }
            .rw-presets { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
            .rw-transcript { display: flex; flex-direction: column; gap: 10px; margin-bottom: 14px; max-height: 280px; overflow-y: auto; }
            .rw-turn { padding: 10px 13px; border-radius: 10px; font-size: 13px; line-height: 1.5; }
            .rw-turn.user { background: var(--eiaaw-bg-warm); color: var(--eiaaw-ink-2); align-self: flex-end; max-width: 85%; }
            .rw-turn.assistant { background: var(--eiaaw-primary-tint); color: var(--eiaaw-ink); border: 1px solid #CDE9E3; max-width: 92%; }
            .rw-turn .who { font-family: var(--eiaaw-mono); font-size: 9.5px; letter-spacing: .14em; text-transform: uppercase; opacity: .6; display: block; margin-bottom: 3px; }
            .rw-proposal { border: 1px solid var(--eiaaw-primary); background: white; border-radius: 12px; padding: 16px 18px; margin-bottom: 14px; }
            .rw-proposal-body { font-size: 14px; line-height: 1.55; color: var(--eiaaw-ink); white-space: pre-wrap; margin-bottom: 12px; }
            .rw-proposal-note { font-size: 12px; color: var(--eiaaw-mute); margin-bottom: 12px; font-style: italic; }
            .rw-chatbox { display: flex; gap: 8px; align-items: flex-end; }
            .rw-empty { font-size: 12.5px; color: var(--eiaaw-mute); margin-bottom: 14px; line-height: 1.5; }
            .rw-footer { margin-top: 20px; display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
            .rw-back { font-size: 13px; color: var(--eiaaw-mute); text-decoration: none; }
            .rw-back:hover { color: var(--eiaaw-ink); }
        </style>
    @endpush

    <div class="rw-grid">
        {{-- LEFT: direct edit --}}
        <div class="rw-card">
            <div class="rw-meta">{{ ucfirst($platform) }} · direct edit</div>
            <h3 class="rw-h3">Caption</h3>
            <textarea
                wire:model.live="body"
                class="rw-textarea"
                maxlength="{{ $maxChars }}"
                placeholder="Write the caption…"></textarea>
            <div class="rw-counter {{ mb_strlen($body) > $maxChars ? 'over' : '' }}">
                {{ mb_strlen($body) }} / {{ $maxChars }} characters
                @if (mb_strlen($body) > $maxChars) · too long for {{ ucfirst($platform) }} @endif
            </div>

            <div style="margin-top: 18px;">
                <h3 class="rw-h3">Hashtags</h3>
                <input
                    type="text"
                    wire:model="hashtagsCsv"
                    class="rw-input"
                    placeholder="growth, ai, malaysia (comma-separated, no #)" />
                <div class="rw-counter">Without the #. Capped at 30, trimmed per platform at publish.</div>
            </div>

            <div class="rw-footer">
                <button type="button" class="rw-cta"
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save">
                    <span wire:loading.remove wire:target="save">Save &amp; re-run compliance <span aria-hidden="true">→</span></span>
                    <span wire:loading wire:target="save">Saving &amp; checking…</span>
                </button>
                <a href="{{ \App\Filament\Agency\Resources\Drafts\DraftResource::getUrl('index') }}" class="rw-back">Cancel</a>
            </div>
            <div class="rw-counter" style="margin-top: 12px;">
                Saving resets the draft to compliance check — banned phrases, brand voice, and grounding are re-verified before it can be approved.
            </div>
        </div>

        {{-- RIGHT: AI assist --}}
        <div class="rw-card warm">
            <div class="rw-meta">AI assist</div>
            <h3 class="rw-h3">Reword with AI</h3>

            <div class="rw-presets">
                @foreach (['shorten' => 'Shorten', 'punchier' => 'Punchier', 'more_formal' => 'More formal', 'fix_grammar' => 'Fix grammar'] as $key => $label)
                    <button type="button" class="rw-chip"
                            wire:click="runPreset('{{ $key }}')"
                            wire:loading.attr="disabled"
                            wire:target="runPreset('{{ $key }}'), sendChat">
                        {{ $label }}
                    </button>
                @endforeach
            </div>

            @if (empty($chatHistory))
                <div class="rw-empty">
                    Tap a quick action above, or type how you'd like the caption reframed —
                    e.g. “make it warmer and add a question hook”, “reframe for a B2B audience”.
                    The AI keeps every fact and never invents numbers.
                </div>
            @else
                <div class="rw-transcript">
                    @foreach ($chatHistory as $turn)
                        <div class="rw-turn {{ $turn['role'] === 'assistant' ? 'assistant' : 'user' }}">
                            <span class="who">{{ $turn['role'] === 'assistant' ? 'AI proposal' : 'You' }}</span>
                            {{ $turn['content'] }}
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- pending proposal --}}
            @if ($proposal !== null)
                <div class="rw-proposal">
                    <div class="rw-meta">Proposed rewrite</div>
                    <div class="rw-proposal-body">{{ $proposal }}</div>
                    @if ($proposalNote)
                        <div class="rw-proposal-note">{{ $proposalNote }}</div>
                    @endif
                    <div style="display: flex; gap: 8px;">
                        <button type="button" class="rw-cta" wire:click="acceptProposal">Use this</button>
                        <button type="button" class="rw-cta ghost" wire:click="discardProposal">Discard</button>
                    </div>
                </div>
            @endif

            {{-- chat box --}}
            <div class="rw-chatbox" wire:loading.class="opacity-60" wire:target="sendChat, runPreset">
                <input
                    type="text"
                    wire:model="chatInput"
                    wire:keydown.enter="sendChat"
                    class="rw-input"
                    placeholder="Ask the AI to reframe or reword…" />
                <button type="button" class="rw-cta"
                        wire:click="sendChat"
                        wire:loading.attr="disabled"
                        wire:target="sendChat, runPreset('shorten'), runPreset('punchier'), runPreset('more_formal'), runPreset('fix_grammar')">
                    <span wire:loading.remove wire:target="sendChat, runPreset('shorten'), runPreset('punchier'), runPreset('more_formal'), runPreset('fix_grammar')">Send</span>
                    <span wire:loading wire:target="sendChat, runPreset('shorten'), runPreset('punchier'), runPreset('more_formal'), runPreset('fix_grammar')">Thinking…</span>
                </button>
            </div>
        </div>
    </div>
</x-filament-panels::page>

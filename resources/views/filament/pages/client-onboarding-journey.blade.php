<x-filament-panels::page>
    @php($slides = $this->slides())
    @php($videoPrompt = $this->videoPrompt())

    @push('styles')
        <style>
            :root { --smt-teal: #11766A; --smt-teal-soft: rgba(17,118,106,.10); }

            /* ── Deck shell ── */
            .oj-deck {
                position: relative;
                border: 1px solid var(--gray-200, #e5e7eb);
                border-radius: 16px;
                overflow: hidden;
                background: #fff;
                box-shadow: 0 1px 3px rgba(0,0,0,.04);
            }
            .dark .oj-deck { background: #0c1413; border-color: rgba(255,255,255,.08); }

            .oj-stage {
                position: relative;
                aspect-ratio: 16 / 9;
                min-height: 420px;
                display: grid;
            }
            .oj-slide {
                grid-area: 1 / 1;
                display: flex; flex-direction: column;
                justify-content: center;
                padding: 48px 56px;
                opacity: 0; visibility: hidden;
                transform: translateY(8px);
                transition: opacity .28s ease, transform .28s ease;
                overflow-y: auto;
            }
            .oj-slide.is-active { opacity: 1; visibility: visible; transform: none; }

            .oj-eyebrow {
                font-size: 12px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
                color: var(--smt-teal); margin-bottom: 14px;
            }
            .oj-title {
                font-size: clamp(26px, 3.4vw, 44px); font-weight: 800; line-height: 1.08;
                letter-spacing: -.02em; color: var(--gray-900, #111827);
            }
            .dark .oj-title { color: #f4faf8; }
            .oj-sub { font-size: clamp(15px, 1.6vw, 20px); color: var(--gray-600, #4b5563); margin-top: 16px; line-height: 1.5; max-width: 60ch; }
            .dark .oj-sub { color: #aebdba; }
            .oj-foot { margin-top: auto; padding-top: 24px; font-size: 13px; color: var(--gray-400, #9ca3af); }

            /* ── Cover ── */
            .oj-cover {
                background:
                    radial-gradient(1200px 400px at 10% -10%, var(--smt-teal-soft), transparent 60%),
                    linear-gradient(180deg, #fff, #f7faf9);
            }
            .dark .oj-cover {
                background:
                    radial-gradient(1200px 400px at 10% -10%, rgba(17,118,106,.25), transparent 60%),
                    linear-gradient(180deg, #0c1413, #0a100f);
            }

            /* ── Journey map ── */
            .oj-journey-grid {
                display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px 28px;
                margin-top: 24px;
            }
            @media (max-width: 720px){ .oj-journey-grid{ grid-template-columns: 1fr; } }
            .oj-jrow { display: grid; grid-template-columns: 30px 1fr auto; gap: 12px; align-items: center; padding: 7px 0; border-bottom: 1px dashed var(--gray-200,#e5e7eb); }
            .dark .oj-jrow { border-color: rgba(255,255,255,.08); }
            .oj-jnum {
                width: 26px; height: 26px; border-radius: 999px; background: var(--smt-teal-soft);
                color: var(--smt-teal); display: flex; align-items: center; justify-content: center;
                font-weight: 800; font-size: 12px; font-family: ui-monospace, monospace;
            }
            .oj-jlabel { font-size: 14px; font-weight: 600; color: var(--gray-800,#1f2937); }
            .dark .oj-jlabel { color: #dce7e4; }
            .oj-jwho { font-size: 11px; font-weight: 600; color: var(--gray-400,#9ca3af); text-transform: uppercase; letter-spacing: .04em; white-space: nowrap; }

            /* ── Stage slide ── */
            .oj-stage-head { display: flex; align-items: center; gap: 16px; margin-bottom: 8px; }
            .oj-badge {
                width: 56px; height: 56px; border-radius: 16px; flex: none;
                display: flex; align-items: center; justify-content: center;
                font-size: 26px; font-weight: 900; font-family: ui-monospace, monospace;
                background: var(--smt-teal); color: #fff;
            }
            .oj-badge[data-tone="hq"]    { background: #1e40af; }
            .oj-badge[data-tone="agent"] { background: var(--smt-teal); }
            .oj-badge[data-tone="you"]   { background: #b45309; }
            .oj-badge[data-tone="auto"]  { background: #6d28d9; }
            .oj-cols { display: grid; grid-template-columns: 1.1fr .9fr; gap: 36px; margin-top: 20px; }
            @media (max-width: 820px){ .oj-cols{ grid-template-columns: 1fr; gap: 18px; } }
            .oj-body { font-size: clamp(15px,1.5vw,18px); line-height: 1.55; color: var(--gray-700,#374151); }
            .dark .oj-body { color: #c3d0cd; }
            .oj-actions-title { font-size: 12px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: var(--gray-400,#9ca3af); margin-bottom: 10px; }
            .oj-actions { list-style: none; padding: 0; margin: 0; display: grid; gap: 9px; }
            .oj-actions li { position: relative; padding-left: 24px; font-size: 14.5px; line-height: 1.45; color: var(--gray-700,#374151); }
            .dark .oj-actions li { color: #c3d0cd; }
            .oj-actions li::before {
                content: ''; position: absolute; left: 0; top: 7px; width: 9px; height: 9px;
                border-radius: 3px; background: var(--smt-teal);
            }
            .oj-proof {
                margin-top: 22px; padding: 14px 16px; border-radius: 12px;
                background: var(--smt-teal-soft); border: 1px solid rgba(17,118,106,.18);
                font-size: 13.5px; line-height: 1.5; color: #0c4f47;
            }
            .dark .oj-proof { color: #7fd8cb; border-color: rgba(17,118,106,.35); }
            .oj-proof strong { color: var(--smt-teal); }
            .dark .oj-proof strong { color: #34c0ac; }
            .oj-screen {
                margin-top: 14px; font-family: ui-monospace, monospace; font-size: 12px;
                color: var(--gray-400,#9ca3af);
            }
            .oj-screen b { color: var(--gray-600,#4b5563); font-weight: 600; }
            .dark .oj-screen b { color: #9fb0ad; }

            /* ── Recap ── */
            .oj-recap-list { list-style: none; padding: 0; margin: 22px 0 0; display: grid; gap: 12px; }
            .oj-recap-list li { display: grid; grid-template-columns: 24px 1fr; gap: 12px; align-items: start; font-size: 16px; line-height: 1.45; color: var(--gray-700,#374151); }
            .dark .oj-recap-list li { color: #c3d0cd; }
            .oj-tick { color: var(--smt-teal); font-weight: 900; }
            .oj-closing { margin-top: 22px; font-size: 15px; color: var(--gray-600,#4b5563); line-height: 1.55; max-width: 64ch; }
            .dark .oj-closing { color: #aebdba; }
            .oj-help { margin-top: 18px; font-size: 13.5px; color: var(--smt-teal); font-weight: 600; }

            /* ── Prompt slide ── */
            .oj-prompt-intro { font-size: 15px; color: var(--gray-600,#4b5563); line-height: 1.55; margin-top: 14px; max-width: 70ch; }
            .dark .oj-prompt-intro { color: #aebdba; }
            .oj-prompt-box {
                position: relative; margin-top: 18px;
                background: #0c1716; color: #cfe6e1; border-radius: 12px;
                padding: 18px 20px; font-family: ui-monospace, 'JetBrains Mono', monospace;
                font-size: 12px; line-height: 1.65; white-space: pre-wrap;
                max-height: 240px; overflow-y: auto;
                border: 1px solid rgba(255,255,255,.06);
            }
            .oj-prompt-copy {
                position: absolute; top: 12px; right: 12px;
                background: var(--smt-teal); color: #fff; border: 0; border-radius: 7px;
                padding: 6px 14px; font-size: 12px; font-weight: 600; cursor: pointer;
                font-family: ui-sans-serif, system-ui;
            }
            .oj-prompt-copy:hover { filter: brightness(1.1); }

            /* ── Controls ── */
            .oj-controls {
                display: flex; align-items: center; justify-content: space-between; gap: 16px;
                padding: 14px 20px; border-top: 1px solid var(--gray-200,#e5e7eb);
                background: var(--gray-50,#f9fafb);
            }
            .dark .oj-controls { background: rgba(255,255,255,.02); border-color: rgba(255,255,255,.08); }
            .oj-dots { display: flex; gap: 6px; flex-wrap: wrap; }
            .oj-dot { width: 9px; height: 9px; border-radius: 999px; background: var(--gray-300,#d1d5db); border: 0; padding: 0; cursor: pointer; transition: all .2s; }
            .oj-dot.is-active { background: var(--smt-teal); transform: scale(1.25); }
            .oj-nav { display: flex; align-items: center; gap: 8px; }
            .oj-counter { font-size: 12px; font-family: ui-monospace, monospace; color: var(--gray-400,#9ca3af); min-width: 56px; text-align: center; }
            .oj-btn {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 7px 14px; border-radius: 9px; font-size: 13px; font-weight: 600;
                border: 1px solid var(--gray-200,#e5e7eb); background: #fff; color: var(--gray-700,#374151);
                cursor: pointer;
            }
            .dark .oj-btn { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.1); color: #dce7e4; }
            .oj-btn:hover { border-color: var(--smt-teal); color: var(--smt-teal); }
            .oj-btn[disabled] { opacity: .4; cursor: not-allowed; }
            .oj-btn-present { background: var(--smt-teal); color: #fff; border-color: var(--smt-teal); }
            .oj-btn-present:hover { color: #fff; filter: brightness(1.08); }

            /* ── Fullscreen present mode ── */
            .oj-deck:fullscreen { border-radius: 0; display: flex; flex-direction: column; }
            .oj-deck:fullscreen .oj-stage { flex: 1; min-height: 0; aspect-ratio: auto; }
            .oj-deck:fullscreen .oj-slide { padding: 6vh 8vw; }

            .oj-hint { font-size: 12px; color: var(--gray-400,#9ca3af); margin-top: 10px; text-align: center; }
        </style>
    @endpush

    <div
        x-data="{
            i: 0,
            total: {{ count($slides) }},
            go(n){ this.i = Math.max(0, Math.min(this.total - 1, n)); },
            next(){ this.go(this.i + 1); },
            prev(){ this.go(this.i - 1); },
            present(){
                const el = this.$refs.deck;
                if (!document.fullscreenElement) { el.requestFullscreen?.(); }
                else { document.exitFullscreen?.(); }
            },
            copyPrompt(btn){
                const t = this.$refs.prompt?.dataset.prompt || '';
                navigator.clipboard.writeText(t).then(() => {
                    const o = btn.textContent; btn.textContent = 'Copied ✓';
                    setTimeout(() => btn.textContent = o, 1600);
                });
            }
        }"
        x-init="$watch('i', () => {})"
        @keydown.window.arrow-right.prevent="next()"
        @keydown.window.arrow-left.prevent="prev()"
        @keydown.window.p="present()"
    >
        <div class="oj-deck" x-ref="deck">
            <div class="oj-stage">
                @foreach ($slides as $idx => $s)
                    <section class="oj-slide @if($s['kind']==='cover') oj-cover @endif"
                             :class="{ 'is-active': i === {{ $idx }} }"
                             x-show="i === {{ $idx }}" x-cloak>

                        {{-- ── COVER ── --}}
                        @if ($s['kind'] === 'cover')
                            <div class="oj-eyebrow">{{ $s['eyebrow'] }}</div>
                            <h1 class="oj-title">{{ $s['title'] }}</h1>
                            <p class="oj-sub">{{ $s['subtitle'] }}</p>
                            <div class="oj-foot">{{ $s['footnote'] }}</div>

                        {{-- ── JOURNEY MAP ── --}}
                        @elseif ($s['kind'] === 'journey')
                            <div class="oj-eyebrow">{{ $s['eyebrow'] }}</div>
                            <h2 class="oj-title">{{ $s['title'] }}</h2>
                            <p class="oj-sub">{{ $s['lead'] }}</p>
                            <div class="oj-journey-grid">
                                @foreach ($s['steps'] as $step)
                                    <div class="oj-jrow">
                                        <span class="oj-jnum">{{ $step['n'] }}</span>
                                        <span class="oj-jlabel">{{ $step['label'] }}</span>
                                        <span class="oj-jwho">{{ $step['who'] }}</span>
                                    </div>
                                @endforeach
                            </div>

                        {{-- ── STAGE ── --}}
                        @elseif ($s['kind'] === 'stage')
                            <div class="oj-stage-head">
                                <div class="oj-badge" data-tone="{{ $s['tone'] }}">{{ $s['badge'] }}</div>
                                <div>
                                    <div class="oj-eyebrow" style="margin-bottom:6px;">{{ $s['eyebrow'] }}</div>
                                    <h2 class="oj-title" style="font-size:clamp(22px,2.6vw,34px);">{{ $s['title'] }}</h2>
                                </div>
                            </div>
                            <div class="oj-cols">
                                <div>
                                    <p class="oj-body">{{ $s['body'] }}</p>
                                    <div class="oj-proof"><strong>How you know it worked:</strong> {{ $s['proof'] }}</div>
                                    <div class="oj-screen">Screen: <b>{{ $s['screen'] }}</b></div>
                                </div>
                                <div>
                                    <div class="oj-actions-title">{{ $s['action_title'] }}</div>
                                    <ul class="oj-actions">
                                        @foreach ($s['actions'] as $a)
                                            <li>{{ $a }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            </div>

                        {{-- ── RECAP ── --}}
                        @elseif ($s['kind'] === 'recap')
                            <div class="oj-eyebrow">{{ $s['eyebrow'] }}</div>
                            <h2 class="oj-title">{{ $s['title'] }}</h2>
                            <ul class="oj-recap-list">
                                @foreach ($s['points'] as $p)
                                    <li><span class="oj-tick">✓</span><span>{{ $p }}</span></li>
                                @endforeach
                            </ul>
                            <p class="oj-closing">{{ $s['closing'] }}</p>
                            <p class="oj-help">{{ $s['help'] }}</p>

                        {{-- ── PROMPT ── --}}
                        @elseif ($s['kind'] === 'prompt')
                            <div class="oj-eyebrow">{{ $s['eyebrow'] }}</div>
                            <h2 class="oj-title">{{ $s['title'] }}</h2>
                            <p class="oj-prompt-intro">{{ $s['intro'] }}</p>
                            <div class="oj-prompt-box" x-ref="prompt" data-prompt="{{ e($videoPrompt) }}">{{ $videoPrompt }}<button
                                    type="button" class="oj-prompt-copy" @click="copyPrompt($el)">Copy script</button></div>
                        @endif
                    </section>
                @endforeach
            </div>

            {{-- ── Controls ── --}}
            <div class="oj-controls">
                <div class="oj-dots">
                    @foreach ($slides as $idx => $s)
                        <button type="button" class="oj-dot" :class="{ 'is-active': i === {{ $idx }} }"
                                @click="go({{ $idx }})" aria-label="Go to slide {{ $idx + 1 }}"></button>
                    @endforeach
                </div>
                <div class="oj-nav">
                    <button type="button" class="oj-btn" @click="prev()" :disabled="i === 0">← Prev</button>
                    <span class="oj-counter" x-text="(i + 1) + ' / ' + total"></span>
                    <button type="button" class="oj-btn" @click="next()" :disabled="i === total - 1">Next →</button>
                    <button type="button" class="oj-btn oj-btn-present" @click="present()">⤢ Present</button>
                </div>
            </div>
        </div>

        <div class="oj-hint">Arrow keys to navigate · press <strong>P</strong> for fullscreen · click a dot to jump · the last slide has a copy-ready video script</div>
    </div>
</x-filament-panels::page>

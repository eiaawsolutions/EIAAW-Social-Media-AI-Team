<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

/**
 * HQ-only onboarding journey deck.
 *
 * A self-contained, presentation-mode slide deck that walks a new client
 * through the exact SMT onboarding journey — the same 9-stage readiness
 * ladder that drives App\Services\Readiness\SetupReadiness, plus the
 * Metricool connect-link handoff (stage 0) that precedes it. Two uses:
 *
 *   1. Screen-share / present these slides live on an onboarding call.
 *      Arrow keys (or on-screen controls) advance slides; press "P" or the
 *      Present button for fullscreen.
 *
 *   2. Produce a reusable onboarding VIDEO for the clients page. The last
 *      slide carries a copy-paste-ready production prompt script (scene-by-
 *      scene, with on-screen actions, VO, and timing) you can hand to a
 *      video tool or an editor.
 *
 * Single source of truth: the slide content here mirrors the real stages in
 * SetupReadiness so the deck never drifts from what the customer actually
 * sees in /agency/setup-wizard. If a stage changes there, update $this->slides().
 *
 * Lives in the Admin (HQ) panel, super-admin only.
 */
class ClientOnboardingJourney extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Onboarding journey';
    protected static \UnitEnum|string|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 2; // just below platform onboarding
    protected static ?string $title = 'Client onboarding journey';
    protected static ?string $slug = 'onboarding-journey';
    protected string $view = 'filament.pages.client-onboarding-journey';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function getSubheading(): ?string
    {
        return 'Present these slides on an onboarding call, or use the production prompt on the last slide to make a reusable onboarding video for the clients page.';
    }

    /**
     * The deck. Each slide is self-describing so the Blade view stays dumb.
     * `kind` switches the layout: cover | journey | stage | recap | prompt.
     *
     * The `stage` slides are 1:1 with SetupReadiness detectors — same labels,
     * same order, same "what proves it's done" evidence language.
     *
     * @return array<int, array<string,mixed>>
     */
    public function slides(): array
    {
        return [
            // ── 00 · Cover ─────────────────────────────────────────────
            [
                'kind' => 'cover',
                'eyebrow' => 'EIAAW · Social Media Team',
                'title' => 'Your first week with SMT',
                'subtitle' => 'From signup to your first scheduled, compliance-checked post — in about 20 minutes of clicks. The agents do the rest.',
                'footnote' => 'A guided onboarding. Nothing here is fabricated — every step is verifiable in your own dashboard.',
            ],

            // ── 01 · The journey at a glance ──────────────────────────
            [
                'kind' => 'journey',
                'eyebrow' => 'The whole picture',
                'title' => 'Ten checkpoints. One next-action at a time.',
                'lead' => 'Your Setup Wizard always shows exactly one thing to do next. You never guess. When all ten are green, your AI social team is live and running on autopilot at the autonomy level you choose.',
                'steps' => [
                    ['n' => '0', 'label' => 'Social accounts connected', 'who' => 'You · secure link'],
                    ['n' => '1', 'label' => 'Brand profile created', 'who' => 'You · 1 min'],
                    ['n' => '2', 'label' => 'Brand voice synthesised', 'who' => 'Agent'],
                    ['n' => '3', 'label' => 'Brand corpus seeded', 'who' => 'You · optional'],
                    ['n' => '4', 'label' => 'Platform connected', 'who' => 'You · 2 min'],
                    ['n' => '5', 'label' => 'Autonomy lane decided', 'who' => 'You · 30 sec'],
                    ['n' => '6', 'label' => 'First calendar generated', 'who' => 'Agent'],
                    ['n' => '7', 'label' => 'First draft passes Compliance', 'who' => 'Agent'],
                    ['n' => '8', 'label' => 'First post scheduled', 'who' => 'You approve'],
                    ['n' => '9', 'label' => 'First real metric recorded', 'who' => 'Auto'],
                ],
            ],

            // ── 02 · Stage 0 — Metricool connect-link handoff ─────────
            [
                'kind' => 'stage',
                'badge' => '0',
                'tone' => 'hq',
                'eyebrow' => 'First · connect your social accounts',
                'title' => 'Connect your accounts with one secure link',
                'body' => 'SMT publishes your content and reads your metrics for you. We set up a secure space for your brand, then send you a private link to connect your own Instagram, Facebook, LinkedIn, TikTok, YouTube, Threads, X or Pinterest — no extra account, no extra login.',
                'action_title' => 'What happens',
                'actions' => [
                    'You sign up and land on the Platform Setup page.',
                    'You click "Request setup". That tells our team to create your brand\'s space.',
                    'You get a private connection link (it expires in 71 hours for security).',
                    'You open it, connect the accounts you publish to — each is a quick authorise — then click "I\'ve connected — check now".',
                ],
                'proof' => 'Green check the moment we detect your connected accounts — read live, not a guess. Until then, the rest of the product stays politely locked, so you can never get stuck halfway.',
                'screen' => '/agency/metricool-setup',
            ],

            // ── 03 · Stage 1 — Brand profile ──────────────────────────
            [
                'kind' => 'stage',
                'badge' => '1',
                'tone' => 'you',
                'eyebrow' => 'Your turn · about 1 minute',
                'title' => 'Create your brand profile',
                'body' => 'Tell us who the brand is: name, website, a sentence on what you do, and where your voice lives online (your site, your best posts, a brand deck).',
                'action_title' => 'What you do',
                'actions' => [
                    'Open the Setup Wizard — it loads here automatically on first login.',
                    'Add your brand name and website URL.',
                    'Paste links to your strongest existing content as "evidence sources".',
                ],
                'proof' => 'The brand record now exists in your workspace. One green check down.',
                'screen' => '/agency/setup-wizard',
            ],

            // ── 04 · Stage 2 — Brand voice ────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '2',
                'tone' => 'agent',
                'eyebrow' => 'The Onboarding agent · runs in ~1 minute',
                'title' => 'Your brand voice, synthesised',
                'body' => 'Click "Run Onboarding agent". It reads your evidence sources, distils how your brand actually sounds, and writes a brand-style guide — then embeds it so every future caption is grounded in your real voice, not a generic AI tone.',
                'action_title' => 'What the agent produces',
                'actions' => [
                    'A brand-style guide: tone, vocabulary, do/don\'t, signature phrases.',
                    'Real evidence quotes pulled from your own content as proof.',
                    'A searchable embedding so the Writer can retrieve your voice on demand.',
                ],
                'proof' => 'You see the version number, word count, and the exact quotes it learned from. Nothing invented.',
                'screen' => '/agency/setup-wizard',
            ],

            // ── 05 · Stage 3 — Corpus ─────────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '3',
                'tone' => 'you',
                'eyebrow' => 'Optional but recommended · 2 minutes',
                'title' => 'Seed your brand corpus',
                'body' => 'Paste a handful of your best historical posts (or let us pull chunks from your website). The more real examples the Writer can ground in, the more on-brand every caption sounds.',
                'action_title' => 'What you do',
                'actions' => [
                    'Open Brand corpus from the wizard.',
                    'Paste 5+ past posts, or seed directly from your website.',
                    'Each item is indexed so the Writer can reference it.',
                ],
                'proof' => 'A live count of indexed items. Five is the minimum that flips this green — more is better.',
                'screen' => '/agency/brand-corpus',
            ],

            // ── 06 · Stage 4 — Platforms ──────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '4',
                'tone' => 'you',
                'eyebrow' => 'Your turn · about 2 minutes',
                'title' => 'Confirm where SMT publishes',
                'body' => 'The accounts you connected via the secure link now appear here automatically — Instagram, LinkedIn, TikTok, X, Threads, Facebook, YouTube or Pinterest. Confirm the handle(s) this brand should publish to so the Scheduler can post on your behalf.',
                'action_title' => 'What you do',
                'actions' => [
                    'Open Platforms — your connected accounts are already listed.',
                    'Confirm the handle(s) you want this brand to use.',
                    'Connected more later? Re-check on Platform Setup and they appear here.',
                ],
                'proof' => 'Each connected platform shows its handle, e.g. "Instagram (@yourbrand)". Green check on the first one.',
                'screen' => '/agency/platforms',
            ],

            // ── 07 · Stage 5 — Autonomy ───────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '5',
                'tone' => 'you',
                'eyebrow' => 'The big choice · 30 seconds',
                'title' => 'Decide how much control you keep',
                'body' => 'This is the heart of SMT. Pick your default autonomy lane — and change it per platform any time.',
                'action_title' => 'Three lanes',
                'actions' => [
                    'Green — the agents publish automatically. Fully hands-off.',
                    'Amber — one human approves each post before it goes out.',
                    'Red — two humans must approve. Maximum control, for sensitive brands.',
                ],
                'proof' => 'Your chosen default lane is saved and shown. You\'re always in charge of the dial — start cautious, loosen as you build trust.',
                'screen' => '/agency/autonomy',
            ],

            // ── 08 · Stage 6 — Calendar ───────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '6',
                'tone' => 'agent',
                'eyebrow' => 'The Strategist agent · runs in a minute',
                'title' => 'Your first month, planned',
                'body' => 'Click "Run Strategist". It builds a full month of post ideas with a balanced content-pillar mix, format mix (image / video / carousel), and the right platform for each.',
                'action_title' => 'What the agent produces',
                'actions' => [
                    'A month of dated content ideas, each with a pillar and a format.',
                    'Platform targeting per entry — the right idea on the right channel.',
                    'A calendar you can review and edit before anything is written.',
                ],
                'proof' => 'You see the calendar label and the exact number of entries planned. Review it, tweak it, move on.',
                'screen' => '/agency/calendar-entries',
            ],

            // ── 09 · Stage 7 — First draft + Compliance ──────────────
            [
                'kind' => 'stage',
                'badge' => '7',
                'tone' => 'agent',
                'eyebrow' => 'Writer + Compliance · the safety net',
                'title' => 'Your first draft — checked before you ever see it',
                'body' => 'The Writer drafts a post (caption + image, and video where the format calls for it). Then the Compliance gate inspects it: brand-voice match, factual grounding, embargoes, duplicate detection, and image-DNA. A draft only reaches you if every check passes.',
                'action_title' => 'What the gate checks',
                'actions' => [
                    'Brand voice — does it actually sound like you?',
                    'Factual grounding — no invented claims or numbers.',
                    'Embargoes + dedup — nothing off-limits, nothing repeated.',
                    'Image-DNA — visuals stay on-brand.',
                ],
                'proof' => 'You see the platform, the lane, and the passing status. If a check fails, you get the exact reason and can iterate — no silent failures.',
                'screen' => '/agency/drafts',
            ],

            // ── 10 · Stage 8 — Schedule ───────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '8',
                'tone' => 'you',
                'eyebrow' => 'Your approval · one click',
                'title' => 'Approve, and your first post is live in the queue',
                'body' => 'A compliance-passed draft is ready. You approve it; it\'s queued for publishing through your own account. From here, your chosen autonomy lane takes over for everything that follows.',
                'action_title' => 'What you do',
                'actions' => [
                    'Open the draft, give it a final read.',
                    'Click Approve & schedule.',
                    'It\'s queued — and your autonomy lane runs the rest.',
                ],
                'proof' => 'You see the exact scheduled time and the queued status. Your first post is on the rails.',
                'screen' => '/agency/scheduled-posts',
            ],

            // ── 11 · Stage 9 — Metrics ────────────────────────────────
            [
                'kind' => 'stage',
                'badge' => '9',
                'tone' => 'auto',
                'eyebrow' => 'Automatic · the truth loop closes',
                'title' => 'Your first real metric, recorded',
                'body' => 'Once a post publishes, SMT reads the real platform result straight from your connected accounts — impressions, reach, likes, comments, shares. Every number on your Performance page is sourced. We never fabricate engagement; where a platform genuinely doesn\'t expose a metric, we leave it blank rather than guess.',
                'action_title' => 'What you get',
                'actions' => [
                    'Real per-post results, read automatically from your connected accounts.',
                    'A manual / CSV upload too, for anything not yet captured.',
                    'A Performance page with 7 / 30 / 90-day windows — all evidence-linked.',
                ],
                'proof' => 'Either a captured platform post id or a sourced upload. Tenth green check. Your AI social team is fully live.',
                'screen' => '/agency/performance',
            ],

            // ── 12 · Recap ────────────────────────────────────────────
            [
                'kind' => 'recap',
                'eyebrow' => 'You\'re live',
                'title' => 'From here, SMT runs — at the level you chose',
                'points' => [
                    'The Strategist keeps your calendar full.',
                    'The Writer drafts in your voice; Compliance checks every one.',
                    'Your autonomy lane decides what auto-publishes vs. waits for you.',
                    'Performance stays honest — real numbers only, always sourced.',
                ],
                'closing' => 'Tighten or loosen autonomy any time. Add brands. Connect more platforms. The Setup Wizard always shows your next best action.',
                'help' => 'Stuck on any step? The support chat (bottom-right of every screen) talks you through it, or reaches a human.',
            ],

            // ── 13 · Video production prompt ──────────────────────────
            [
                'kind' => 'prompt',
                'eyebrow' => 'For the clients page',
                'title' => 'Onboarding video — production prompt script',
                'intro' => 'Copy this into your video tool (or hand it to an editor). It is a complete scene-by-scene script: on-screen action, voiceover, and timing for a ~90-second screen-recorded walkthrough that mirrors these exact slides.',
            ],
        ];
    }

    /**
     * The full, copy-paste video production prompt. Kept as a method (not in
     * the view) so it's easy to version and so the Blade stays presentational.
     *
     * Written to be tool-agnostic: works as a brief for a human editor, a
     * screen-recording script for a Loom/Descript pass, or a generation prompt
     * for an AI video tool. Timing sums to ~90s.
     */
    public function videoPrompt(): string
    {
        return <<<'PROMPT'
TITLE: "Getting started with EIAAW SMT — your AI social team, set up in minutes"
FORMAT: Screen-recorded product walkthrough, 16:9, ~90 seconds.
VOICE: Calm, confident, friendly. Malaysian-neutral English. No hype, no jargon.
BRAND: Deep teal (#11766A) accents. Clean white UI. Inter font. Lower-third captions for each step.
MUSIC: Soft, optimistic, low under VO. Duck under voice.
RULE: Every claim shown on screen must be real product UI — no fabricated numbers, no fake dashboards.

──────────────────────────────────────────────────────────────────────
SCENE 1 — HOOK (0:00–0:08)
ON SCREEN: SMT logo on deep-teal, then quick montage: calendar filling, a draft writing itself, a green "Scheduled" badge.
VO: "Meet SMT — your AI social media team. Here's how to go from sign-up to your first scheduled post, in about twenty minutes."
LOWER THIRD: "EIAAW Social Media Team"

SCENE 2 — THE JOURNEY MAP (0:08–0:16)
ON SCREEN: The Setup Wizard with the ten-checkpoint ladder; one item highlighted at a time.
VO: "There's no guesswork. The Setup Wizard always shows exactly one next step — ten checkpoints, and your team is live."
LOWER THIRD: "One next-action at a time"

SCENE 3 — CONNECT YOUR ACCOUNTS (0:16–0:26)
ON SCREEN: Platform Setup page (per-brand card). Cursor clicks "Request setup" → a secure connection link → quick authorise of Instagram + LinkedIn → back on the card, click "I've connected — check now" → green "Connected" badge with the network chips.
VO: "First, connect your social accounts with one secure link — no extra login. Authorise the platforms you post to, click check, and we detect them live. Connected."
LOWER THIRD: "Step 0 · Connect with one secure link"

SCENE 4 — BRAND PROFILE + VOICE (0:26–0:40)
ON SCREEN: Type a brand name + website, paste two evidence links. Click "Run Onboarding agent". Show the brand-style result with version + evidence quotes.
VO: "Add your brand and a few links to your best content. The Onboarding agent learns how you actually sound — and shows you the real quotes it learned from. No generic AI voice."
LOWER THIRD: "Steps 1–2 · Your brand, your voice"

SCENE 5 — PLATFORMS + AUTONOMY (0:40–0:55)
ON SCREEN: Connect Instagram (show "@handle"). Then the autonomy screen — hover Green, Amber, Red; select one.
VO: "Connect where you want to post. Then choose how much control you keep: green publishes automatically, amber waits for one approval, red needs two. Change it any time."
LOWER THIRD: "Steps 4–5 · You hold the dial"

SCENE 6 — CALENDAR + DRAFT + COMPLIANCE (0:55–1:12)
ON SCREEN: Click "Run Strategist" → a month of calendar entries appears. Then a draft writes; the Compliance panel shows checks passing (brand voice, grounding, dedup, image-DNA).
VO: "The Strategist plans your month. The Writer drafts in your voice — and every post is checked for brand fit, facts, and originality before it ever reaches you."
LOWER THIRD: "Steps 6–7 · Planned and checked"

SCENE 7 — APPROVE + SCHEDULE + METRICS (1:12–1:24)
ON SCREEN: Click "Approve & schedule" → "Scheduled for ..." badge. Cut to Performance page with a real captured result (impressions / reach / likes).
VO: "Approve with one click, and it's queued. After it publishes, SMT reads the real result straight from your connected accounts — sourced numbers only, never invented."
LOWER THIRD: "Steps 8–9 · Live, and honest"

SCENE 8 — CLOSE (1:24–1:30)
ON SCREEN: The wizard, all ten checks green. Support chat bubble pulses bottom-right.
VO: "That's it — your AI social team is live, at the level of control you chose. Need a hand? The support chat is on every screen."
LOWER THIRD: "Ready when you are · smt.eiaawsolutions.com"

──────────────────────────────────────────────────────────────────────
PRODUCTION NOTES
• Record at 1920×1080, 60fps if possible; slow cursor movements; pause 1s on each green check.
• Caption everything (accessibility + silent autoplay on the clients page).
• Keep all on-screen data realistic — use a demo workspace with genuine sample content, not mock-ups.
• Export a 16:9 master for the clients page and a 9:16 vertical cut (Scenes 1, 3, 5, 7) for social.
PROMPT;
    }
}

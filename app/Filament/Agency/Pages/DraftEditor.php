<?php

namespace App\Filament\Agency\Pages;

use App\Agents\ComplianceAgent;
use App\Agents\Prompts\WriterPrompt;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Content\RewordAssistant;
use App\Services\Content\RewordPrompt;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

/**
 * Draft caption editor — direct edit + AI assist (free-form chat + quick
 * presets). Reached only via the "Edit / AI assist" row action on the Drafts
 * table (carries ?draft=ID); never appears in the sidebar.
 *
 * On save (direct OR accepted-AI — same path), the draft is reset to
 * compliance_pending and ComplianceAgent re-runs synchronously, so an edited
 * caption can never skip the publish gate (banned phrase / brand voice /
 * grounding / dedup).
 *
 * SECURITY — IDOR: the ?draft id is attacker-controllable and this is a custom
 * Page, NOT covered by the resource getEloquentQuery() tenant gate. We re-scope
 * to the current workspace on mount AND on every write method (the id rehydrates
 * from the Livewire snapshot), via resolveDraftOrAbort(). Another tenant's id
 * returns 404, never their data.
 *
 * @property-read \App\Models\Draft|null $loadedDraft
 */
class DraftEditor extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'drafts/edit';
    protected static ?string $title = 'Edit draft';
    protected string $view = 'filament.agency.pages.draft-editor';

    /** Statuses whose caption may be edited. Mirrors the row-action ->visible() gate. */
    private const EDITABLE_STATUSES = ['awaiting_approval', 'compliance_failed', 'compliance_pending', 'approved'];

    // ── Livewire-safe scalar state ──────────────────────────────────────
    public ?int $recordId = null;
    public string $platform = '';
    public int $maxChars = 1000;

    public string $body = '';
    public string $hashtagsCsv = '';

    /** @var array<int, array{role: string, content: string}> */
    public array $chatHistory = [];
    public string $chatInput = '';

    public ?string $proposal = null;
    public ?string $proposalNote = null;

    public function mount(): void
    {
        $this->recordId = request()->integer('draft') ?: null;
        $draft = $this->resolveDraftOrAbort();

        $this->platform = (string) $draft->platform;
        $this->maxChars = WriterPrompt::PLATFORM_LIMITS[$this->platform] ?? 1000;
        $this->body = (string) $draft->body;
        $this->hashtagsCsv = implode(', ', is_array($draft->hashtags) ? $draft->hashtags : []);
    }

    public function getTitle(): string
    {
        return $this->recordId
            ? "Edit draft #{$this->recordId} — " . ucfirst($this->platform)
            : 'Edit draft';
    }

    // ── AI assist ───────────────────────────────────────────────────────

    /** Free-form chat instruction → one rewrite proposal. */
    public function sendChat(): void
    {
        $instruction = trim($this->chatInput);
        if ($instruction === '') {
            return;
        }
        $this->chatInput = '';
        $this->runReword($instruction, appendUserTurn: $instruction);
    }

    /** Quick-preset button → fixed instruction → one rewrite proposal. */
    public function runPreset(string $key): void
    {
        if (! RewordPrompt::isPreset($key)) {
            return;
        }
        $instruction = RewordPrompt::presetInstruction($key);
        // Show a friendly label in the transcript rather than the raw instruction.
        $label = ucwords(str_replace('_', ' ', $key));
        $this->runReword($instruction, appendUserTurn: $label);
    }

    private function runReword(string $instruction, string $appendUserTurn): void
    {
        @set_time_limit(60);

        $draft = $this->resolveDraftOrAbort();
        $this->chatHistory[] = ['role' => 'user', 'content' => $appendUserTurn];

        try {
            $result = app(RewordAssistant::class)->reword(
                brand: $draft->brand,
                workspace: $draft->brand->workspace,
                surface: RewordPrompt::SURFACE_CAPTION,
                currentText: $this->body,
                instruction: $instruction,
                chatHistory: $this->chatHistory,
                maxChars: $this->maxChars,
                platform: $this->platform,
                brandVoiceSnippet: $this->brandVoiceSnippet($draft),
            );
        } catch (\Throwable $e) {
            Log::warning('DraftEditor: reword failed', [
                'draft_id' => $this->recordId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title("Couldn't rewrite that")
                ->body('Try rephrasing your request, or edit the caption directly below.')
                ->warning()
                ->send();

            return;
        }

        $this->proposal = $result->rewrittenText;
        $this->proposalNote = $result->note ?: null;
        $this->chatHistory[] = ['role' => 'assistant', 'content' => $result->rewrittenText];
    }

    /** Accept the pending proposal into the editable body (does NOT save). */
    public function acceptProposal(): void
    {
        if ($this->proposal === null) {
            return;
        }
        $this->body = self::clampForDisplay($this->proposal, $this->maxChars);
        $this->proposal = null;
        $this->proposalNote = null;
    }

    /** Discard the pending proposal; body + transcript untouched. */
    public function discardProposal(): void
    {
        $this->proposal = null;
        $this->proposalNote = null;
    }

    // ── Save ────────────────────────────────────────────────────────────

    public function save(): void
    {
        $draft = $this->resolveDraftOrAbort();

        $body = trim($this->body);
        if ($body === '') {
            Notification::make()->title('Caption is empty')->warning()->send();

            return;
        }
        if (mb_strlen($body) > $this->maxChars) {
            Notification::make()
                ->title('Caption is too long')
                ->body("Trim to {$this->maxChars} characters for " . ucfirst($this->platform) . '.')
                ->warning()
                ->send();

            return;
        }

        // An approved draft with a live scheduled post must be unscheduled first
        // — resetting it to compliance_pending would strand a queued post.
        if ($draft->status === 'approved' && $draft->scheduledPosts()
            ->whereIn('status', ['queued', 'submitting', 'submitted', 'published'])
            ->exists()
        ) {
            Notification::make()
                ->title('Unschedule this post first')
                ->body('This draft already has a queued post. Cancel it on the Schedule page before editing.')
                ->warning()
                ->send();

            return;
        }

        $hashtags = $this->parseHashtags($this->hashtagsCsv);
        $newBody = mb_substr($body, 0, $this->maxChars);

        // Did the wording actually change? $draft is freshly loaded, so
        // $draft->body is still the STORED (pre-edit) caption here. Compare by
        // content fingerprint so a cosmetic re-save (whitespace only) doesn't
        // needlessly invalidate good distillations or trigger a paid re-distill.
        $bodyChanged = Draft::hashBody($draft->body) !== Draft::hashBody($newBody);

        $update = [
            'body' => $newBody,
            'hashtags' => $hashtags,
            'status' => 'compliance_pending',
        ];

        if ($bodyChanged) {
            // The image/video are built from distilled signals cached on the
            // draft (branding_payload.quote/voiceover/poster_*, platform_payload
            // headline/cta/carousel_slides) — NOT from the live body. Clearing
            // them forces DesignerAgent/VideoAgent to re-distil from the edited
            // caption on the next "Generate image/video", so the new media is
            // on-message instead of illustrating the pre-edit text. Dropping the
            // media_body_hash also marks any existing still as stale, so video
            // regenerates its keyframe from the new caption.
            $update['branding_payload'] = null;
            $update['platform_payload'] = self::stripDerivedCopyFields($draft->platform_payload);
        }

        $draft->update($update);

        // Re-run the compliance gate synchronously (same pattern as the Drafts
        // table "Re-run Compliance" action). FPM request → @set_time_limit is
        // safe here (NOT a queued job).
        @set_time_limit(180);
        try {
            $cr = app(ComplianceAgent::class)->run($draft->brand, ['draft_id' => $draft->id]);
        } catch (\Throwable $e) {
            Log::warning('DraftEditor: compliance re-run crashed after edit', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Saved, but compliance crashed')
                ->body('Your edit is saved. Open the draft and click "Re-run Compliance" to retry.')
                ->warning()
                ->send();
            $this->redirect(\App\Filament\Agency\Resources\Drafts\DraftResource::getUrl('index'));

            return;
        }

        $passed = ! empty($cr->data['all_passed']);
        Notification::make()
            ->title($passed ? 'Saved — compliance passed' : 'Saved — compliance held it')
            ->body('Status: ' . str_replace('_', ' ', (string) ($cr->data['new_status'] ?? '?')))
            ->color($passed ? 'success' : 'warning')
            ->send();

        $this->redirect(\App\Filament\Agency\Resources\Drafts\DraftResource::getUrl('index'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Load the draft scoped to the current workspace, or abort. Called from
     * mount AND every write method — $recordId persists in the Livewire snapshot
     * across requests, so trusting it once is not enough (IDOR).
     */
    private function resolveDraftOrAbort(): Draft
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id ?? $user?->ownedWorkspaces()->value('id');
        abort_unless($workspaceId, 403);
        abort_unless($this->recordId, 404);

        $draft = Draft::whereKey($this->recordId)
            ->whereHas('brand', fn ($q) => $q->whereNull('archived_at')->where('workspace_id', $workspaceId))
            ->first();
        abort_unless($draft, 404); // another tenant's id => 404, never their data
        abort_unless(in_array($draft->status, self::EDITABLE_STATUSES, true), 403);

        return $draft;
    }

    /** The brand-style markdown used as the voice reference for the rewrite. */
    private function brandVoiceSnippet(Draft $draft): ?string
    {
        $style = $draft->brand?->currentStyle()->first();
        $md = trim((string) ($style?->content_md ?? ''));
        if ($md === '') {
            return null;
        }

        // Keep the reference bounded — the voice spine is in the first section.
        return mb_substr($md, 0, 2000);
    }

    /** Normalise the comma-separated hashtag field to the stored array shape. */
    private function parseHashtags(string $csv): array
    {
        $parts = preg_split('/[,\n]+/u', $csv) ?: [];
        $tags = [];
        foreach ($parts as $p) {
            $t = ltrim(trim($p), '#');
            if ($t !== '') {
                $tags[] = $t;
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, 30);
    }

    /**
     * Remove the Writer-derived copy fields from platform_payload so an edited
     * caption can't carry a stale headline / CTA / carousel arc into the next
     * image generation. Preserves any non-derived keys (future-proofing); when
     * nothing else remains, returns null so the column reads empty.
     *
     * @param  mixed  $payload  the draft's current platform_payload (array|null)
     */
    private static function stripDerivedCopyFields(mixed $payload): ?array
    {
        if (! is_array($payload)) {
            return null;
        }

        foreach (['headline', 'cta', 'carousel_slides', 'hook_pattern'] as $derived) {
            unset($payload[$derived]);
        }

        return $payload === [] ? null : $payload;
    }

    private static function clampForDisplay(string $text, int $cap): string
    {
        return $cap > 0 ? mb_substr($text, 0, $cap) : $text;
    }
}

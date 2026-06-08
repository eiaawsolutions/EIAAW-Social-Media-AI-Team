<?php

namespace App\Filament\Agency\Pages;

use App\Models\BrandAsset;
use App\Services\Content\RewordAssistant;
use App\Services\Content\RewordPrompt;
use App\Services\Imagery\BrandAssetTagger;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

/**
 * Brand asset description editor — direct edit + AI assist (free-form chat +
 * quick presets) for the asset's description + tags. Reached only via the
 * "Edit / AI assist" row action on the Asset library table (carries ?asset=ID);
 * never in the sidebar.
 *
 * On save, the asset's description/tags are updated and the embedding is
 * RE-EMBEDDED (embed only, NO Claude vision) so the Designer/Video picker's
 * semantic match follows the new words. This is the key difference from the
 * table "Re-tag" action, which re-runs the whole vision pass.
 *
 * SECURITY — IDOR: the ?asset id is attacker-controllable and this custom Page
 * is NOT covered by the resource tenant gate. resolveAssetOrAbort() re-scopes to
 * the current workspace on mount AND every write method (the id rehydrates from
 * the snapshot). Another tenant's id returns 404.
 */
class BrandAssetEditor extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $slug = 'asset-library/edit';
    protected static ?string $title = 'Edit asset';
    protected string $view = 'filament.agency.pages.brand-asset-editor';

    // ── Livewire-safe scalar state ──────────────────────────────────────
    public ?int $recordId = null;
    public int $wordCap = RewordPrompt::ASSET_DESCRIPTION_WORD_CAP;

    public string $description = '';
    public string $tagsCsv = '';

    /** @var array<int, array{role: string, content: string}> */
    public array $chatHistory = [];
    public string $chatInput = '';

    public ?string $proposal = null;
    public ?string $proposalNote = null;

    public function mount(): void
    {
        $this->recordId = request()->integer('asset') ?: null;
        $asset = $this->resolveAssetOrAbort();

        $this->description = (string) $asset->description;
        $this->tagsCsv = implode(', ', is_array($asset->tags) ? $asset->tags : []);
    }

    public function getTitle(): string
    {
        return $this->recordId ? "Edit asset #{$this->recordId}" : 'Edit asset';
    }

    /** Thumbnail for the editor view (re-scoped). Null when bytes are gone. */
    public function assetPreviewUrl(): ?string
    {
        $asset = $this->resolveAssetOrAbort();

        return $asset->displayUrl();
    }

    public function assetIsVideo(): bool
    {
        return $this->resolveAssetOrAbort()->isVideo();
    }

    // ── AI assist ───────────────────────────────────────────────────────

    public function sendChat(): void
    {
        $instruction = trim($this->chatInput);
        if ($instruction === '') {
            return;
        }
        $this->chatInput = '';
        $this->runReword($instruction, appendUserTurn: $instruction);
    }

    public function runPreset(string $key): void
    {
        if (! RewordPrompt::isPreset($key)) {
            return;
        }
        $instruction = RewordPrompt::presetInstruction($key);
        $label = ucwords(str_replace('_', ' ', $key));
        $this->runReword($instruction, appendUserTurn: $label);
    }

    private function runReword(string $instruction, string $appendUserTurn): void
    {
        @set_time_limit(60);

        $asset = $this->resolveAssetOrAbort();
        $this->chatHistory[] = ['role' => 'user', 'content' => $appendUserTurn];

        try {
            $result = app(RewordAssistant::class)->reword(
                brand: $asset->brand,
                workspace: $asset->brand->workspace,
                surface: RewordPrompt::SURFACE_ASSET_DESCRIPTION,
                currentText: $this->description,
                instruction: $instruction,
                chatHistory: $this->chatHistory,
            );
        } catch (\Throwable $e) {
            Log::warning('BrandAssetEditor: reword failed', [
                'asset_id' => $this->recordId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title("Couldn't rewrite that")
                ->body('Try rephrasing your request, or edit the description directly.')
                ->warning()
                ->send();

            return;
        }

        $this->proposal = $result->rewrittenText;
        $this->proposalNote = $result->note ?: null;
        $this->chatHistory[] = ['role' => 'assistant', 'content' => $result->rewrittenText];
    }

    public function acceptProposal(): void
    {
        if ($this->proposal === null) {
            return;
        }
        $this->description = $this->proposal;
        $this->proposal = null;
        $this->proposalNote = null;
    }

    public function discardProposal(): void
    {
        $this->proposal = null;
        $this->proposalNote = null;
    }

    // ── Save ────────────────────────────────────────────────────────────

    public function save(): void
    {
        $asset = $this->resolveAssetOrAbort();

        $description = trim($this->description);
        if ($description === '') {
            Notification::make()->title('Description is empty')->warning()->send();

            return;
        }

        $asset->update([
            'description' => RewordAssistant::clampToWords($description, $this->wordCap),
            'tags' => $this->parseTags($this->tagsCsv),
        ]);

        // Re-embed from the edited text — embed only, NO Claude vision call.
        @set_time_limit(120);
        try {
            app(BrandAssetTagger::class)->reembed($asset->fresh());
        } catch (\Throwable $e) {
            Log::warning('BrandAssetEditor: re-embed failed after edit', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Saved, but re-embedding failed')
                ->body('Your text is saved. The semantic picker may lag until the next re-tag. We have been alerted.')
                ->warning()
                ->send();
            $this->redirect(\App\Filament\Agency\Resources\BrandAssets\BrandAssetResource::getUrl('index'));

            return;
        }

        Notification::make()
            ->title('Saved & re-embedded')
            ->body('The Designer + Video agents will match on the new description.')
            ->success()
            ->send();

        $this->redirect(\App\Filament\Agency\Resources\BrandAssets\BrandAssetResource::getUrl('index'));
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Load the asset scoped to the current workspace, or abort. Called from
     * mount AND every write method (IDOR — $recordId persists across requests).
     */
    private function resolveAssetOrAbort(): BrandAsset
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id ?? $user?->ownedWorkspaces()->value('id');
        abort_unless($workspaceId, 403);
        abort_unless($this->recordId, 404);

        $asset = BrandAsset::whereKey($this->recordId)
            ->whereNull('archived_at')
            ->whereHas('brand', fn ($q) => $q->whereNull('archived_at')->where('workspace_id', $workspaceId))
            ->first();
        abort_unless($asset, 404); // another tenant's id => 404, never their data

        return $asset;
    }

    /** Normalise the comma-separated tag field to the stored array shape. */
    private function parseTags(string $csv): array
    {
        $parts = preg_split('/[,\n]+/u', $csv) ?: [];
        $tags = [];
        foreach ($parts as $p) {
            $t = trim(strtolower($p));
            if ($t !== '') {
                $tags[] = $t;
            }
        }

        return array_values(array_unique($tags));
    }
}

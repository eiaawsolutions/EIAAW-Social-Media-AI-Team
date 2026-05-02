<?php

namespace App\Filament\Agency\Pages;

use App\Models\Brand;
use App\Models\BrandCorpusItem;
use App\Models\Workspace;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Readiness\SetupReadiness;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Stage 03 — Brand corpus seed.
 *
 * v1 ships with two real, working paths because Blotato (our v1 publishing
 * integration) has no read API for historical posts and first-party platform
 * OAuth is a v2 milestone (Meta App Review etc.):
 *
 *   1. Manual paste: customer drops 5+ of their best historical posts into a
 *      textarea (one post per blank-line block). Each becomes a
 *      brand_corpus row with source_type='historical_post', embedded into
 *      pgvector for the Writer's RAG retrieval and the Compliance agent's
 *      dedup check.
 *
 *   2. Website fallback: the brand's website_url is fetched, split into
 *      paragraph chunks, and each chunk is embedded as
 *      source_type='website_chunk'. Lower-quality grounding than real posts
 *      but unblocks the wizard for brands that have not yet posted publicly.
 *
 * Both actions lift set_time_limit(180) — same FPM-timeout fix we applied to
 * SetupWizard::runStage. The OnboardingAgent fix proved a 30s FPM ceiling
 * kills sequential outbound HTTP (scrape + Claude + embed) mid-flight.
 *
 * v2 follow-up (logged in memory/followups.md): direct first-party OAuth
 * pull from Instagram Graph / Facebook Pages / LinkedIn UGC / TikTok Display
 * APIs to backfill real post history automatically.
 */
class BrandCorpusSeed extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationLabel = 'Brand corpus';
    protected static ?string $title = 'Brand corpus';
    protected static ?int $navigationSort = 5;
    protected static ?string $slug = 'brand-corpus';
    protected string $view = 'filament.agency.pages.brand-corpus-seed';

    /** Livewire-safe scalar state. */
    public ?int $brand = null;
    public string $pasteText = '';

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
    }

    public function resolveBrand(): ?Brand
    {
        $user = auth()->user();
        if (! $user) return null;

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) return null;

        if ($this->brand) {
            $b = Brand::where('workspace_id', $ws->id)->find($this->brand);
            if ($b) return $b;
        }

        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->first();
    }

    public function existingCount(): int
    {
        $b = $this->resolveBrand();
        return $b ? BrandCorpusItem::where('brand_id', $b->id)->count() : 0;
    }

    public function readinessThreshold(): int
    {
        return 5;
    }

    /**
     * Persist pasted historical posts. One post per blank-line-separated block.
     * Trims, drops empties, requires ≥1 non-empty block. Embeds in a single
     * Voyage call (batched) to amortize the API cost.
     */
    public function savePaste(): void
    {
        @set_time_limit(180);

        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to seed')->danger()->send();
            return;
        }

        $blocks = $this->splitPasteBlocks($this->pasteText);
        if (count($blocks) === 0) {
            Notification::make()
                ->title('Nothing to save')
                ->body('Paste at least one post — separate multiple posts with a blank line.')
                ->warning()
                ->send();
            return;
        }

        try {
            $vectors = app(EmbeddingService::class)->embedMany(
                texts: $blocks,
                brand: $brand,
                workspace: $brand->workspace,
            );
        } catch (\Throwable $e) {
            Log::error('BrandCorpusSeed: paste embedding failed', [
                'brand_id' => $brand->id,
                'block_count' => count($blocks),
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Could not embed your posts')
                ->body('The embedding service errored: ' . substr($e->getMessage(), 0, 200))
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        DB::transaction(function () use ($brand, $blocks, $vectors): void {
            foreach ($blocks as $i => $text) {
                BrandCorpusItem::create([
                    'brand_id' => $brand->id,
                    'source_type' => 'historical_post',
                    'source_label' => 'Pasted post ' . ($i + 1),
                    'content' => $text,
                    'embedding' => $vectors[$i],
                ]);
            }
        });

        app(SetupReadiness::class)->invalidate($brand);
        $this->pasteText = '';

        Notification::make()
            ->title('Corpus updated')
            ->body(sprintf(
                'Saved %d post%s. Total corpus items: %d / %d needed.',
                count($blocks),
                count($blocks) === 1 ? '' : 's',
                BrandCorpusItem::where('brand_id', $brand->id)->count(),
                $this->readinessThreshold(),
            ))
            ->success()
            ->send();
    }

    /**
     * Fallback path: scrape the brand's website_url, split paragraphs, embed
     * each as source_type='website_chunk'. Used when the customer has nothing
     * to paste yet. Less ideal than real historical posts (synthetic voice,
     * marketing copy), but it unblocks the wizard immediately.
     */
    public function seedFromWebsite(): void
    {
        @set_time_limit(180);

        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to seed')->danger()->send();
            return;
        }

        if (empty($brand->website_url)) {
            Notification::make()
                ->title('Brand has no website URL')
                ->body('Add a website URL to the brand profile, then retry.')
                ->warning()
                ->send();
            return;
        }

        $chunks = $this->scrapeWebsiteChunks($brand->website_url);
        if ($chunks === null) {
            Notification::make()
                ->title('Could not fetch the website')
                ->body('Check the URL is reachable and try again.')
                ->danger()
                ->send();
            return;
        }
        if (count($chunks) === 0) {
            Notification::make()
                ->title('Nothing to chunk')
                ->body('The site returned too little text to be useful. Paste posts instead.')
                ->warning()
                ->send();
            return;
        }

        try {
            $vectors = app(EmbeddingService::class)->embedMany(
                texts: $chunks,
                brand: $brand,
                workspace: $brand->workspace,
            );
        } catch (\Throwable $e) {
            Log::error('BrandCorpusSeed: website embedding failed', [
                'brand_id' => $brand->id,
                'chunk_count' => count($chunks),
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Could not embed website chunks')
                ->body(substr($e->getMessage(), 0, 200))
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        DB::transaction(function () use ($brand, $chunks, $vectors): void {
            foreach ($chunks as $i => $text) {
                BrandCorpusItem::create([
                    'brand_id' => $brand->id,
                    'source_type' => 'website_chunk',
                    'source_url' => $brand->website_url,
                    'source_label' => 'Website chunk ' . ($i + 1),
                    'content' => $text,
                    'embedding' => $vectors[$i],
                ]);
            }
        });

        app(SetupReadiness::class)->invalidate($brand);

        Notification::make()
            ->title('Website chunks indexed')
            ->body(sprintf(
                'Indexed %d chunk%s from %s. Total corpus items: %d / %d needed.',
                count($chunks),
                count($chunks) === 1 ? '' : 's',
                $brand->website_url,
                BrandCorpusItem::where('brand_id', $brand->id)->count(),
                $this->readinessThreshold(),
            ))
            ->success()
            ->send();
    }

    /**
     * @return array<int, string> Trimmed, deduped, non-empty blocks of >=20 chars.
     */
    private function splitPasteBlocks(string $raw): array
    {
        $normalised = preg_replace("/\r\n|\r/", "\n", $raw) ?? '';
        $blocks = preg_split("/\n\s*\n+/", $normalised) ?: [];
        $out = [];
        foreach ($blocks as $b) {
            $b = trim($b);
            if (mb_strlen($b) >= 20) {
                $out[] = $b;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * @return array<int, string>|null
     */
    private function scrapeWebsiteChunks(string $url): ?array
    {
        try {
            $response = (new Http(['timeout' => 30, 'allow_redirects' => true]))->get($url, [
                'headers' => [
                    'User-Agent' => 'EIAAW-SocialMediaTeam/1.0 (+https://eiaawsolutions.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('BrandCorpusSeed: scrape failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        $html = (string) $response->getBody();
        if (strlen($html) < 200) return [];

        // Strip noisy blocks before extracting text.
        $cleaned = preg_replace(
            ['/<script\b[^>]*>.*?<\/script>/is', '/<style\b[^>]*>.*?<\/style>/is', '/<nav\b[^>]*>.*?<\/nav>/is', '/<footer\b[^>]*>.*?<\/footer>/is'],
            ' ',
            $html
        );

        $text = trim(html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{2,}/', "\n\n", $text);
        if (mb_strlen($text) < 200) return [];

        // Naive paragraph split — good enough for a v1 fallback.
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
        $chunks = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 80) continue;
            // Cap each chunk at ~2000 chars (~500 tokens) so embeddings stay cheap and on-topic.
            if (mb_strlen($p) > 2000) {
                foreach (str_split($p, 2000) as $sub) {
                    $chunks[] = trim($sub);
                }
            } else {
                $chunks[] = $p;
            }
        }
        return array_values(array_unique(array_filter($chunks)));
    }
}

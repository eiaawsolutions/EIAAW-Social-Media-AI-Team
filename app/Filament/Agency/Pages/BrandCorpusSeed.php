<?php

namespace App\Filament\Agency\Pages;

use App\Agents\BaseAgent;
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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;

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
 *      source_type='website_page' (the schema's canonical vocabulary —
 *      brand_corpus_source_type_check enumerates the legal values). Lower-
 *      quality grounding than real posts but unblocks the wizard for
 *      brands that have not yet posted publicly.
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
    use WithFileUploads;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-book-open';

    protected static ?string $navigationLabel = 'Brand corpus';

    protected static ?string $title = 'Brand corpus';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'brand-corpus';

    protected string $view = 'filament.agency.pages.brand-corpus-seed';

    public function getSubheading(): ?string
    {
        return 'Past posts and reference material that train the Writer to sound like the brand. Aim for 5+ items per brand.';
    }

    /** Livewire-safe scalar state. */
    public ?int $brand = null;

    public string $pasteText = '';

    /**
     * Operator-supplied business facts state (locations + target audience).
     * Hydrated from the brand on mount, persisted by saveBrandFacts(). These
     * enrich the brand voice the Writer + Strategist reason over — they're
     * authoritative ground truth injected ABOVE the AI-synthesised
     * brand-style.md (see Brand::brandFactsBlock).
     *
     * @var array<int, array{area: string, country: string, is_primary: bool, notes: string}>
     */
    public array $locations = [];

    /**
     * The brand's industry (a key from App\Support\Compliance\IndustryCatalog).
     * Drives the legal-compliance rules — advertising & industry laws for the
     * brand's jurisdiction — that the Strategist, Writer, and Compliance gate
     * apply to every post. Hydrated on mount, persisted by saveBrandFacts().
     */
    public string $industry = '';

    public string $audienceDescription = '';

    public string $audienceSegmentsText = '';

    public string $audienceGeoFocus = '';

    /**
     * Operator-pasted company / brand profile (positioning, products, voice,
     * audience). This is the AUTHORITATIVE text the Writer + Strategist read —
     * rendered into Brand::brandFactsBlock above the AI-synthesised voice.
     */
    public string $companyProfile = '';

    /** Transient Livewire upload handle for the optional ARCHIVAL source file. */
    public $profileFile = null;

    public function mount(): void
    {
        $this->brand = request()->integer('brand') ?: null;
        $this->hydrateBrandFacts();
    }

    /** Load the resolved brand's stored facts into the form state. */
    public function hydrateBrandFacts(): void
    {
        $brand = $this->resolveBrand();
        if (! $brand) {
            return;
        }

        // Normalise any legacy free-text industry to a catalog key so the
        // Select shows a valid option (else it renders blank for old brands).
        $this->industry = \App\Support\Compliance\IndustryCatalog::isValid($brand->industry)
            ? (string) $brand->industry
            : ($brand->industry ? \App\Support\Compliance\IndustryCatalog::normalize($brand->industry) : '');

        $this->locations = array_values(array_map(fn ($l) => [
            'area' => (string) ($l['area'] ?? ''),
            'country' => (string) ($l['country'] ?? ''),
            'is_primary' => (bool) ($l['is_primary'] ?? false),
            'notes' => (string) ($l['notes'] ?? ''),
        ], (array) ($brand->business_locations ?? [])));

        $audience = (array) ($brand->audience_profile ?? []);
        $this->audienceDescription = (string) ($audience['description'] ?? '');
        $this->audienceSegmentsText = implode(', ', (array) ($audience['segments'] ?? []));
        $this->audienceGeoFocus = (string) ($audience['geo_focus'] ?? '');

        $this->companyProfile = (string) ($brand->company_profile ?? '');
        // $profileFile is a transient upload handle — never round-tripped. The
        // "current file" indicator in the view reads $brand->company_profile_file.
    }

    /** Add a blank location row to the repeater. */
    public function addLocation(): void
    {
        $this->locations[] = ['area' => '', 'country' => '', 'is_primary' => false, 'notes' => ''];
    }

    /** Remove a location row by index. */
    public function removeLocation(int $index): void
    {
        unset($this->locations[$index]);
        $this->locations = array_values($this->locations);
    }

    public function resolveBrand(): ?Brand
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        /** @var ?Workspace $ws */
        $ws = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
        if (! $ws) {
            return null;
        }

        if ($this->brand) {
            $b = Brand::where('workspace_id', $ws->id)->find($this->brand);
            if ($b) {
                return $b;
            }
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
                ->body('The embedding service errored: '.substr($e->getMessage(), 0, 200))
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
                    'source_label' => 'Pasted post '.($i + 1),
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

        // source_type uses the schema's canonical vocabulary — the
        // brand_corpus_source_type_check constraint allows only
        // {historical_post, website_page, style_guide, product_doc, manual_note}.
        // We're chunking the website, so 'website_page' is the right value;
        // 'website_chunk' would violate the CHECK constraint.
        DB::transaction(function () use ($brand, $chunks, $vectors): void {
            foreach ($chunks as $i => $text) {
                BrandCorpusItem::create([
                    'brand_id' => $brand->id,
                    'source_type' => 'website_page',
                    'source_url' => $brand->website_url,
                    'source_label' => 'Website chunk '.($i + 1),
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
     * Persist the operator-supplied business facts (locations + target audience)
     * onto the brand. Stored on the brands row (not brand_styles), so they
     * survive every voice re-synthesis and are always the freshest facts the
     * Writer + Strategist see. Empty rows/fields are dropped so an unfilled
     * form clears cleanly to NULL rather than storing noise.
     */
    public function saveBrandFacts(): void
    {
        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to update')->danger()->send();

            return;
        }

        // Locations: keep only rows with at least an area or country.
        $locations = [];
        foreach ($this->locations as $row) {
            $area = trim((string) ($row['area'] ?? ''));
            $country = trim((string) ($row['country'] ?? ''));
            if ($area === '' && $country === '') {
                continue;
            }
            $locations[] = [
                'area' => mb_substr($area, 0, 120),
                'country' => mb_substr($country, 0, 80),
                'is_primary' => (bool) ($row['is_primary'] ?? false),
                'notes' => mb_substr(trim((string) ($row['notes'] ?? '')), 0, 200),
            ];
        }

        // Exactly one primary: if the operator flagged several, keep the first;
        // if none and there's at least one row, promote the first.
        $primarySeen = false;
        foreach ($locations as $i => $loc) {
            if ($loc['is_primary'] && ! $primarySeen) {
                $primarySeen = true;
            } elseif ($loc['is_primary']) {
                $locations[$i]['is_primary'] = false;
            }
        }
        if (! $primarySeen && $locations !== []) {
            $locations[0]['is_primary'] = true;
        }

        // Audience: split segments on commas, trim, drop empties, cap at 12.
        $segments = collect(preg_split('/[,\n]+/', $this->audienceSegmentsText) ?: [])
            ->map(fn ($s) => mb_substr(trim((string) $s), 0, 60))
            ->filter(fn ($s) => $s !== '')
            ->unique()
            ->take(12)
            ->values()
            ->all();

        $description = mb_substr(trim($this->audienceDescription), 0, 600);
        $geoFocus = mb_substr(trim($this->audienceGeoFocus), 0, 160);

        $audience = array_filter([
            'description' => $description,
            'segments' => $segments,
            'geo_focus' => $geoFocus,
        ], fn ($v) => $v !== '' && $v !== []);

        // Company profile text — generous cap for archival prose; the AI reads
        // this verbatim above brand-style.md (see Brand::brandFactsBlock).
        $companyProfile = mb_substr(trim($this->companyProfile), 0, 20000);

        // Industry — store the catalog key only (legal rules key off it). An
        // unrecognised submission falls back to the existing value rather than
        // wiping it; empty stays empty.
        $industry = \App\Support\Compliance\IndustryCatalog::isValid($this->industry)
            ? $this->industry
            : ($brand->industry ?: null);

        $brand->update([
            'industry' => $industry,
            'business_locations' => $locations !== [] ? $locations : null,
            'audience_profile' => $audience !== [] ? $audience : null,
            'company_profile' => $companyProfile !== '' ? $companyProfile : null,
        ]);

        // Re-normalise local state from what we persisted (collapses cleared rows).
        $this->hydrateBrandFacts();

        app(SetupReadiness::class)->invalidate($brand);

        Notification::make()
            ->title('Business details saved')
            ->body('The Writer and Strategist will now ground every post in these locations and audience.')
            ->success()
            ->send();
    }

    /**
     * Move the optional ARCHIVAL profile document to the DURABLE disk (R2 in
     * prod, public locally) and record its metadata on the brand.
     *
     * The file is NEVER parsed and NEVER read back — it's the operator's record
     * only; the AI is grounded by the pasted company_profile text, not this
     * file. Livewire temp uploads land on the LOCAL disk first, so storeAs(...,
     * ['disk' => $disk]) performs a one-way local→durable transfer with no
     * read-back/preview of the stored file (sidesteps the Livewire S3-temp
     * preview failure mode entirely).
     *
     * Kept independent of saveBrandFacts so editing the profile text never
     * forces a re-upload of the (optional) document.
     */
    public function saveCompanyProfileFile(): void
    {
        $brand = $this->resolveBrand();
        if (! $brand) {
            Notification::make()->title('No brand to update')->danger()->send();

            return;
        }
        if (! $this->profileFile) {
            Notification::make()->title('No file selected')->warning()->send();

            return;
        }

        $this->validate([
            'profileFile' => [
                'file',
                'max:25600', // 25 MB
                'mimes:pdf,doc,docx,ppt,pptx,txt,md,rtf,odt,csv,xls,xlsx',
            ],
        ]);

        $disk = BaseAgent::durableArtifactDisk(); // 'r2' | 'public'
        $original = $this->profileFile->getClientOriginalName();
        $mime = $this->profileFile->getMimeType();
        $size = $this->profileFile->getSize();

        // One-way local-temp → durable disk. No get(), no preview, no parse.
        $path = $this->profileFile->storeAs(
            'company-profiles/'.$brand->id,
            Str::random(20).'-'.$original,
            ['disk' => $disk, 'visibility' => 'public'],
        );
        $url = Storage::disk($disk)->url($path);

        $brand->update([
            'company_profile_file' => [
                'disk' => $disk,
                'path' => $path,
                'url' => $url,
                'filename' => $original,
                'mime' => $mime,
                'size' => $size,
                'uploaded_at' => now()->toIso8601String(),
            ],
        ]);

        $this->profileFile = null;
        app(SetupReadiness::class)->invalidate($brand);

        Notification::make()
            ->title('Profile document saved')
            ->body('Archived for your records. The AI is grounded by the profile text you paste, not this file.')
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
        if (strlen($html) < 200) {
            return [];
        }

        // Strip noisy blocks before extracting text.
        $cleaned = preg_replace(
            ['/<script\b[^>]*>.*?<\/script>/is', '/<style\b[^>]*>.*?<\/style>/is', '/<nav\b[^>]*>.*?<\/nav>/is', '/<footer\b[^>]*>.*?<\/footer>/is'],
            ' ',
            $html
        );

        $text = trim(html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{2,}/', "\n\n", $text);
        if (mb_strlen($text) < 200) {
            return [];
        }

        // Naive paragraph split — good enough for a v1 fallback.
        $paragraphs = preg_split("/\n\s*\n/", $text) ?: [];
        $chunks = [];
        foreach ($paragraphs as $p) {
            $p = trim($p);
            if (mb_strlen($p) < 80) {
                continue;
            }
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

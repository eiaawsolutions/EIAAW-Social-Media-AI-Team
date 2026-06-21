<?php

namespace App\Filament\Agency\Resources\BrandAssets\Pages;

use App\Filament\Agency\Resources\BrandAssets\BrandAssetResource;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Workspace;
use App\Services\Imagery\BrandAssetTagger;
use App\Services\Imagery\CustomisedPostScheduler;
use App\Services\Imagery\CustomisedNarrativeWriter;
use App\Services\Imagery\Recurrence;
use App\Services\Imagery\RecurrenceExpander;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ManageBrandAssets extends ManageRecords
{
    protected static string $resource = BrandAssetResource::class;

    public function getSubheading(): ?string
    {
        return 'Brand-approved images and videos the Designer pulls from. Upload for general agent use, or schedule a customised post around one.';
    }

    protected function getHeaderActions(): array
    {
        // A brand asset MUST belong to a brand (the upload modal's first field
        // is a required brand picker). On a fresh workspace with zero brands the
        // picker is empty, so the modal can never submit — which reads to the
        // operator as "the upload button is broken". Detect that here and turn
        // the action into a signpost to the Brands page instead of a dead-end form.
        if (! $this->hasBrands()) {
            return [
                Action::make('createBrandFirst')
                    ->label('Create a brand first')
                    ->icon('heroicon-o-plus')
                    ->color('primary')
                    ->tooltip('Brand assets attach to a brand. Add your first brand, then come back to upload.')
                    ->url(\App\Filament\Agency\Resources\Brands\BrandResource::getUrl('index', panel: 'agency')),
            ];
        }

        return [
            Action::make('upload')
                ->label('Upload assets')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->modalHeading('Upload brand assets')
                ->modalDescription('Add brand-approved images / videos. Choose whether the agents may use them freely, or schedule one as a customised post.')
                // NOTE: a reactive `fn (callable $get) => ...` label here 500s in
                // Filament v5 — the modal-submit-label closure is evaluated on the
                // ACTION (header action), where getSchemaComponent() is null, so
                // resolving the `$get`/`$set` schema utility fatals with
                // "Call to a member function makeGetUtility() on null". `$get` is
                // only safe INSIDE the schema component closures (uploadSchema()),
                // not on the action's own modal-label. Keep this label static.
                ->modalSubmitActionLabel('Upload / schedule')
                ->schema($this->uploadSchema())
                ->action(fn (array $data) => $this->handleUpload($data)),
        ];
    }

    /** Does the operator's workspace have at least one (non-archived) brand? */
    private function hasBrands(): bool
    {
        $ws = $this->workspace();
        if (! $ws) {
            return false;
        }

        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->exists();
    }

    /** @return array<int, \Filament\Schemas\Components\Component|\Filament\Forms\Components\Field> */
    private function uploadSchema(): array
    {
        return [
            Select::make('brand_id')
                ->label('Brand')
                ->options(fn () => $this->brandOptions())
                ->default(fn () => $this->defaultBrandId())
                ->live()
                ->required(),

            Radio::make('usage_intent')
                ->label('How will this asset be used?')
                ->options([
                    BrandAsset::INTENT_GENERAL => 'General usage — let the agents post with it',
                    BrandAsset::INTENT_CUSTOMISED => 'Customised post — I\'ll schedule a specific post around it',
                ])
                ->descriptions([
                    BrandAsset::INTENT_GENERAL => 'Goes into the pool the Designer + Video agents semantically pick from when they build your posts. Best for stock photography, b-roll, brand shots.',
                    BrandAsset::INTENT_CUSTOMISED => 'Reserved for one dedicated post you control: your own caption (or an AI-written one), the platforms, and the publish date. We run it through the same compliance checks as agent posts — your wording is never AI-rewritten; if a check flags it, it waits for you to review and approve. The agents won\'t reuse the asset.',
                ])
                ->default(BrandAsset::INTENT_GENERAL)
                ->required()
                ->live(),

            // ---- Files ----
            // Upload straight from the user's computer: drag-drop OR click to
            // open the OS file picker. ->openable lets them re-open a picked
            // file; ->downloadable is off (these are inbound, not exports).
            FileUpload::make('files')
                ->label(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED
                    ? 'File from your computer (one asset for this post)'
                    : 'Files from your computer (drag-drop or browse — multiple allowed)')
                ->placeholder('Drag images / videos here, or click to choose from your computer')
                ->multiple(fn (callable $get) => $get('usage_intent') !== BrandAsset::INTENT_CUSTOMISED)
                ->maxFiles(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED ? 1 : null)
                ->disk($this->preferredDisk())
                ->directory(fn (callable $get) => 'brand-assets/' . ($get('brand_id') ?: 'unsorted'))
                ->visibility('public')
                ->preserveFilenames()
                ->openable()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/quicktime', 'video/webm'])
                ->maxSize(50 * 1024) // 50 MB per file — kept in lockstep with livewire.temporary_file_upload rules (max:51200)
                ->helperText('JPG, PNG, WEBP, GIF, MP4, MOV or WEBM · up to 50 MB each. Automatically tagged on save (~3s each) so the agents can find them.')
                ->required(),

            // ---- Customised-post fields (revealed only for that intent) ----
            CheckboxList::make('platforms')
                ->label('Publish to')
                ->options($this->platformOptions())
                ->columns(2)
                ->bulkToggleable()
                ->helperText('One post is created per platform, each fitted to its caption limit.')
                ->visible(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED)
                ->required(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED),

            Textarea::make('narrative')
                ->label('Post narrative')
                ->rows(5)
                ->placeholder('Write the caption for this post — or use the AI writer to draft it, then edit.')
                ->helperText('This text publishes (trimmed per platform). It runs through compliance first — if everything passes it queues automatically (amber/red lanes wait for your approval); if a check flags it, it surfaces on the Drafts page for you to review and edit. We never auto-rewrite your words.')
                ->hintAction($this->aiWriterAction())
                ->visible(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED)
                ->required(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED)
                ->maxLength(5000),

            // Records whether the narrative was AI-written (set by the hint
            // action) or hand-typed (default). Persisted on the asset row.
            Hidden::make('narrative_source')->default('manual'),

            TagsInput::make('hashtags')
                ->label('Hashtags (optional)')
                ->placeholder('add a tag + Enter')
                ->helperText('Without the #. Capped per platform at publish time.')
                ->visible(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED),

            DateTimePicker::make('publish_at')
                ->label(fn (callable $get) => $get('recurrence_frequency') && $get('recurrence_frequency') !== RecurrenceExpander::FREQ_NONE
                    ? 'First publish date & time'
                    : 'Publish date & time')
                ->seconds(false)
                ->native(false)
                ->minDate(now()->subMinutes(5))
                ->default(now()->addHour()->startOfHour())
                ->live()
                ->helperText(fn () => 'In your brand timezone (' . ($this->defaultBrandTimezone() ?: 'UTC') . '). Past times publish within a few minutes.')
                ->visible(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED)
                ->required(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED),

            // ---- Recurrence (revealed only for customised intent) ----
            // A repeating rule materialises one post per occurrence through the
            // SAME compliance + scheduling rail (see CustomisedPostScheduler).
            // Compliance runs once on the first occurrence; identical followers
            // inherit its verdict. The whole series is capped at
            // RecurrenceExpander::MAX_OCCURRENCES so a runaway rule can't flood
            // the calendar, and the publish-time plan cap defers anything that
            // overflows a capped month to the next period.
            Select::make('recurrence_frequency')
                ->label('Repeat')
                ->options([
                    RecurrenceExpander::FREQ_NONE => 'Does not repeat',
                    RecurrenceExpander::FREQ_DAILY => 'Daily',
                    RecurrenceExpander::FREQ_WEEKLY => 'Weekly',
                    RecurrenceExpander::FREQ_MONTHLY => 'Monthly',
                    RecurrenceExpander::FREQ_YEARLY => 'Yearly',
                ])
                ->default(RecurrenceExpander::FREQ_NONE)
                ->selectablePlaceholder(false)
                ->live()
                ->helperText('Schedule this post once, or repeat it on a cadence. Repeats run through the same compliance check as a single post — checked once, then the series queues.')
                ->visible(fn (callable $get) => $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED),

            TextInput::make('recurrence_interval')
                ->label(fn (callable $get) => 'Repeat every (' . $this->intervalUnitLabel($get('recurrence_frequency')) . ')')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(52)
                ->default(1)
                ->helperText('e.g. 2 = every other ' . 'period. Leave at 1 for every one.')
                ->visible(fn (callable $get) => $this->recurrenceActive($get)),

            // Weekly only: which day(s) of the week the series fires on. ISO day
            // numbers (1=Mon..7=Sun) so they map straight onto the expander.
            CheckboxList::make('recurrence_weekdays')
                ->label('On these days')
                ->options([
                    1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu',
                    5 => 'Fri', 6 => 'Sat', 7 => 'Sun',
                ])
                ->columns(4)
                ->bulkToggleable()
                ->helperText('Leave empty to repeat on the same weekday as the first post.')
                ->visible(fn (callable $get) => $this->recurrenceActive($get)
                    && $get('recurrence_frequency') === RecurrenceExpander::FREQ_WEEKLY),

            Radio::make('recurrence_ends')
                ->label('Ends')
                ->options([
                    'count' => 'After a number of posts',
                    'until' => 'On a date',
                ])
                ->descriptions([
                    'count' => 'Stop after publishing this many posts (including the first).',
                    'until' => 'Keep repeating until this date, then stop.',
                ])
                ->default('count')
                ->live()
                ->visible(fn (callable $get) => $this->recurrenceActive($get)),

            TextInput::make('recurrence_count')
                ->label('Number of posts')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(RecurrenceExpander::MAX_OCCURRENCES)
                ->default(4)
                ->helperText('Including the first post. Capped at ' . RecurrenceExpander::MAX_OCCURRENCES . ' per upload.')
                ->visible(fn (callable $get) => $this->recurrenceActive($get) && $get('recurrence_ends') === 'count')
                ->required(fn (callable $get) => $this->recurrenceActive($get) && $get('recurrence_ends') === 'count'),

            DatePicker::make('recurrence_until')
                ->label('Repeat until')
                ->native(false)
                ->minDate(fn (callable $get) => $get('publish_at') ?: now())
                ->default(now()->addMonth())
                ->helperText('The series stops after this date (whole day included). Max ' . RecurrenceExpander::MAX_OCCURRENCES . ' posts per upload.')
                ->visible(fn (callable $get) => $this->recurrenceActive($get) && $get('recurrence_ends') === 'until')
                ->required(fn (callable $get) => $this->recurrenceActive($get) && $get('recurrence_ends') === 'until'),
        ];
    }

    /**
     * Is a repeating rule currently active in the form? (customised intent AND a
     * non-"none" frequency). Single predicate so every recurrence sub-field
     * reveals/hides together.
     */
    private function recurrenceActive(callable $get): bool
    {
        return $get('usage_intent') === BrandAsset::INTENT_CUSTOMISED
            && $get('recurrence_frequency')
            && $get('recurrence_frequency') !== RecurrenceExpander::FREQ_NONE;
    }

    /** Human label for the interval unit, driven by the chosen frequency. */
    private function intervalUnitLabel(?string $frequency): string
    {
        return match ($frequency) {
            RecurrenceExpander::FREQ_DAILY => 'days',
            RecurrenceExpander::FREQ_WEEKLY => 'weeks',
            RecurrenceExpander::FREQ_MONTHLY => 'months',
            RecurrenceExpander::FREQ_YEARLY => 'years',
            default => 'units',
        };
    }

    /**
     * The "Generate with AI writer" hint action. Runs the Writer inline against
     * the asset's vision tags + brand voice, then fills the narrative field so
     * the operator REVIEWS + EDITS before scheduling (review-before-schedule).
     * Needs at least one uploaded file (so we can see what the post is about).
     */
    private function aiWriterAction(): Action
    {
        return Action::make('generateNarrative')
            ->label('Generate with AI writer')
            ->icon('heroicon-m-sparkles')
            ->action(function (callable $get, Set $set): void {
                @set_time_limit(120);

                $brandId = (int) ($get('brand_id') ?: 0);
                $brand = $brandId ? Brand::find($brandId) : null;
                if (! $brand) {
                    Notification::make()->title('Pick a brand first')->warning()->send();
                    return;
                }

                // FileUpload state is keyed by UUID (not 0..n) and — crucially —
                // BEFORE the form is submitted the value is a Livewire
                // TemporaryUploadedFile, NOT a final disk path. This hint action
                // runs mid-form, so saveUploadedFiles() hasn't moved the file to
                // its `brand-assets/...` home yet. Passing the temp path to a disk
                // read found nothing → the writer got no image → the model said
                // "no image is showing". So read the FIRST file robustly and, when
                // it's still a temp upload, hand its BYTES straight to the writer.
                $files = $get('files') ?: [];
                if (! is_array($files)) $files = [$files];
                $firstFile = $files !== [] ? reset($files) : null;
                if (! $firstFile) {
                    Notification::make()
                        ->title('Upload the file first')
                        ->body('The AI writer needs to see the asset before it can write about it.')
                        ->warning()
                        ->send();
                    return;
                }

                $platforms = is_array($get('platforms')) ? $get('platforms') : [];
                $platform = $platforms[0] ?? 'instagram';

                try {
                    if ($firstFile instanceof TemporaryUploadedFile) {
                        // Pre-submit: read the temp upload's bytes + mime directly.
                        $bytes = $firstFile->get();
                        $mime = $firstFile->getMimeType() ?: 'image/jpeg';
                        $narrative = app(CustomisedNarrativeWriter::class)->draftForUpload(
                            brand: $brand,
                            imageBytes: is_string($bytes) && strlen($bytes) > 100 ? $bytes : null,
                            mimeType: $mime,
                            platform: $platform,
                        );
                    } else {
                        // Already a stored relative path (e.g. re-opened form).
                        $narrative = app(CustomisedNarrativeWriter::class)->draftFor(
                            brand: $brand,
                            disk: $this->preferredDisk(),
                            relativePath: (string) $firstFile,
                            platform: $platform,
                        );
                    }
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('AI writer could not draft')
                        ->body(substr($e->getMessage(), 0, 240))
                        ->danger()
                        ->send();
                    return;
                }

                if ($narrative === '') {
                    Notification::make()->title('AI writer returned nothing — try again')->warning()->send();
                    return;
                }

                $set('narrative', $narrative);
                $set('narrative_source', 'ai_writer');
                Notification::make()
                    ->title('Draft written — review and edit before scheduling')
                    ->success()
                    ->send();
            });
    }

    /**
     * Single entry point for the upload action. Forks on usage_intent.
     */
    private function handleUpload(array $data): void
    {
        @set_time_limit(300);

        // Durability guard: refuse to accept an upload we know won't survive.
        // On a stateless production container the local `public` disk is wiped
        // every redeploy, so a "successful" upload there silently disappears and
        // the preview breaks (this is exactly what stranded Bear Hug's assets).
        // Fail loudly with an operator-actionable message instead. Object
        // storage (R2) clears this guard.
        $disk = $this->preferredDisk();
        if (! self::storageIsDurable($disk)) {
            \Illuminate\Support\Facades\Log::error('brand-asset upload blocked: non-durable storage', [
                'disk' => $disk,
                'driver' => config("filesystems.disks.{$disk}.driver"),
                'env' => app()->environment(),
            ]);
            Notification::make()
                ->title('Uploads are temporarily unavailable')
                ->body('Asset storage isn’t configured for durable hosting yet, so we’ve paused uploads to avoid losing your files. Our team has been alerted and will enable it shortly.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        $intent = $data['usage_intent'] ?? BrandAsset::INTENT_GENERAL;

        if ($intent === BrandAsset::INTENT_CUSTOMISED) {
            $this->handleCustomisedUpload($data);
            return;
        }

        $this->handleGeneralUpload($data);
    }

    /** Today's behaviour: tag + embed, asset joins the agent pool. */
    private function handleGeneralUpload(array $data): void
    {
        $disk = $this->preferredDisk();
        $brandId = (int) $data['brand_id'];
        $files = $data['files'] ?? [];
        if (! is_array($files)) $files = [$files];

        $created = 0;
        foreach ($files as $relativePath) {
            $asset = $this->persistAsset($disk, $brandId, (string) $relativePath, BrandAsset::INTENT_GENERAL);
            $this->tagSafely($asset);
            $created++;
        }

        Notification::make()
            ->title("Uploaded {$created} asset(s)")
            ->body('Tagging + embedding ran inline. Designer/Video agents will start picking from these immediately.')
            ->success()
            ->send();
    }

    /**
     * Customised path: persist the single asset, tag it (so topic/visual
     * direction is meaningful), then schedule one post per platform via the
     * existing Draft → ScheduledPost → SubmitScheduledPost rail.
     */
    private function handleCustomisedUpload(array $data): void
    {
        $disk = $this->preferredDisk();
        $brandId = (int) $data['brand_id'];
        $brand = Brand::find($brandId);
        if (! $brand) {
            Notification::make()->title('Brand not found')->danger()->send();
            return;
        }

        $files = $data['files'] ?? [];
        if (! is_array($files)) $files = [$files];
        $relativePath = (string) ($files[0] ?? '');
        if ($relativePath === '') {
            Notification::make()->title('No file uploaded')->danger()->send();
            return;
        }

        $platforms = array_values((array) ($data['platforms'] ?? []));
        $narrative = trim((string) ($data['narrative'] ?? ''));
        $hashtags = array_values((array) ($data['hashtags'] ?? []));
        $publishAtRaw = $data['publish_at'] ?? null;

        if (empty($platforms) || $narrative === '' || ! $publishAtRaw) {
            Notification::make()
                ->title('Missing details')
                ->body('A customised post needs at least one platform, a narrative, and a publish date.')
                ->danger()
                ->send();
            return;
        }

        // Interpret the picked datetime in the brand timezone, store as a
        // timezone-aware Carbon. The scheduler localises it back for the entry.
        $brandTz = $brand->timezone ?: 'UTC';
        try {
            $publishAt = Carbon::parse($publishAtRaw, $brandTz);
        } catch (\Throwable) {
            $publishAt = now($brandTz)->addHour();
        }

        // Build the recurrence rule from the form (defaults to a single post).
        $recurrence = Recurrence::fromFormData($data, $brandTz);

        // Persist + tag the asset BEFORE scheduling so its description anchors
        // the calendar entry's topic/visual_direction.
        $asset = $this->persistAsset($disk, $brandId, $relativePath, BrandAsset::INTENT_CUSTOMISED);
        $this->tagSafely($asset);

        try {
            $result = app(CustomisedPostScheduler::class)->schedule(
                asset: $asset,
                brand: $brand,
                narrative: $narrative,
                platforms: $platforms,
                publishAt: $publishAt,
                narrativeSource: ! empty($data['narrative_source']) ? (string) $data['narrative_source'] : 'manual',
                hashtags: $hashtags,
                recurrence: $recurrence,
            );
        } catch (\App\Exceptions\AlreadyScheduledException $e) {
            // Benign double-submit (e.g. a double-click): the asset is already
            // scheduled. Tell the operator calmly, not as an error.
            Notification::make()
                ->title('Already scheduled')
                ->body('This asset already has a customised post scheduled. Check the Schedule or Drafts page.')
                ->warning()
                ->send();
            return;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not schedule the customised post')
                ->body(substr($e->getMessage(), 0, 240))
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        // Report the TRUE outcome per draft (the scheduler refreshes each draft
        // after compliance, so these statuses are live). Compliance can hold a
        // post (awaiting_approval) or fail it (compliance_failed) — a flat
        // "Scheduled!" success would be a false positive that sends the operator
        // to the Schedule page where the post isn't (and won't be until they
        // approve it on the Drafts page).
        //
        // For a recurring series the bucketed statuses below are the FIRST
        // occurrence's drafts — the only ones compliance ran on. The followers
        // inherit that verdict (see CustomisedPostScheduler::cloneComplianceVerdict),
        // so the verdict the operator sees here governs the whole series.
        $drafts = $result['drafts'];
        $count = count($drafts);                                 // posts per occurrence (one per platform)
        $occurrences = (int) ($result['occurrences'] ?? 1);      // number of dates in the series
        $isSeries = $occurrences > 1;
        $totalPosts = $count * $occurrences;
        $when = $publishAt->copy()->setTimezone($brandTz)->format('D, M j Y · g:i A');

        // For a series, name the last publish date too, so "8 weekly posts" has
        // a concrete end the operator can sanity-check.
        $lastWhen = null;
        if ($isSeries && ! empty($result['occurrence_entries'])) {
            $lastEntry = end($result['occurrence_entries']);
            if ($lastEntry && $lastEntry->scheduled_date) {
                $lastWhen = Carbon::parse($lastEntry->scheduled_date)->format('M j, Y');
            }
        }
        $seriesNote = $isSeries
            ? " Repeating series: {$occurrences} dates ({$totalPosts} posts total"
                . ($lastWhen ? ", through {$lastWhen}" : '') . ')'
                . ($count > 0 ? '. Compliance was checked once on the first post; the rest inherit that result.' : '.')
            : '';

        $statuses = collect($drafts)->countBy(fn ($d) => $d->status);
        $queued = (int) $statuses->get('approved', 0) + (int) $statuses->get('scheduled', 0);
        $awaiting = (int) $statuses->get('awaiting_approval', 0);
        $failed = (int) $statuses->get('compliance_failed', 0);
        $platformList = implode(', ', $platforms);

        // Title noun adapts to single post vs series.
        $unit = $isSeries ? "recurring post series ({$totalPosts} posts)" : "{$count} customised post(s)";

        if ($failed === 0 && $awaiting === 0) {
            // All passed compliance + green-lane approved → genuinely queued.
            $startNote = $isSeries ? "First publishes {$platformList} on {$when}." : "Publishing {$platformList} on {$when}.";
            Notification::make()
                ->title("Scheduled {$unit}")
                ->body("Passed compliance. {$startNote}{$seriesNote} Track it on the Schedule page.")
                ->success()
                ->send();
        } elseif ($failed > 0) {
            Notification::make()
                ->title("Held: {$failed} of {$count} post(s) flagged by compliance")
                ->body("Created {$count} post(s) per date for {$platformList}. {$failed} need a fix and "
                    . ($queued + $awaiting > 0 ? ($queued + $awaiting) . ' are awaiting approval/queued. ' : '')
                    . "Review them on the Drafts page — your wording is never auto-rewritten.{$seriesNote}")
                ->warning()
                ->persistent()
                ->send();
        } else {
            // Some/all awaiting approval (amber/red lane, or compliance couldn't
            // run e.g. no brand_style yet — see the reason on each draft).
            Notification::make()
                ->title("Created {$unit} — {$awaiting} awaiting your approval")
                ->body("For {$platformList}, "
                    . ($isSeries ? "first publishing {$when} " : "publishing {$when} ")
                    . "once approved. "
                    . ($queued > 0 ? "{$queued} already queued. " : '')
                    . "Approve them on the Drafts page (the compliance result explains each one).{$seriesNote}")
                ->info()
                ->persistent()
                ->send();
        }
    }

    /** Create one BrandAsset row from an uploaded relative path. */
    private function persistAsset(string $disk, int $brandId, string $relativePath, string $intent): BrandAsset
    {
        $absoluteUrl = Storage::disk($disk)->url($relativePath);
        $isVideo = str_starts_with(Storage::disk($disk)->mimeType($relativePath) ?: '', 'video/');

        return BrandAsset::create([
            'brand_id' => $brandId,
            'uploaded_by_user_id' => auth()->id(),
            'media_type' => $isVideo ? 'video' : 'image',
            'source' => 'upload',
            'usage_intent' => $intent,
            'storage_disk' => $disk,
            'storage_path' => $relativePath,
            'public_url' => $absoluteUrl,
            'original_filename' => basename($relativePath),
            'mime_type' => Storage::disk($disk)->mimeType($relativePath) ?: null,
            'file_size_bytes' => Storage::disk($disk)->size($relativePath) ?: null,
            'brand_approved' => true,
        ]);
    }

    /** Best-effort vision tagging — the asset survives even if it fails. */
    private function tagSafely(BrandAsset $asset): void
    {
        try {
            app(BrandAssetTagger::class)->tag($asset);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('upload: tagger failed', [
                'asset_id' => $asset->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** R2 if env is configured, else local public disk. */
    private function preferredDisk(): string
    {
        return self::resolvePreferredDisk();
    }

    /**
     * The disk brand-asset uploads land on: R2 when it's configured, else the
     * local `public` disk. Static + shared so the upload form, persistence, and
     * the durability guard all agree on one answer.
     *
     * Durability note: the local `public` disk is NOT durable on a stateless
     * container (Railway wipes storage/app/public on every redeploy), so files
     * uploaded there vanish and previews break. R2 is the only durable option in
     * production — see storageIsDurable() + the guard in persistAsset().
     */
    public static function resolvePreferredDisk(): string
    {
        return config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
    }

    /**
     * Is the chosen disk safe to persist customer uploads to in this
     * environment? Object storage (s3/r2 driver) always is. The local disks are
     * only durable outside production — on a production stateless container they
     * are ephemeral, which is the exact failure that wiped Bear Hug's assets.
     */
    public static function storageIsDurable(string $disk): bool
    {
        $driver = config("filesystems.disks.{$disk}.driver");
        if ($driver === 's3') {
            return true;
        }

        // local/public driver — durable only when NOT a stateless production box.
        return ! app()->environment('production');
    }

    /** @return array<string, string> platform enum => label */
    private function platformOptions(): array
    {
        return [
            'instagram' => 'Instagram',
            'facebook' => 'Facebook',
            'linkedin' => 'LinkedIn',
            'tiktok' => 'TikTok',
            'threads' => 'Threads',
            'x' => 'X (Twitter)',
            'youtube' => 'YouTube',
            'pinterest' => 'Pinterest',
        ];
    }

    /** @return array<int, string> brand_id => display name */
    private function brandOptions(): array
    {
        $ws = $this->workspace();
        if (! $ws) return [];
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->pluck('name', 'id')
            ->all();
    }

    private function defaultBrandId(): ?int
    {
        $ws = $this->workspace();
        if (! $ws) return null;
        return Brand::where('workspace_id', $ws->id)
            ->whereNull('archived_at')
            ->orderBy('id')
            ->value('id');
    }

    private function defaultBrandTimezone(): ?string
    {
        $id = $this->defaultBrandId();
        return $id ? Brand::whereKey($id)->value('timezone') : null;
    }

    private function workspace(): ?Workspace
    {
        $user = auth()->user();
        if (! $user) return null;
        return $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();
    }
}

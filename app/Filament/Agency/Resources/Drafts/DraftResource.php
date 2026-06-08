<?php

namespace App\Filament\Agency\Resources\Drafts;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Agents\VideoAgent;
use App\Filament\Agency\Resources\Drafts\Pages\ManageDrafts;
use App\Jobs\RedraftFailedDraft;
use App\Models\Brand;
use App\Models\BrandAsset;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use App\Services\Blotato\BlotatoClient;
use App\Services\Blotato\PlatformMediaRules;
use App\Services\Imagery\BrandAssetTagger;
use App\Services\Imagery\FalAiClient;
use App\Services\Imagery\ImageAutoCompressor;
use App\Services\Imagery\ImageCreativeDirection;
use App\Services\Imagery\MediaComplianceChecker;
use App\Services\Imagery\MediaComplianceException;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Drafts — every post the Writer has produced, with full provenance and
 * compliance state. The user reviews drafts here, approves or rejects,
 * and schedules approved drafts for publishing.
 *
 * Stage 07 detector flips green when a draft exists in
 * awaiting_approval/approved/scheduled/published. This page is also where
 * v1.1 drag-edit UX lands (edit body, regenerate, approve in batch).
 */
class DraftResource extends Resource
{
    protected static ?string $model = Draft::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $navigationLabel = 'Drafts';

    protected static ?string $modelLabel = 'Draft';

    protected static ?string $pluralModelLabel = 'Drafts';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder('Search captions...')
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->size('sm')
                    ->sortable(),
                Tables\Columns\ImageColumn::make('asset_url')
                    ->label('Image')
                    ->size(56)
                    ->square()
                    ->defaultImageUrl(fn () => null),
                Tables\Columns\TextColumn::make('platform')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'instagram' => 'pink',
                        'facebook' => 'info',
                        'linkedin' => 'primary',
                        'tiktok' => 'gray',
                        'threads' => 'gray',
                        'x' => 'gray',
                        'youtube' => 'danger',
                        'pinterest' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('body')
                    ->label('Caption')
                    ->wrap()
                    ->limit(140)
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'compliance_pending' => 'gray',
                        'compliance_failed' => 'danger',
                        'awaiting_approval' => 'warning',
                        'approved' => 'success',
                        'scheduled' => 'info',
                        'published' => 'success',
                        'rejected' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => str_replace('_', ' ', $state)),
                Tables\Columns\TextColumn::make('next_action')
                    ->label('Next step')
                    ->state(fn (Draft $r) => self::nextActionFor($r))
                    ->badge()
                    ->color(fn (string $state) => match (true) {
                        str_starts_with($state, 'WAIT') => 'gray',
                        str_starts_with($state, 'YOU:') => 'warning',
                        str_starts_with($state, 'AUTO') => 'info',
                        str_starts_with($state, 'DONE') => 'success',
                        str_starts_with($state, 'FAIL') => 'danger',
                        default => 'gray',
                    })
                    ->wrap()
                    ->extraAttributes(['style' => 'max-width: 280px;']),
                Tables\Columns\TextColumn::make('lane')
                    ->badge()
                    ->color(fn (?string $state) => match ($state) {
                        'green' => 'success',
                        'amber' => 'warning',
                        'red' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->color('gray')
                    ->size('sm')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Status')
                    ->multiple()
                    ->options([
                        'compliance_pending' => 'Compliance pending',
                        'compliance_failed' => 'Compliance failed',
                        'awaiting_approval' => 'Awaiting approval',
                        'approved' => 'Approved',
                        'scheduled' => 'Scheduled',
                        'published' => 'Published',
                        'rejected' => 'Rejected',
                    ]),
                Tables\Filters\SelectFilter::make('platform')
                    ->label('Platform')
                    ->multiple()
                    ->options([
                        'instagram' => 'Instagram',
                        'facebook' => 'Facebook',
                        'linkedin' => 'LinkedIn',
                        'tiktok' => 'TikTok',
                        'threads' => 'Threads',
                        'x' => 'X (Twitter)',
                        'youtube' => 'YouTube',
                        'pinterest' => 'Pinterest',
                    ]),
                Tables\Filters\Filter::make('created_range')
                    ->label('Created')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->native(false)
                            ->closeOnDateSelection(),
                        DatePicker::make('until')
                            ->label('To')
                            ->native(false)
                            ->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date),
                            );
                    })
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['from'] ?? null) {
                            $indicators[] = Indicator::make('From: '.Carbon::parse($data['from'])->format('M j, Y'))
                                ->removeField('from');
                        }
                        if ($data['until'] ?? null) {
                            $indicators[] = Indicator::make('To: '.Carbon::parse($data['until'])->format('M j, Y'))
                                ->removeField('until');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(4)
            ->filtersLayout(FiltersLayout::AboveContent)
            ->recordActions([
                Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (Draft $r) => "Draft #{$r->id} — ".ucfirst($r->platform))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Draft $r) => view('filament.agency.partials.draft-view', [
                        'draft' => $r,
                    ])),

                // Direct edit + AI assist (chat reword + quick presets). Opens
                // the dedicated DraftEditor page; on save the draft resets to
                // compliance_pending and Compliance re-runs. Same editable-status
                // set the editor enforces (it 403s on anything else). 'scheduled'
                // is excluded — resetting a queued post to compliance_pending
                // would strand it.
                Action::make('aiEdit')
                    ->label('Edit / AI assist')
                    ->icon('heroicon-o-pencil-square')
                    ->color('primary')
                    ->visible(fn (Draft $r) => in_array($r->status, [
                        'awaiting_approval', 'compliance_failed', 'compliance_pending', 'approved',
                    ], true))
                    ->url(fn (Draft $r) => \App\Filament\Agency\Pages\DraftEditor::getUrl(['draft' => $r->id])),

                Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Draft $r) => $r->status === 'awaiting_approval')
                    ->requiresConfirmation()
                    ->action(function (Draft $r): void {
                        $r->update([
                            'status' => 'approved',
                            'approved_by_user_id' => auth()->id(),
                            'approved_at' => now(),
                        ]);
                        Notification::make()
                            ->title('Approved')
                            ->body("Draft #{$r->id} approved. Use 'Schedule' to queue it.")
                            ->success()
                            ->send();
                    }),

                Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Draft $r) => in_array($r->status, ['awaiting_approval', 'approved']))
                    ->schema([
                        Textarea::make('rejection_reason')
                            ->label('Why?')
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (Draft $r, array $data): void {
                        $r->update([
                            'status' => 'rejected',
                            'rejected_by_user_id' => auth()->id(),
                            'rejected_at' => now(),
                            'rejection_reason' => $data['rejection_reason'],
                        ]);
                        Notification::make()
                            ->title('Rejected')
                            ->body("Draft #{$r->id} rejected.")
                            ->warning()
                            ->send();
                    }),

                Action::make('schedule')
                    ->label('Schedule')
                    ->icon('heroicon-o-clock')
                    ->color('primary')
                    ->visible(fn (Draft $r) => in_array($r->status, ['approved', 'awaiting_approval']))
                    ->schema([
                        DateTimePicker::make('scheduled_for')
                            ->label(fn (Draft $r) => 'Publish at ('.($r->brand?->timezone ?: 'UTC').')')
                            ->helperText(fn (Draft $r) => 'Pick a time in your brand timezone ('.($r->brand?->timezone ?: 'UTC').'). The system stores it as UTC internally.')
                            ->seconds(false)
                            // Filament v5 timezone(): the picker reads/writes datetimes
                            // in this timezone, while the action handler still receives
                            // a UTC-equivalent string ($data['scheduled_for']).
                            ->timezone(fn (Draft $r) => $r->brand?->timezone ?: 'UTC')
                            ->default(fn (Draft $r) => now($r->brand?->timezone ?: 'UTC')->addHour())
                            ->minDate(fn (Draft $r) => now($r->brand?->timezone ?: 'UTC'))
                            ->required(),
                    ])
                    ->action(function (Draft $r, array $data): void {
                        $connection = PlatformConnection::where('brand_id', $r->brand_id)
                            ->where('platform', $r->platform)
                            ->where('status', 'active')
                            ->first();
                        if (! $connection) {
                            Notification::make()
                                ->title('No active platform connection')
                                ->body("Reconnect {$r->platform} on /agency/platform-connections, then retry.")
                                ->danger()
                                ->send();

                            return;
                        }

                        $existing = ScheduledPost::where('draft_id', $r->id)
                            ->whereIn('status', ['queued', 'submitting', 'submitted', 'published'])
                            ->first();
                        if ($existing) {
                            Notification::make()
                                ->title('Already queued')
                                ->body('This draft already has a scheduled post — view it on /agency/schedule.')
                                ->warning()
                                ->send();

                            return;
                        }

                        // Filament's DateTimePicker with ->timezone() returns the
                        // picked datetime as a UTC-equivalent string already, so
                        // we wrap in Carbon (which is UTC) and let Eloquent's
                        // datetime cast persist it as UTC. Display formatting
                        // converts to brand timezone for the operator.
                        $scheduledForUtc = Carbon::parse($data['scheduled_for']);
                        $brandTz = $r->brand?->timezone ?: 'UTC';

                        ScheduledPost::create([
                            'draft_id' => $r->id,
                            'brand_id' => $r->brand_id,
                            'platform_connection_id' => $connection->id,
                            'scheduled_for' => $scheduledForUtc,
                            'status' => 'queued',
                            'attempt_count' => 0,
                        ]);
                        $r->update(['status' => 'scheduled']);

                        Notification::make()
                            ->title('Scheduled')
                            ->body(sprintf(
                                'Draft #%d queued for %s %s (= %s UTC).',
                                $r->id,
                                $scheduledForUtc->copy()->setTimezone($brandTz)->format('M j, H:i'),
                                $brandTz,
                                $scheduledForUtc->format('M j, H:i'),
                            ))
                            ->success()
                            ->send();
                    }),

                Action::make('replaceMedia')
                    ->label(fn (Draft $r) => empty($r->asset_url) ? 'Add image / video' : 'Replace image / video')
                    ->icon('heroicon-o-photo')
                    ->color('primary')
                    ->visible(fn (Draft $r) => ! in_array($r->status, ['published', 'rejected']))
                    ->modalHeading(fn (Draft $r) => empty($r->asset_url)
                        ? "Add media to Draft #{$r->id}"
                        : "Replace media on Draft #{$r->id}")
                    ->modalSubmitActionLabel('Apply media')
                    ->schema([
                        Radio::make('source')
                            ->label('Where should the media come from?')
                            ->options([
                                'library' => 'Choose from asset library',
                                'upload' => 'Upload from this computer',
                            ])
                            ->descriptions([
                                'library' => 'Pick any brand-approved image or video you have already uploaded.',
                                'upload' => 'Pick a file from your desktop. Zero AI cost.',
                            ])
                            ->default('library')
                            ->required()
                            ->live(),

                        // ---- Library path ----
                        Select::make('brand_asset_id')
                            ->label('Asset from library')
                            ->options(fn (Draft $r) => self::libraryAssetOptions($r))
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->placeholder('Search your asset library…')
                            ->helperText(fn (Draft $r) => self::draftNeedsVideo($r)
                                ? 'This is a video format on a video-capable platform — only videos are listed.'
                                : 'Images and videos from this brand\'s library.')
                            ->visible(fn (callable $get) => $get('source') === 'library')
                            ->required(fn (callable $get) => $get('source') === 'library'),

                        Placeholder::make('library_empty')
                            ->label('')
                            ->content('No assets in this brand\'s library yet. Switch to "Upload from this computer", or add files on the Asset library page first.')
                            ->visible(fn (callable $get, Draft $r) => $get('source') === 'library' && empty(self::libraryAssetOptions($r))),

                        // ---- Upload path ----
                        FileUpload::make('upload_file')
                            ->label('File to upload')
                            ->disk(fn () => self::preferredUploadDisk())
                            ->directory(fn (Draft $r) => 'brand-assets/'.$r->brand_id)
                            ->visibility('public')
                            ->preserveFilenames()
                            ->acceptedFileTypes([
                                'image/jpeg', 'image/png', 'image/webp', 'image/gif',
                                'video/mp4', 'video/quicktime', 'video/webm',
                            ])
                            ->maxSize(50 * 1024) // 50 MB
                            ->helperText('We check the file against this platform\'s publishing limits on apply. '
                                .'Images that are too large are auto-compressed to fit; videos that fail are returned with the exact fixes needed.')
                            ->visible(fn (callable $get) => $get('source') === 'upload')
                            ->required(fn (callable $get) => $get('source') === 'upload'),

                        Toggle::make('save_to_library')
                            ->label('Also save this file to the asset library')
                            ->helperText('Keep it for reuse — the Designer/Video agents and future drafts can pick it. Tagged via Claude vision on save.')
                            ->default(true)
                            ->visible(fn (callable $get) => $get('source') === 'upload'),
                    ])
                    ->action(function (Draft $r, array $data): void {
                        @set_time_limit(300);
                        try {
                            $note = self::applyManualMedia($r, $data);
                        } catch (MediaComplianceException $e) {
                            self::sendMediaComplianceFailure($e);

                            return;
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Could not apply media')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();

                            return;
                        }
                        Notification::make()
                            ->title('Media applied')
                            ->body($note)
                            ->success()
                            ->send();
                    }),

                Action::make('pickImage')
                    ->label(fn (Draft $r) => empty($r->asset_url) ? 'Auto-pick / generate image' : 'Auto-regenerate image')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn (Draft $r) => ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Draft $r) => self::regenModalDescription($r, 'library-first'))
                    ->action(function (Draft $r): void {
                        @set_time_limit(420);
                        if (! empty($r->asset_url)) {
                            $r->update(['asset_url' => null]);
                        }
                        try {
                            $result = app(DesignerAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Designer crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();

                            return;
                        }
                        if (! $result->ok) {
                            Notification::make()
                                ->title('Could not get image')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();

                            return;
                        }
                        // For video formats, the still is the keyframe; we
                        // MUST re-run VideoAgent so asset_url ends up as the
                        // mp4, not the just-regenerated jpeg. Skipping this
                        // is what produced the YouTube "static-image-as-video"
                        // takedown on 2026-05-07. Soft-fail: if Video fails,
                        // we'll surface that — the operator still has the
                        // image and can retry video manually.
                        $videoNote = self::rerunVideoIfNeeded($r);

                        Notification::make()
                            ->title('Image ready')
                            ->body('Image regenerated for this draft.'.$videoNote)
                            ->success()
                            ->send();
                    }),

                Action::make('forceAiImage')
                    ->label('Force AI image')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->visible(fn (Draft $r) => ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Draft $r) => self::regenModalDescription($r, 'force-fal'))
                    ->action(function (Draft $r): void {
                        @set_time_limit(420);
                        if (! empty($r->asset_url)) {
                            $r->update(['asset_url' => null]);
                        }
                        try {
                            $result = app(DesignerAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                                'force_fal' => true,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Image generation crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();

                            return;
                        }
                        if (! $result->ok) {
                            Notification::make()
                                ->title('Could not generate image')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();

                            return;
                        }
                        // See pickImage action above — same rationale: keep
                        // asset_url=mp4 for video formats by re-running Video.
                        $videoNote = self::rerunVideoIfNeeded($r);

                        Notification::make()
                            ->title('AI image ready')
                            ->body('A fresh AI image was generated for this draft.'.$videoNote)
                            ->success()
                            ->send();
                    }),

                Action::make('generateVideo')
                    ->label('Generate video')
                    ->icon('heroicon-o-film')
                    ->color('gray')
                    ->visible(fn (Draft $r) => FalAiClient::platformAcceptsVideo($r->platform)
                        && ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Draft $r) => empty($r->asset_url)
                        ? 'Generates a short vertical video for this draft with AI.'
                        : 'Uses the current still as the keyframe and generates a 5s vertical video around it. The old still moves into the asset history.')
                    ->action(function (Draft $r): void {
                        @set_time_limit(420);
                        try {
                            $result = app(VideoAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('VideoAgent crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();

                            return;
                        }
                        if (! $result->ok) {
                            Notification::make()
                                ->title('Could not generate video')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();

                            return;
                        }
                        Notification::make()
                            ->title('Video ready')
                            ->body(sprintf(
                                'A %ss vertical video was generated for this draft.',
                                $result->data['duration_seconds'] ?? '?',
                            ))
                            ->success()
                            ->send();
                    }),

                Action::make('rerunCompliance')
                    ->label('Re-run Compliance')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->visible(fn (Draft $r) => in_array($r->status, ['compliance_pending', 'compliance_failed']))
                    ->requiresConfirmation()
                    ->action(function (Draft $r): void {
                        @set_time_limit(180);
                        try {
                            $cr = app(ComplianceAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Compliance crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();

                            return;
                        }
                        $passed = ! empty($cr->data['all_passed']);
                        Notification::make()
                            ->title($passed ? 'Compliance passed' : 'Still failing')
                            ->body('Status: '.($cr->data['new_status'] ?? '?'))
                            ->color($passed ? 'success' : 'warning')
                            ->send();
                    }),

                Action::make('redraftNow')
                    ->label('Redraft now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Draft $r) => $r->status === 'compliance_failed'
                        && ($r->revision_count ?? 0) < RedraftFailedDraft::MAX_REVISIONS
                        && (bool) $r->calendar_entry_id)
                    ->requiresConfirmation()
                    ->modalHeading('Redraft this failed post')
                    ->modalDescription(fn (Draft $r) => sprintf(
                        'Asks the Writer to fix the %d failure(s) on this draft, then re-runs Compliance. Attempt %d of %d.',
                        $r->complianceChecks()->where('result', 'fail')->count(),
                        ($r->revision_count ?? 0) + 1,
                        RedraftFailedDraft::MAX_REVISIONS,
                    ))
                    ->action(function (Draft $r): void {
                        RedraftFailedDraft::dispatch($r->id);
                        Notification::make()
                            ->title('Redraft queued')
                            ->body('The Writer is fixing the violations and Compliance will re-run. Refresh in ~30s.')
                            ->success()
                            ->send();
                    }),

                Action::make('resetAttempts')
                    ->label('Reset attempts & retry')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Draft $r) => $r->status === 'compliance_failed'
                        && ($r->revision_count ?? 0) >= RedraftFailedDraft::MAX_REVISIONS
                        && (bool) $r->calendar_entry_id)
                    ->requiresConfirmation()
                    ->modalHeading('Reset retry counter and redraft')
                    ->modalDescription('Use after a Writer/Compliance prompt fix or after enriching the brand corpus. Zeroes the per-draft attempt counter and queues a fresh redraft. Will run up to '.RedraftFailedDraft::MAX_REVISIONS.' more attempts.')
                    ->action(function (Draft $r): void {
                        $r->forceFill([
                            'revision_count' => 0,
                            'last_redraft_at' => null,
                        ])->save();
                        RedraftFailedDraft::dispatch($r->id);
                        Notification::make()
                            ->title('Counter reset, redraft queued')
                            ->body('Refresh in ~30s.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('No drafts yet')
            ->emptyStateDescription('Run the Writer on a calendar entry to produce one.')
            ->emptyStateIcon(Heroicon::OutlinedPencilSquare);
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        // Tenant isolation: a user with no resolvable workspace sees nothing
        // (prevents cross-tenant IDOR). NO super-admin bypass (removed
        // 2026-06-02): the Agency panel is hard own-workspace for EVERYONE,
        // including HQ, which administers other tenants from /admin. See
        // BrandResource for the full rationale.
        if (! $workspaceId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at')->where('workspace_id', $workspaceId);
            });
    }

    /**
     * Modal copy for the regen-image actions. Tells the operator when THIS
     * draft will render as a summary poster (designed headline + key points as
     * text) vs a text-free photo. When the calendar entry is a video format on
     * a video-capable platform, also warns the video will be re-rendered (else
     * asset_url=jpeg on a video draft, which YouTube/TikTok scrub as
     * static-image-as-video).
     *
     * Customer-facing: deliberately omits the underlying AI model name and any
     * per-generation cost — those are internal economics the operator doesn't
     * surface to clients (see the matching Drafts-table cost-column removal).
     */
    private static function regenModalDescription(Draft $draft, string $mode): string
    {
        $model = (string) config('services.fal.image_model', 'fal-ai/nano-banana');

        $base = $mode === 'force-fal'
            ? 'Bypasses the brand asset library and generates a fresh image with AI. Use when you want bespoke art for this draft.'
            : 'Picks the best matching image from your brand asset library, and falls back to a fresh AI image only if no library asset matches.';

        // Tell the operator the actual output kind for THIS draft.
        $entry = $draft->calendarEntry;
        $willBePoster = FalAiClient::modelUsesAspectRatio($model)
            && ImageCreativeDirection::isPosterFormat(
                $entry?->format, $entry?->pillar, $entry?->visual_direction,
            );
        $base .= $willBePoster
            ? ' This draft is an educational/listicle/quote-card format, so it renders as a designed SUMMARY POSTER — headline + key points as legible text.'
            : ' This draft renders as a text-free editorial photo (the quote is stamped on afterward where applicable).';

        if (self::draftNeedsVideo($draft)) {
            $base .= ' Because this draft is a video format on a video-capable platform, the video will also be re-rendered after the image is ready so the publish target stays an mp4.';
        }

        return $base;
    }

    /**
     * Re-run VideoAgent if (and only if) the draft's calendar entry asks
     * for a video format AND the platform accepts video AND there isn't
     * already a video at asset_url after Designer's run.
     *
     * Returns a short status note suffix for the operator notification.
     * Soft-fail: any VideoAgent error is logged + surfaced in the note,
     * never thrown — the operator already has the regenerated image and
     * can click "Generate video" manually if this didn't work.
     */
    private static function rerunVideoIfNeeded(Draft $draft): string
    {
        if (! self::draftNeedsVideo($draft)) {
            return '';
        }

        // If Designer already returned an mp4 (e.g. brand-asset-library
        // found a matching video and BlotatoClient::uploadMediaFromUrl
        // returned a Blotato-hosted video URL), there's nothing to do.
        $current = (string) ($draft->fresh()->asset_url ?? '');
        $currentLower = strtolower($current);
        if ($current !== '' && (
            str_ends_with($currentLower, '.mp4')
            || str_ends_with($currentLower, '.mov')
            || str_ends_with($currentLower, '.webm')
        )) {
            return ' · video already attached';
        }

        try {
            $videoResult = app(VideoAgent::class)->run($draft->brand, [
                'draft_id' => $draft->id,
            ]);
        } catch (\Throwable $e) {
            Log::warning('DraftResource: VideoAgent re-run after image regen failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            return ' · video re-run crashed (click "Generate video" to retry)';
        }

        if (! $videoResult->ok) {
            return ' · video re-run failed: '.substr((string) $videoResult->errorMessage, 0, 80);
        }

        return ' · video ready';
    }

    /**
     * Detector: should this draft have an mp4 as its primary asset_url?
     * Mirrors the same condition DraftCalendarEntry uses (calendar entry
     * format + platform-accepts-video) so the regen UI matches the
     * autonomous pipeline's behaviour.
     */
    private static function draftNeedsVideo(Draft $draft): bool
    {
        $entry = $draft->calendarEntry;
        if (! $entry) {
            return false;
        }
        $format = strtolower((string) ($entry->format ?? ''));
        if (! in_array($format, ['reel', 'video', 'story'], true)) {
            return false;
        }

        return FalAiClient::platformAcceptsVideo($draft->platform);
    }

    /**
     * Options for the "choose from library" select on the replace-media modal.
     * Keyed by brand_asset_id => human label. Scoped strictly to the draft's
     * own brand (the modal already runs inside the tenant-isolated table query,
     * but we re-scope by brand_id here so the select can never list another
     * brand's assets). When the draft is a video format on a video-capable
     * platform we list videos only — picking an image there would leave the
     * publish target as a still and trip the static-image-as-video scrub.
     *
     * @return array<int, string>
     */
    private static function libraryAssetOptions(Draft $draft): array
    {
        $query = BrandAsset::query()
            ->where('brand_id', $draft->brand_id)
            ->whereNull('archived_at')
            ->orderByDesc('id');

        if (self::draftNeedsVideo($draft)) {
            $query->where('media_type', 'video');
        }

        return $query->limit(200)->get()->mapWithKeys(function (BrandAsset $a): array {
            $name = $a->original_filename ?: ($a->description ? Str::limit($a->description, 40) : "Asset #{$a->id}");
            $label = sprintf(
                '%s · %s%s',
                strtoupper($a->media_type),
                $name,
                $a->brand_approved ? '' : ' (not approved)',
            );

            return [$a->id => $label];
        })->all();
    }

    /** R2 if configured, else local public disk. Mirrors ManageBrandAssets. */
    private static function preferredUploadDisk(): string
    {
        return config('filesystems.disks.r2.bucket') ? 'r2' : 'public';
    }

    /**
     * Apply operator-chosen media to a draft. Two source paths converge on the
     * same persistence step:
     *
     *   library → resolve the BrandAsset, use its public_url, recordUse()
     *   upload  → the FileUpload already wrote the file to the preferred disk;
     *             resolve a public URL, and (optionally) register it as a
     *             BrandAsset so it's reusable + gets vision-tagged.
     *
     * Both then re-host through THIS WORKSPACE'S Blotato account so /v2/posts
     * accepts the media at publish time (Blotato rejects external mediaUrls,
     * and media is scoped to the uploading account — see DesignerAgent for the
     * cross-tenant rationale). Finally we persist asset_url + push the new URL
     * (and its source) into the asset_urls history.
     *
     * Returns a short human note for the success toast.
     */
    private static function applyManualMedia(Draft $draft, array $data): string
    {
        $brand = $draft->brand;
        if (! $brand) {
            throw new \RuntimeException('Draft has no brand.');
        }

        $source = $data['source'] ?? 'library';

        if ($source === 'library') {
            $assetId = (int) ($data['brand_asset_id'] ?? 0);
            /** @var BrandAsset|null $asset */
            $asset = BrandAsset::where('id', $assetId)
                ->where('brand_id', $draft->brand_id)
                ->whereNull('archived_at')
                ->first();
            if (! $asset) {
                throw new \RuntimeException('Selected library asset not found for this brand.');
            }

            $mediaType = $asset->media_type === 'video' ? 'video' : 'image';
            $sourceUrl = (string) $asset->public_url;

            // Compliance gate. Library assets can predate the media rules, so
            // we re-validate every pick. Probe a local copy (real path for
            // local disks, temp download for R2).
            [$localPath, $cleanup] = self::localCopyOf((string) $asset->storage_disk, (string) $asset->storage_path, $sourceUrl);
            try {
                $compNote = self::enforceMediaCompliance(
                    $localPath,
                    $draft->platform,
                    $mediaType,
                    onImageCompressed: function (string $compressedPath) use ($asset, &$sourceUrl): void {
                        // Persist the compressed image back over the library
                        // asset's stored file so future picks are compliant too.
                        $sourceUrl = self::overwriteStoredFile(
                            (string) $asset->storage_disk,
                            (string) $asset->storage_path,
                            $compressedPath,
                        );
                        $asset->forceFill([
                            'public_url' => $sourceUrl,
                            'file_size_bytes' => @filesize($compressedPath) ?: $asset->file_size_bytes,
                        ])->save();
                    },
                );
            } finally {
                $cleanup();
            }

            $blotatoUrl = self::rehostOnBlotato($brand, $sourceUrl);
            self::persistDraftMedia($draft, $blotatoUrl, [$blotatoUrl, $sourceUrl]);
            $asset->recordUse();

            return sprintf(
                'Library %s "%s" attached%s%s.',
                $mediaType,
                $asset->original_filename ?: "#{$asset->id}",
                $mediaType === 'video' ? '' : ' · no AI cost',
                $compNote,
            );
        }

        // ---- Upload path ----
        $disk = self::preferredUploadDisk();
        $relativePath = $data['upload_file'] ?? null;
        if (is_array($relativePath)) {
            $relativePath = $relativePath[0] ?? null;
        }
        if (! $relativePath) {
            throw new \RuntimeException('No file was uploaded.');
        }

        $storage = Storage::disk($disk);
        $sourceUrl = $storage->url($relativePath);
        $mime = $storage->mimeType($relativePath) ?: '';
        $isVideo = str_starts_with($mime, 'video/');
        $mediaType = $isVideo ? 'video' : 'image';

        // Compliance gate BEFORE Blotato re-host. For images we auto-compress
        // and write the compliant file back over the upload; for videos a
        // failure throws MediaComplianceException → fail popup with fixes.
        [$localPath, $cleanup] = self::localCopyOf($disk, $relativePath, $sourceUrl);
        try {
            $compNote = self::enforceMediaCompliance(
                $localPath,
                $draft->platform,
                $mediaType,
                onImageCompressed: function (string $compressedPath) use ($disk, $relativePath, &$sourceUrl): void {
                    $sourceUrl = self::overwriteStoredFile($disk, $relativePath, $compressedPath);
                },
            );
        } finally {
            $cleanup();
        }

        $blotatoUrl = self::rehostOnBlotato($brand, $sourceUrl);

        $savedNote = '';
        if (! empty($data['save_to_library'])) {
            $asset = BrandAsset::create([
                'brand_id' => $draft->brand_id,
                'uploaded_by_user_id' => auth()->id(),
                'media_type' => $mediaType,
                'source' => 'upload',
                'storage_disk' => $disk,
                'storage_path' => $relativePath,
                'public_url' => $sourceUrl,
                'original_filename' => basename($relativePath),
                'mime_type' => $mime ?: null,
                'file_size_bytes' => $storage->size($relativePath) ?: null,
                'brand_approved' => true,
                'use_count' => 1,
                'last_used_at' => now(),
            ]);
            try {
                app(BrandAssetTagger::class)->tag($asset);
            } catch (\Throwable $e) {
                Log::warning('replaceMedia: tagger failed', [
                    'asset_id' => $asset->id,
                    'error' => $e->getMessage(),
                ]);
            }
            $savedNote = ' · saved to library';
        }

        self::persistDraftMedia($draft, $blotatoUrl, [$blotatoUrl, $sourceUrl]);

        return sprintf('Uploaded %s attached%s%s.', $mediaType, $savedNote, $compNote);
    }

    /**
     * Run the media-file compliance gate on a local file and resolve it.
     *
     *   - PASS                          → return '' (no note)
     *   - FAIL, image, compressible     → auto-compress, re-check; on success
     *                                      invoke $onImageCompressed(path) so
     *                                      the caller can persist the fixed file,
     *                                      and return a " · auto-compressed …" note
     *   - FAIL, otherwise (video, or an
     *     image that compression can't
     *     rescue, or non-compressible
     *     image issues like aspect)     → throw MediaComplianceException with the
     *                                      structured reasons + suggestions
     *
     * @param  callable(string):void  $onImageCompressed  receives the compressed local path
     */
    private static function enforceMediaCompliance(
        string $localPath,
        string $platform,
        string $mediaType,
        callable $onImageCompressed,
    ): string {
        $checker = app(MediaComplianceChecker::class);
        $result = $checker->check($localPath, $platform, $mediaType);

        if ($result['passed']) {
            return '';
        }

        // Advisory-only failures (e.g. ffprobe unavailable for video) must not
        // block — strip them; if nothing blocking remains, pass.
        $blocking = array_values(array_filter(
            $result['violations'],
            fn (array $v) => ($v['kind'] ?? '') !== 'probe_advisory',
        ));
        if (empty($blocking)) {
            return '';
        }

        // Only attempt compression when EVERY blocking violation is
        // compression-fixable AND this is an image. A mix (e.g. oversize +
        // wrong aspect) can't be fully fixed by compression, so we fail with
        // the full reason list rather than half-fixing and re-failing.
        $allFixable = collect($blocking)->every(fn (array $v) => ! empty($v['fixable_by_compression']));

        if ($mediaType === 'image' && $allFixable) {
            try {
                $compressed = app(ImageAutoCompressor::class)
                    ->compressForPlatform($localPath, $platform);
            } catch (\Throwable $e) {
                // Compression itself failed — surface as a compliance failure
                // with the original reasons plus why we couldn't auto-fix.
                throw new MediaComplianceException(
                    violations: array_merge($blocking, [[
                        'kind' => 'compression_failed',
                        'reason' => 'We tried to compress the image but couldn\'t: '.substr($e->getMessage(), 0, 160),
                        'suggestion' => 'Re-export the image smaller (e.g. 1080px wide JPEG) and upload again.',
                        'fixable_by_compression' => false,
                        'detail' => [],
                    ]]),
                    platform: $platform,
                    mediaType: $mediaType,
                );
            }

            // Re-check the compressed output to be certain it now passes.
            $recheck = $checker->check($compressed['path'], $platform, $mediaType);
            $recheckBlocking = array_values(array_filter(
                $recheck['violations'],
                fn (array $v) => ($v['kind'] ?? '') !== 'probe_advisory',
            ));
            if (! empty($recheckBlocking)) {
                @unlink($compressed['path']);
                throw new MediaComplianceException(
                    violations: $recheckBlocking,
                    platform: $platform,
                    mediaType: $mediaType,
                    message: 'Compressed image still does not meet the platform rules.',
                );
            }

            try {
                $onImageCompressed($compressed['path']);
            } finally {
                @unlink($compressed['path']);
            }

            return sprintf(
                ' · auto-compressed to %d×%d, %s (q%d)',
                $compressed['width'],
                $compressed['height'],
                PlatformMediaRules::humanBytes($compressed['bytes']),
                $compressed['quality'],
            );
        }

        // Not auto-fixable (video, or image with non-compressible issues).
        throw new MediaComplianceException(
            violations: $blocking,
            platform: $platform,
            mediaType: $mediaType,
        );
    }

    /**
     * Obtain a readable LOCAL path for a stored file. Local disks expose the
     * real path directly; cloud disks (R2) require a temp download. Returns
     * [localPath, cleanupCallable] — always call cleanup() in a finally.
     *
     * @return array{0:string, 1:callable():void}
     */
    private static function localCopyOf(string $disk, string $relativePath, string $publicUrl): array
    {
        $storage = Storage::disk($disk);

        // Local/public disks expose a filesystem path.
        try {
            $real = $storage->path($relativePath);
            if (is_string($real) && is_file($real)) {
                return [$real, fn () => null];
            }
        } catch (\Throwable) {
            // Driver without path() (cloud) — fall through to download.
        }

        // Cloud disk: download to a temp file.
        $tmp = tempnam(sys_get_temp_dir(), 'media_chk_');
        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);
        if ($ext !== '') {
            $tmpWithExt = $tmp.'.'.$ext;
            @rename($tmp, $tmpWithExt);
            $tmp = $tmpWithExt;
        }

        $bytes = null;
        try {
            $bytes = $storage->get($relativePath);
        } catch (\Throwable) {
            // Fall back to fetching the public URL.
            $bytes = @file_get_contents($publicUrl) ?: null;
        }
        if ($bytes === null || $bytes === '') {
            @unlink($tmp);
            throw new \RuntimeException('Could not read the media file for compliance checking.');
        }
        file_put_contents($tmp, $bytes);

        return [$tmp, function () use ($tmp): void {
            @unlink($tmp);
        }];
    }

    /**
     * Overwrite a stored file with the bytes from a local path and return the
     * (unchanged) public URL. Used to persist an auto-compressed image back
     * over the original upload / library asset so the publishable copy and the
     * stored copy agree.
     */
    private static function overwriteStoredFile(string $disk, string $relativePath, string $localPath): string
    {
        $storage = Storage::disk($disk);
        $bytes = file_get_contents($localPath);
        if ($bytes === false) {
            throw new \RuntimeException('Could not read compressed image to store.');
        }
        $storage->put($relativePath, $bytes, 'public');

        return $storage->url($relativePath);
    }

    /**
     * Render the media-compliance failure as a persistent danger notification
     * (the "fail popup") listing every reason and the suggested fix.
     */
    private static function sendMediaComplianceFailure(MediaComplianceException $e): void
    {
        $lines = collect($e->violations)->map(function (array $v): string {
            $reason = trim((string) ($v['reason'] ?? ''));
            $suggestion = trim((string) ($v['suggestion'] ?? ''));

            return $suggestion !== ''
                ? "• {$reason}\n   → {$suggestion}"
                : "• {$reason}";
        })->implode("\n");

        $verb = $e->mediaType === 'video'
            ? 'This video can\'t be auto-fixed — please re-export it:'
            : 'Here\'s what needs fixing:';

        Notification::make()
            ->title(ucfirst($e->mediaType).' failed '.ucfirst($e->platform).' compliance')
            ->body($verb."\n\n".$lines)
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Re-host a media URL through the brand's workspace Blotato account so it's
     * publishable. Per the per-workspace-isolation invariant we always use
     * forWorkspace(), never fromConfig().
     */
    private static function rehostOnBlotato(Brand $brand, string $url): string
    {
        if (! $brand->workspace) {
            throw new \RuntimeException('Brand has no workspace — cannot resolve Blotato account.');
        }

        return BlotatoClient::forWorkspace($brand->workspace)
            ->uploadMediaFromUrl($url);
    }

    /**
     * Persist the chosen media on the draft. asset_url becomes the
     * Blotato-hosted (publishable) URL; asset_urls keeps a de-duplicated
     * history including the original source URL for provenance.
     *
     * @param  array<int, string>  $urlsToRemember
     */
    private static function persistDraftMedia(Draft $draft, string $publishableUrl, array $urlsToRemember): void
    {
        $draft->update([
            'asset_url' => $publishableUrl,
            'asset_urls' => array_values(array_unique(array_merge(
                is_array($draft->asset_urls) ? $draft->asset_urls : [],
                array_filter($urlsToRemember),
            ))),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDrafts::route('/'),
        ];
    }

    /**
     * Plain-English next step per draft. Format: '<ACTOR>: <verb>'.
     *   YOU:  operator action required
     *   AUTO: cron / agent will progress this on its own; nothing to do
     *   WAIT: waiting on a system that's not us (rare)
     *   DONE: terminal state
     *   FAIL: terminal failure that needs operator attention
     */
    public static function nextActionFor(Draft $draft): string
    {
        $hasSchedule = $draft->scheduledPosts()
            ->whereIn('status', ['queued', 'submitting', 'submitted', 'published'])
            ->exists();

        return match ($draft->status) {
            'compliance_pending' => 'WAIT for Compliance to finish (auto, ~30s)',
            'compliance_failed' => 'FAIL — auto-redraft runs every 5 min (cap '.RedraftFailedDraft::MAX_REVISIONS.'). Click Redraft now to retry immediately.',
            'awaiting_approval' => 'YOU: click Approve, then Schedule',
            'approved' => $hasSchedule
                ? 'AUTO: cron will publish on schedule'
                : 'YOU: click Schedule to queue for publishing',
            'scheduled' => 'AUTO: cron picks this up at scheduled_for time',
            'published' => 'DONE — view on Live feed',
            'rejected' => 'DONE — rejected; will not publish',
            default => '—',
        };
    }
}

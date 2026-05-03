<?php

namespace App\Filament\Agency\Resources\Drafts;

use App\Filament\Agency\Resources\Drafts\Pages\ManageDrafts;
use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Models\ScheduledPost;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
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
                Tables\Columns\TextColumn::make('cost_usd')
                    ->label('Cost')
                    ->money('USD', divideBy: 1)
                    ->color('gray')
                    ->size('sm')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->since()
                    ->color('gray')
                    ->size('sm')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
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
                    ->options([
                        'instagram' => 'Instagram',
                        'facebook' => 'Facebook',
                        'linkedin' => 'LinkedIn',
                        'tiktok' => 'TikTok',
                        'threads' => 'Threads',
                        'x' => 'X (Twitter)',
                        'youtube' => 'YouTube',
                    ]),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('view')
                    ->label('View')
                    ->icon('heroicon-o-eye')
                    ->color('gray')
                    ->modalHeading(fn (Draft $r) => "Draft #{$r->id} — " . ucfirst($r->platform))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Draft $r) => view('filament.agency.partials.draft-view', [
                        'draft' => $r,
                    ])),

                \Filament\Actions\Action::make('approve')
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
                        \Filament\Notifications\Notification::make()
                            ->title('Approved')
                            ->body("Draft #{$r->id} approved. Use 'Schedule' to queue it.")
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Draft $r) => in_array($r->status, ['awaiting_approval', 'approved']))
                    ->schema([
                        \Filament\Forms\Components\Textarea::make('rejection_reason')
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
                        \Filament\Notifications\Notification::make()
                            ->title('Rejected')
                            ->body("Draft #{$r->id} rejected.")
                            ->warning()
                            ->send();
                    }),

                \Filament\Actions\Action::make('schedule')
                    ->label('Schedule')
                    ->icon('heroicon-o-clock')
                    ->color('primary')
                    ->visible(fn (Draft $r) => in_array($r->status, ['approved', 'awaiting_approval']))
                    ->schema([
                        \Filament\Forms\Components\DateTimePicker::make('scheduled_for')
                            ->label(fn (Draft $r) => 'Publish at (' . ($r->brand?->timezone ?: 'UTC') . ')')
                            ->helperText(fn (Draft $r) => 'Pick a time in your brand timezone (' . ($r->brand?->timezone ?: 'UTC') . '). The system stores it as UTC internally.')
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
                            \Filament\Notifications\Notification::make()
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
                            \Filament\Notifications\Notification::make()
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
                        $scheduledForUtc = \Illuminate\Support\Carbon::parse($data['scheduled_for']);
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

                        \Filament\Notifications\Notification::make()
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

                \Filament\Actions\Action::make('pickImage')
                    ->label(fn (Draft $r) => empty($r->asset_url) ? 'Pick / generate image' : 'Replace image')
                    ->icon('heroicon-o-photo')
                    ->color('gray')
                    ->visible(fn (Draft $r) => ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription('Picks the best matching image from your brand asset library. Falls back to FAL flux-schnell ($0.003) only if no library asset matches.')
                    ->action(function (Draft $r): void {
                        @set_time_limit(180);
                        if (! empty($r->asset_url)) {
                            $r->update(['asset_url' => null]);
                        }
                        try {
                            $result = app(\App\Agents\DesignerAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Designer crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();
                            return;
                        }
                        if (! $result->ok) {
                            \Filament\Notifications\Notification::make()
                                ->title('Could not get image')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();
                            return;
                        }
                        $source = $result->data['source'] ?? 'fal';
                        \Filament\Notifications\Notification::make()
                            ->title('Image ready · ' . $source)
                            ->body(sprintf(
                                '$%.4f · %dms',
                                $result->data['cost_usd'] ?? 0,
                                $result->data['latency_ms'] ?? 0,
                            ))
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('forceAiImage')
                    ->label('Force AI image')
                    ->icon('heroicon-o-sparkles')
                    ->color('warning')
                    ->visible(fn (Draft $r) => ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription('Bypasses the brand asset library and goes straight to FAL.AI. Use when you want bespoke art for this draft. Cost ~$0.003 (flux-schnell) or up to $0.04 (flux-pro / recraft) depending on configured model.')
                    ->action(function (Draft $r): void {
                        @set_time_limit(180);
                        if (! empty($r->asset_url)) {
                            $r->update(['asset_url' => null]);
                        }
                        try {
                            $result = app(\App\Agents\DesignerAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                                'force_fal' => true,
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('FAL crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();
                            return;
                        }
                        if (! $result->ok) {
                            \Filament\Notifications\Notification::make()
                                ->title('FAL refused')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();
                            return;
                        }
                        \Filament\Notifications\Notification::make()
                            ->title('AI image ready')
                            ->body(sprintf(
                                '$%.4f · %dms · model %s',
                                $result->data['cost_usd'] ?? 0,
                                $result->data['latency_ms'] ?? 0,
                                $result->data['model'] ?? '?',
                            ))
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('generateVideo')
                    ->label('Generate video')
                    ->icon('heroicon-o-film')
                    ->color('gray')
                    ->visible(fn (Draft $r) => \App\Services\Imagery\FalAiClient::platformAcceptsVideo($r->platform)
                        && ! in_array($r->status, ['published', 'rejected']))
                    ->requiresConfirmation()
                    ->modalDescription(fn (Draft $r) => empty($r->asset_url)
                        ? 'Run VideoAgent (FAL Wan 2.6 text-to-video, ~$0.50, ~30s).'
                        : 'Use the current still as keyframe and generate a 5s vertical video around it (FAL Wan 2.6 image-to-video, ~$0.50, ~30s). Old still moves into asset_urls history.')
                    ->action(function (Draft $r): void {
                        @set_time_limit(420);
                        try {
                            $result = app(\App\Agents\VideoAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('VideoAgent crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();
                            return;
                        }
                        if (! $result->ok) {
                            \Filament\Notifications\Notification::make()
                                ->title('Could not generate video')
                                ->body($result->errorMessage ?: 'unknown')
                                ->danger()
                                ->send();
                            return;
                        }
                        \Filament\Notifications\Notification::make()
                            ->title('Video ready')
                            ->body(sprintf(
                                '$%.2f · %dms · %ss · %s',
                                $result->data['cost_usd'] ?? 0,
                                $result->data['latency_ms'] ?? 0,
                                $result->data['duration_seconds'] ?? '?',
                                ($result->data['used_keyframe'] ?? false) ? 'i2v' : 't2v',
                            ))
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('rerunCompliance')
                    ->label('Re-run Compliance')
                    ->icon('heroicon-o-shield-check')
                    ->color('gray')
                    ->visible(fn (Draft $r) => in_array($r->status, ['compliance_pending', 'compliance_failed']))
                    ->requiresConfirmation()
                    ->action(function (Draft $r): void {
                        @set_time_limit(180);
                        try {
                            $cr = app(\App\Agents\ComplianceAgent::class)->run($r->brand, [
                                'draft_id' => $r->id,
                            ]);
                        } catch (\Throwable $e) {
                            \Filament\Notifications\Notification::make()
                                ->title('Compliance crashed')
                                ->body(substr($e->getMessage(), 0, 240))
                                ->danger()
                                ->send();
                            return;
                        }
                        $passed = ! empty($cr->data['all_passed']);
                        \Filament\Notifications\Notification::make()
                            ->title($passed ? 'Compliance passed' : 'Still failing')
                            ->body('Status: ' . ($cr->data['new_status'] ?? '?'))
                            ->color($passed ? 'success' : 'warning')
                            ->send();
                    }),

                \Filament\Actions\Action::make('redraftNow')
                    ->label('Redraft now')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (Draft $r) => $r->status === 'compliance_failed'
                        && ($r->revision_count ?? 0) < \App\Jobs\RedraftFailedDraft::MAX_REVISIONS
                        && (bool) $r->calendar_entry_id)
                    ->requiresConfirmation()
                    ->modalHeading('Redraft this failed post')
                    ->modalDescription(fn (Draft $r) => sprintf(
                        'Asks the Writer to fix the %d failure(s) on this draft, then re-runs Compliance. Attempt %d of %d. Each attempt costs ~$0.02–0.05.',
                        $r->complianceChecks()->where('result', 'fail')->count(),
                        ($r->revision_count ?? 0) + 1,
                        \App\Jobs\RedraftFailedDraft::MAX_REVISIONS,
                    ))
                    ->action(function (Draft $r): void {
                        \App\Jobs\RedraftFailedDraft::dispatch($r->id);
                        \Filament\Notifications\Notification::make()
                            ->title('Redraft queued')
                            ->body('The Writer is fixing the violations and Compliance will re-run. Refresh in ~30s.')
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('resetAttempts')
                    ->label('Reset attempts & retry')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (Draft $r) => $r->status === 'compliance_failed'
                        && ($r->revision_count ?? 0) >= \App\Jobs\RedraftFailedDraft::MAX_REVISIONS
                        && (bool) $r->calendar_entry_id)
                    ->requiresConfirmation()
                    ->modalHeading('Reset retry counter and redraft')
                    ->modalDescription('Use after a Writer/Compliance prompt fix or after enriching the brand corpus. Zeroes the per-draft attempt counter and queues a fresh redraft. Will run up to '.\App\Jobs\RedraftFailedDraft::MAX_REVISIONS.' more attempts.')
                    ->action(function (Draft $r): void {
                        $r->forceFill([
                            'revision_count' => 0,
                            'last_redraft_at' => null,
                        ])->save();
                        \App\Jobs\RedraftFailedDraft::dispatch($r->id);
                        \Filament\Notifications\Notification::make()
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

        // Tenant isolation: super admin sees everything; anyone else without a
        // resolvable workspace sees nothing (prevents cross-tenant IDOR).
        if ($user?->is_super_admin) {
            return parent::getEloquentQuery()
                ->whereHas('brand', fn (Builder $q) => $q->whereNull('archived_at'));
        }

        if (! $workspaceId) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->whereHas('brand', function (Builder $q) use ($workspaceId) {
                $q->whereNull('archived_at')->where('workspace_id', $workspaceId);
            });
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
            'compliance_failed' => 'FAIL — auto-redraft runs every 5 min (cap '.\App\Jobs\RedraftFailedDraft::MAX_REVISIONS.'). Click Redraft now to retry immediately.',
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

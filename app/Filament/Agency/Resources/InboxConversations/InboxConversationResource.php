<?php

namespace App\Filament\Agency\Resources\InboxConversations;

use App\Agents\CommunityAgent;
use App\Filament\Agency\Resources\InboxConversations\Pages\ManageInboxConversations;
use App\Models\InboxConversation;
use App\Models\InboxReplyDraft;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The human approval gate for the L4 community actuator.
 *
 * Every reply the CommunityAgent drafts stops here. Nothing reaches a real
 * person without someone clicking Approve — the send is irreversible (we hold no
 * platform tokens and cannot delete a sent message) and it goes out under the
 * brand's name to a named individual, so a model's confidence is not an
 * acceptable gate on its own.
 *
 * Ordered by reply-window expiry, because these deadlines are real: 24h for
 * comments, 7d for DMs, enforced by the platform. A conversation sorted below
 * the fold is one that silently becomes unanswerable.
 *
 * Own-workspace scoped for EVERYONE including HQ — same discipline as
 * GrowthGoalResource and PlatformConnectionResource.
 */
class InboxConversationResource extends Resource
{
    use \App\Filament\Agency\Concerns\ScopesToSelectedBrands;

    protected static ?string $model = InboxConversation::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;
    protected static ?string $navigationLabel = 'Inbox';
    protected static ?string $modelLabel = 'Conversation';
    protected static ?string $pluralModelLabel = 'Inbox';
    protected static ?int $navigationSort = 10;

    /**
     * Own-workspace only, deny-by-default. Mirrors
     * GrowthGoalResource::getEloquentQuery — a user with no resolvable workspace
     * sees NOTHING rather than falling through to an unscoped query. Enforced by
     * tests/Unit/TenantIsolationGuardTest.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $workspaceId = $user?->current_workspace_id
            ?? $user?->ownedWorkspaces()->value('id');

        $query = parent::getEloquentQuery()->with(['brand', 'replyDrafts']);

        if (! $workspaceId) {
            return $query->whereRaw('1 = 0');
        }

        // Workspace isolation first (authoritative), then the operator's brand
        // selection from the topbar switcher on top of it. The table also keeps
        // its own per-column brand filter for a one-off narrowing that shouldn't
        // change the whole panel's scope.
        return self::applyBrandScope($query->where('workspace_id', $workspaceId));
    }

    /** Count of things genuinely waiting on a human — drives the sidebar badge. */
    public static function getNavigationBadge(): ?string
    {
        $n = static::getEloquentQuery()
            ->awaitingReply()
            ->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        // Read-only surface: conversations mirror an external system and replies
        // are composed through the Approve action, not an edit form.
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('window_expires_at', 'asc')
            ->columns([
                Tables\Columns\TextColumn::make('brand.name')
                    ->label('Brand')
                    ->sortable()
                    ->toggleable(),
                Tables\Columns\TextColumn::make('provider')
                    ->label('Platform')
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? ucfirst(strtolower($state)) : '—'),
                Tables\Columns\TextColumn::make('conversation_type')
                    ->label('Type')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        InboxConversation::TYPE_COMMENT => 'info',
                        InboxConversation::TYPE_REVIEW => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        InboxConversation::TYPE_COMMENT => 'Comment',
                        InboxConversation::TYPE_REVIEW => 'Review',
                        default => 'DM',
                    }),
                Tables\Columns\TextColumn::make('participant_name')
                    ->label('From')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('last_message_text')
                    ->label('Message')
                    ->limit(60)
                    ->tooltip(fn (?string $state) => $state)
                    ->placeholder('(no text — story mention, reaction or attachment)'),
                Tables\Columns\TextColumn::make('window_expires_at')
                    ->label('Reply window')
                    ->sortable()
                    // The whole point of the column is urgency, so show relative
                    // time and colour it — an absolute timestamp buries the fact
                    // that something has 40 minutes left.
                    // A review genuinely has no deadline — say so, rather than
                    // rendering the same "unknown" we'd show for a missing
                    // timestamp on a comment.
                    ->formatStateUsing(fn (?\Illuminate\Support\Carbon $state, InboxConversation $r) => match (true) {
                        $r->conversation_type === InboxConversation::TYPE_REVIEW => 'no deadline',
                        $state === null => 'unknown',
                        $state->isPast() => 'CLOSED',
                        default => 'closes '.$state->diffForHumans(),
                    })
                    ->color(fn (InboxConversation $r) => match (true) {
                        $r->window_expires_at === null => 'gray',
                        $r->window_expires_at->isPast() => 'danger',
                        $r->window_expires_at->lessThan(now()->addHours(4)) => 'warning',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('draft_state')
                    ->label('Draft')
                    ->badge()
                    ->state(fn (InboxConversation $r) => self::draftLabel($r))
                    ->color(fn (InboxConversation $r) => match (self::draftLabel($r)) {
                        'Awaiting approval' => 'warning',
                        'Sent' => 'success',
                        'No reply advised' => 'gray',
                        'Approved' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('awaiting')
                    ->label('Awaiting reply')
                    ->queries(
                        true: fn (Builder $q) => $q->awaitingReply(),
                        false: fn (Builder $q) => $q,
                        blank: fn (Builder $q) => $q,
                    ),
                Tables\Filters\SelectFilter::make('conversation_type')
                    ->label('Type')
                    ->options([
                        InboxConversation::TYPE_DM => 'Direct messages',
                        InboxConversation::TYPE_COMMENT => 'Post comments',
                        InboxConversation::TYPE_REVIEW => 'Reviews',
                    ]),
                Tables\Filters\SelectFilter::make('brand_id')
                    ->label('Brand')
                    ->relationship('brand', 'name'),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('approve')
                    ->label('Approve & send')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('success')
                    // Reviews are ingest-only until the review-reply request
                    // shape is verified against a real Google Business Profile.
                    ->visible(fn (InboxConversation $r) => $r->conversation_type !== InboxConversation::TYPE_REVIEW
                        && self::pendingDraft($r)?->recommends_no_reply === false)
                    ->requiresConfirmation()
                    ->modalHeading('Send this reply?')
                    ->modalDescription(fn (InboxConversation $r) => 'This sends a real message to '
                        .($r->participant_name ?? 'this person')
                        .' on '.ucfirst(strtolower($r->provider))
                        .'. It cannot be unsent.')
                    ->modalSubmitActionLabel('Send it')
                    ->schema(fn (InboxConversation $r) => [
                        \Filament\Forms\Components\Textarea::make('body')
                            ->label('Reply')
                            ->default(fn () => self::pendingDraft($r)?->body)
                            ->helperText('Edit before sending if you want to — what is here is exactly what goes out.')
                            ->rows(4)
                            ->required(),
                    ])
                    ->action(function (InboxConversation $r, array $data): void {
                        $draft = self::pendingDraft($r);
                        if (! $draft) {
                            Notification::make()->title('No draft to send')->warning()->send();

                            return;
                        }
                        if (! $r->windowIsOpen()) {
                            $draft->update([
                                'status' => InboxReplyDraft::STATUS_EXPIRED,
                                'last_error' => 'Reply window closed before approval.',
                            ]);
                            Notification::make()
                                ->title('Too late — the reply window closed')
                                ->body('The platform will no longer accept a reply on this conversation.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $draft->update([
                            'body' => trim((string) ($data['body'] ?? $draft->body)),
                            'status' => InboxReplyDraft::STATUS_APPROVED,
                            'approved_by_user_id' => auth()->id(),
                            'approved_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Queued for sending')
                            ->body('It goes out within a few minutes.')
                            ->success()
                            ->send();
                    }),

                \Filament\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (InboxConversation $r) => self::pendingDraft($r) !== null)
                    ->requiresConfirmation()
                    ->modalHeading('Discard this draft?')
                    ->action(function (InboxConversation $r): void {
                        self::pendingDraft($r)?->update(['status' => InboxReplyDraft::STATUS_REJECTED]);
                        Notification::make()->title('Draft discarded')->success()->send();
                    }),

                \Filament\Actions\Action::make('draft')
                    ->label(fn (InboxConversation $r) => self::pendingDraft($r) ? 'Redraft' : 'Draft a reply')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn (InboxConversation $r) => $r->windowIsOpen() && ! $r->last_message_from_us)
                    ->action(function (InboxConversation $r): void {
                        $brand = $r->brand;
                        if (! $brand) {
                            Notification::make()->title('Brand missing')->danger()->send();

                            return;
                        }

                        $result = app(CommunityAgent::class)->run($brand, [
                            'conversation_id' => $r->id,
                            'force' => true,
                        ]);

                        if (! $result->ok) {
                            Notification::make()->title('Could not draft a reply')->body($result->errorMessage)->danger()->send();

                            return;
                        }
                        if (! empty($result->data['skipped'])) {
                            Notification::make()->title('Skipped')->body((string) ($result->data['reason'] ?? ''))->warning()->send();

                            return;
                        }
                        if (! empty($result->data['recommends_no_reply'])) {
                            Notification::make()
                                ->title('No reply recommended')
                                ->body('Open the row to see why.')
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()->title('Draft ready for your approval')->success()->send();
                    }),
            ])
            ->emptyStateHeading('Nothing in the inbox yet')
            ->emptyStateDescription('Comments and messages appear here within the hour of arriving.');
    }

    /** The one draft currently awaiting a human, or null. */
    public static function pendingDraft(InboxConversation $conversation): ?InboxReplyDraft
    {
        return $conversation->replyDrafts
            ->firstWhere('status', InboxReplyDraft::STATUS_PENDING_APPROVAL);
    }

    /** Human label for the conversation's current draft state. */
    public static function draftLabel(InboxConversation $conversation): string
    {
        $drafts = $conversation->replyDrafts;
        if ($drafts->isEmpty()) {
            return 'None';
        }
        if ($drafts->firstWhere('status', InboxReplyDraft::STATUS_SENT)) {
            return 'Sent';
        }
        if ($drafts->firstWhere('status', InboxReplyDraft::STATUS_APPROVED)) {
            return 'Approved';
        }
        $pending = $drafts->firstWhere('status', InboxReplyDraft::STATUS_PENDING_APPROVAL);
        if ($pending) {
            return $pending->recommends_no_reply ? 'No reply advised' : 'Awaiting approval';
        }
        if ($drafts->firstWhere('status', InboxReplyDraft::STATUS_EXPIRED)) {
            return 'Expired';
        }

        return 'None';
    }

    public static function getPages(): array
    {
        return ['index' => ManageInboxConversations::route('/')];
    }
}

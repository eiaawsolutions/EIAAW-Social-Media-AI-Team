<?php

namespace App\Console\Commands;

use App\Models\InboxConversation;
use App\Models\InboxReplyDraft;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Delivers replies a HUMAN has already approved. This is the only code path in
 * the system that sends a message to an individual person.
 *
 * It will only ever pick up drafts at status='approved', which is set exclusively
 * by an operator action in the Filament inbox page. There is no path from
 * "drafted" to "sent" that does not pass through a human — deliberately, because
 * the send is irreversible (we hold no platform tokens and cannot delete a
 * published message) and it goes out under the brand's name.
 *
 * Re-checks the reply window immediately before sending: a draft approved 20
 * hours ago on a 24-hour comment window may have expired while it sat in the
 * queue, and the platform would reject it anyway.
 */
class CommunitySend extends Command
{
    protected $signature = 'community:send
                            {--brand= : Restrict to a single brand id}
                            {--limit=50 : Max replies to send in one run}
                            {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send inbox replies that an operator has approved.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $drafts = InboxReplyDraft::query()
            ->sendable()
            ->when($this->option('brand'), fn ($q, $id) => $q->where('brand_id', $id))
            ->with(['conversation', 'brand'])
            ->orderBy('approved_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        if ($drafts->isEmpty()) {
            $this->info('No approved replies to send.');

            return self::SUCCESS;
        }

        $client = MetricoolClient::fromConfig();
        if (! $client && ! $dry) {
            $this->error('Metricool client unavailable — cannot send.');

            return self::FAILURE;
        }

        $sent = 0;
        $expired = 0;
        $failed = 0;

        foreach ($drafts as $draft) {
            $conversation = $draft->conversation;
            $brand = $draft->brand;

            if (! $conversation || ! $brand || ! $brand->metricool_blog_id) {
                $this->markFailed($draft, 'Conversation, brand, or Metricool blog id missing.');
                $failed++;
                continue;
            }

            // The window may have closed while this sat awaiting approval.
            if (! $conversation->windowIsOpen()) {
                $draft->update([
                    'status' => InboxReplyDraft::STATUS_EXPIRED,
                    'last_error' => 'Reply window closed before the approved reply was sent.',
                ]);
                $expired++;
                $this->warn(sprintf('  draft#%d expired — window closed %s', $draft->id, $conversation->window_expires_at?->diffForHumans()));
                continue;
            }

            if ($dry) {
                $this->line(sprintf(
                    '  [dry] draft#%d → %s %s to %s: %s',
                    $draft->id,
                    $conversation->provider,
                    $conversation->conversation_type,
                    $conversation->participant_name ?? '?',
                    mb_substr($draft->body, 0, 70),
                ));
                continue;
            }

            // An empty provider round-trips to Metricool, is rejected, and parks
            // the draft at STATUS_FAILED — which nothing retries, so an operator's
            // approved reply would be lost silently. Fail it here with a cause.
            if (trim((string) $conversation->provider) === '') {
                $this->markFailed($draft, 'Conversation has no provider recorded; cannot address the reply.');
                $failed++;
                continue;
            }

            // Reviews are ingest-only for now: POST /v2/inbox/reviews/replies
            // exists in the spec but we have no Google Business Profile
            // connected, so its request shape is UNVERIFIED. Guessing it would
            // mean posting an unproven body to a customer-facing surface.
            if ($conversation->conversation_type === InboxConversation::TYPE_REVIEW) {
                $this->markFailed($draft, 'Review replies are not wired yet — reply in Metricool directly.');
                $failed++;
                continue;
            }

            try {
                $blogId = (int) $brand->metricool_blog_id;

                if ($conversation->conversation_type === InboxConversation::TYPE_COMMENT) {
                    $client->replyToPostComment($blogId, $conversation->provider, $conversation->external_id, $draft->body);
                } else {
                    $recipient = (string) ($conversation->recipient_external_id ?? '');
                    if ($recipient === '') {
                        $this->markFailed($draft, 'DM reply needs a recipient id and this conversation has none.');
                        $failed++;
                        continue;
                    }
                    $client->replyToConversation($blogId, $conversation->provider, $conversation->external_id, $recipient, $draft->body);
                }

                $draft->update([
                    'status' => InboxReplyDraft::STATUS_SENT,
                    'sent_at' => now(),
                    'last_error' => null,
                ]);
                // Metricool flips the thread PENDING → READ on a successful
                // reply; mirror that locally so the queue drains immediately
                // instead of waiting for the next ingest.
                $conversation->update([
                    'status' => InboxConversation::STATUS_READ,
                    'our_last_reply_at' => now(),
                    'last_message_from_us' => true,
                ]);

                $sent++;
                $this->info(sprintf('  draft#%d sent (%s %s)', $draft->id, $conversation->provider, $conversation->conversation_type));
            } catch (\Throwable $e) {
                $this->markFailed($draft, substr($e->getMessage(), 0, 400));
                $failed++;
                $this->warn(sprintf('  draft#%d failed: %s', $draft->id, substr($e->getMessage(), 0, 120)));
            }
        }

        $this->line('');
        $this->line("sent={$sent} expired={$expired} failed={$failed}");

        return self::SUCCESS;
    }

    private function markFailed(InboxReplyDraft $draft, string $error): void
    {
        $draft->update(['status' => InboxReplyDraft::STATUS_FAILED, 'last_error' => $error]);
        Log::warning('community:send failed', ['draft_id' => $draft->id, 'error' => $error]);
    }
}

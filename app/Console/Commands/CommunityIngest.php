<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\InboxConversation;
use App\Models\InboxReplyDraft;
use App\Services\Inbox\InboxNormalizer;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Metricool's inbox into `inbox_conversations` — the read half of the L4
 * growth actuator.
 *
 * MUST RUN HOURLY AT WORST. Reply windows are 24h for comments and 7d for DMs;
 * a daily job would routinely surface conversations that are already dead, and a
 * weekly one (the cadence the rest of the intel stack uses) would be purely
 * decorative. Prod at first ingest had a paying client with 19 comments ALL
 * pending, oldest 2025-11-15 — every one of them long past its window.
 *
 * Read-only against the platform: this command never sends anything. Drafting is
 * community:draft; sending needs a human approval and community:send.
 */
class CommunityIngest extends Command
{
    protected $signature = 'community:ingest
                            {--brand= : Restrict to a single brand id}
                            {--providers= : Comma-separated provider override (default: verified providers)}
                            {--all-providers : Also try providers that are in the enum but unproven on prod}';

    protected $description = 'Mirror Metricool inbox conversations and post-comments into inbox_conversations.';

    public function handle(): int
    {
        $providers = $this->resolveProviders();

        $brands = Brand::query()
            ->whereNull('archived_at')
            ->whereNotNull('metricool_blog_id')
            ->when($this->option('brand'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($brands->isEmpty()) {
            $this->info('No brands with a Metricool blog id.');

            return self::SUCCESS;
        }

        $totals = ['seen' => 0, 'new' => 0, 'updated' => 0, 'awaiting' => 0, 'expired' => 0, 'skipped' => 0];

        $client = $this->client();
        if (! $client) {
            return self::SUCCESS;
        }

        foreach ($brands as $brand) {

            $blogId = (int) $brand->metricool_blog_id;
            $this->line(sprintf('-> brand#%d %s (blogId %d)', $brand->id, $brand->name, $blogId));

            foreach ($providers as $provider) {
                foreach ([
                    InboxConversation::TYPE_DM => fn () => $client->inboxConversations($blogId, $provider),
                    InboxConversation::TYPE_COMMENT => fn () => $client->inboxPostComments($blogId, $provider),
                ] as $type => $fetch) {
                    try {
                        $rows = $fetch();
                    } catch (\Throwable $e) {
                        // A provider the brand hasn't connected answers 500
                        // "provider invalid". That is an expected absence, not an
                        // outage — record it quietly and carry on.
                        $this->line(sprintf('   %s/%s unavailable: %s', $provider, $type, substr($e->getMessage(), 0, 70)));
                        continue;
                    }

                    foreach ($rows as $row) {
                        $totals['seen']++;
                        $this->upsert($brand, $type, $row, $totals);
                    }
                }
            }
        }

        $this->expireStaleDrafts($totals);

        $this->line('');
        $this->line('--- summary ---');
        foreach ($totals as $k => $v) {
            $this->line(sprintf('  %-9s %d', $k, $v));
        }

        if ($totals['awaiting'] > 0) {
            $this->warn(sprintf('%d conversation(s) awaiting a reply inside their window.', $totals['awaiting']));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,int>    $totals
     */
    private function upsert(Brand $brand, string $type, array $row, array &$totals): void
    {
        $data = InboxNormalizer::normalize($row, $type);
        if ($data === null) {
            return;
        }

        try {
            $existing = InboxConversation::where('brand_id', $brand->id)
                ->where('conversation_type', $type)
                ->where('external_id', $data['external_id'])
                ->first();

            if ($existing) {
                $existing->fill($data)->save();
                $totals['updated']++;
                $conversation = $existing;
            } else {
                $conversation = InboxConversation::create($data + [
                    'brand_id' => $brand->id,
                    'workspace_id' => $brand->workspace_id,
                    'first_seen_at' => now(),
                ]);
                $totals['new']++;
            }

            if ($conversation->windowIsOpen()) {
                if ($conversation->status === InboxConversation::STATUS_PENDING && ! $conversation->last_message_from_us) {
                    $totals['awaiting']++;
                }
            } else {
                $totals['expired']++;
            }
        } catch (\Throwable $e) {
            Log::warning('community:ingest upsert failed', [
                'brand_id' => $brand->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mark drafts whose conversation window has closed. Without this they sit in
     * the approval queue forever looking actionable, and an operator eventually
     * approves one that can never be delivered.
     *
     * @param  array<string,int>  $totals
     */
    private function expireStaleDrafts(array &$totals): void
    {
        $expired = InboxReplyDraft::query()
            ->where('status', InboxReplyDraft::STATUS_PENDING_APPROVAL)
            ->whereHas('conversation', fn ($q) => $q->windowExpired())
            ->update([
                'status' => InboxReplyDraft::STATUS_EXPIRED,
                'last_error' => 'Reply window closed before approval.',
            ]);

        if ($expired > 0) {
            $totals['expired'] += $expired;
            $this->warn("{$expired} pending draft(s) expired — their reply window closed before a human approved them.");
        }
    }

    /** @return array<int,string> */
    private function resolveProviders(): array
    {
        $override = trim((string) $this->option('providers'));
        if ($override !== '') {
            return array_values(array_filter(array_map(
                fn ($p) => strtoupper(trim($p)),
                explode(',', $override),
            )));
        }

        return $this->option('all-providers')
            ? MetricoolClient::INBOX_PROVIDERS
            : MetricoolClient::INBOX_PROVIDERS_VERIFIED;
    }

    /**
     * One shared agency token covers every brand — brands are blogIds, not
     * separate credentials (the opposite of the Blotato model). So there is no
     * per-brand client to build; this just resolves the singleton safely.
     */
    private function client(): ?MetricoolClient
    {
        try {
            // fromConfig() is nullable: it returns null when the integration is
            // dormant (no token / unresolved handle), which is a normal state,
            // not an error.
            $client = MetricoolClient::fromConfig();
        } catch (\Throwable $e) {
            $this->warn("   Metricool client unavailable: {$e->getMessage()}");

            return null;
        }

        if (! $client) {
            $this->warn('   Metricool integration is dormant (no resolved token) — skipping.');
        }

        return $client;
    }
}

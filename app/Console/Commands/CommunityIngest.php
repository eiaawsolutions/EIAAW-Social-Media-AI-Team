<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\InboxConversation;
use App\Models\InboxReplyDraft;
use App\Services\Inbox\InboxCapabilityMap;
use App\Services\Inbox\InboxNormalizer;
use App\Services\Inbox\InboxReadiness;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors Metricool's inbox into `inbox_conversations` — the read half of the L4
 * growth actuator.
 *
 * MUST RUN HOURLY AT WORST. Reply windows are 24h for comments and 7d for DMs;
 * a daily job would routinely surface conversations that are already dead.
 *
 * DRIVEN BY A CAPABILITY MATRIX, NOT A PROVIDER LIST
 * --------------------------------------------------
 * The first version looped every provider against every endpoint and treated a
 * 500 as "this brand hasn't connected that network". That was wrong twice over:
 *
 *   - It made 7 guaranteed-failing calls per brand per run, so the log was
 *     mostly failure noise and a REAL failure was invisible in it.
 *   - It dropped a network from BOTH resources when it failed on one. LinkedIn,
 *     TikTok and YouTube all serve post-comments; none serves DMs. All three
 *     were therefore never ingested at all, on a brand that has active
 *     connections for each — a coverage miss, not merely waste.
 *
 * 500 "provider invalid" means "this (provider, resource) pair has no handler".
 * See InboxCapabilityMap for the measured matrix and the evidence.
 *
 * Read-only against the platform: this command never sends anything.
 */
class CommunityIngest extends Command
{
    protected $signature = 'community:ingest
                            {--brand= : Restrict to a single brand id}
                            {--capability= : Restrict to one of dm|comment|review}
                            {--skip-preflight : Do not check permissions (faster, but hides scope gaps)}';

    protected $description = 'Mirror Metricool inbox conversations, post-comments and reviews into inbox_conversations.';

    /** @var array<string,int> */
    private array $totals = [
        'seen' => 0, 'new' => 0, 'updated' => 0, 'awaiting' => 0,
        'expired' => 0, 'alias_dupes' => 0, 'blocked' => 0, 'failed' => 0,
    ];

    /** @var array<int,string> operator-facing permission problems found this run */
    private array $permissionIssues = [];

    /** @var array<int,array<int,string>> networksData per blogId, fetched once */
    private array $networksCache = [];

    /** @var array<int,array<string,mixed>>|null /admin/profiles-auth, fetched once */
    private ?array $profilesAuthCache = null;

    public function handle(): int
    {
        $client = $this->client();
        if (! $client) {
            return self::SUCCESS;
        }

        $capabilities = $this->resolveCapabilities();
        if ($capabilities === []) {
            $this->error('Unknown --capability. Use one of: '.implode(', ', InboxCapabilityMap::capabilities()));

            return self::FAILURE;
        }

        $brands = Brand::query()
            ->whereNull('archived_at')
            ->whereNotNull('metricool_blog_id')
            ->when($this->option('brand'), fn ($q, $id) => $q->where('id', $id))
            ->get();

        if ($brands->isEmpty()) {
            $this->info('No brands with a Metricool blog id.');

            return self::SUCCESS;
        }

        foreach ($brands as $brand) {
            $this->ingestBrand($client, $brand, $capabilities);
        }

        $this->expireStaleDrafts();
        $this->renderSummary();

        // A run that could not talk to Metricool must NOT look green. Reply
        // windows keep expiring while a revoked token or an outage goes unseen.
        return $this->totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<int,string> $capabilities */
    private function ingestBrand(MetricoolClient $client, Brand $brand, array $capabilities): void
    {
        $blogId = (int) $brand->metricool_blog_id;
        $this->line(sprintf('-> brand#%d %s (blogId %d)', $brand->id, $brand->name, $blogId));

        $issues = [];

        foreach ($capabilities as $capability) {
            $resource = InboxCapabilityMap::resourceFor($capability);
            if ($resource === null) {
                continue;
            }

            // Each group is an alias set — two views of ONE connection. Try in
            // order and STOP at the first that answers, or the same thread lands
            // twice under different ids and the person gets two replies.
            foreach (InboxCapabilityMap::fetchPlan($capability) as $group) {
                $this->fetchGroup($client, $brand, $blogId, $capability, $resource, $group, $issues);
            }
        }

        if ($issues !== []) {
            $brand->forceFill(['inbox_permissions' => $issues])->save();
            $this->totals['blocked'] += count($issues);
            foreach ($issues as $key => $issue) {
                $detail = $issue['error'] ?? ('missing scopes: '.implode(', ', $issue['missing_scopes'] ?? []));
                $this->permissionIssues[] = sprintf('brand#%d %s — %s', $brand->id, $key, $detail);
            }
        } elseif (! $this->option('skip-preflight') && $brand->inbox_permissions) {
            // Resolved since last run — clear it so a stale banner doesn't nag.
            $brand->forceFill(['inbox_permissions' => null])->save();
        }
    }

    /**
     * Try each provider in an alias group until one answers, then stop.
     *
     * @param  array<int,string>          $group
     * @param  array<string,mixed>        $issues
     */
    private function fetchGroup(
        MetricoolClient $client,
        Brand $brand,
        int $blogId,
        string $capability,
        string $resource,
        array $group,
        array &$issues,
    ): void {
        foreach ($group as $provider) {
            $this->preflight($client, $blogId, $resource, $provider, $issues);

            try {
                $rows = $client->inboxFetch($blogId, $resource, $provider);
            } catch (\Throwable $e) {
                $this->classifyFetchError($e, $brand, $resource, $provider);

                continue; // try the next alias
            }

            foreach ($rows as $row) {
                $this->totals['seen']++;
                $this->upsert($brand, $capability, $provider, $row);
            }

            return; // this alias answered — the rest are the same connection
        }
    }

    /**
     * An error here is one of three very different things and they must not be
     * conflated — conflating them is what made the original log useless.
     */
    private function classifyFetchError(\Throwable $e, Brand $brand, string $resource, string $provider): void
    {
        if (MetricoolClient::isUnsupportedInboxPair($e)) {
            // The matrix said this pair IS supported, yet the API disagrees:
            // Metricool changed its implementation scope under us. Loud, because
            // silently skipping would hide a capability we should be using.
            $this->warn(sprintf('   MATRIX DRIFT: %s/%s is mapped as supported but returned "provider invalid"', $resource, $provider));
            Log::warning('community:ingest capability matrix drift', [
                'brand_id' => $brand->id, 'resource' => $resource, 'provider' => $provider,
            ]);

            return;
        }

        $this->totals['failed']++;
        $this->warn(sprintf('   %s/%s FAILED: %s', $resource, $provider, substr($e->getMessage(), 0, 110)));
        Log::error('community:ingest fetch failed', [
            'brand_id' => $brand->id, 'resource' => $resource, 'provider' => $provider,
            'error' => substr($e->getMessage(), 0, 500),
        ]);
    }

    /**
     * Record any permission problem for this (resource, provider).
     *
     * This is the ONLY signal that distinguishes "no engagement" from "we are
     * locked out": a scope-blocked pair returns 200 with an empty list.
     *
     * @param  array<string,mixed>  $issues
     */
    private function preflight(MetricoolClient $client, int $blogId, string $resource, string $provider, array &$issues): void
    {
        if ($this->option('skip-preflight') || $resource === 'reviews') {
            return; // reviews expose no authorizations endpoint
        }

        $key = "{$resource}:{$provider}";

        try {
            $auth = $client->inboxAuthorizations($blogId, $provider, $resource);
        } catch (\Throwable $e) {
            // e.g. 400 "Cant found a page to verify permissions" — a real
            // operator problem (the account isn't resolvable for this blogId).
            $issues[$key] = ['error' => substr($e->getMessage(), 0, 200), 'checked_at' => now()->toIso8601String()];

            return;
        }

        $missing = array_values(array_filter((array) ($auth['missingScopes'] ?? []), 'is_string'));
        $allowMessages = $auth['allowAccessToMessages'] ?? null;

        if ($missing === [] && $allowMessages !== false) {
            return; // healthy — store nothing
        }

        // A missingScopes list on its own cannot name a remedy: the SAME payload
        // is returned for "never connected", for "connected but the account type
        // can't carry the capability", and for "connected but under-consented".
        // Those need three different actions from a human, so classify before
        // telling anyone anything.
        $readiness = InboxReadiness::classify(
            $provider,
            $this->networksFor($client, $blogId),
            $this->profileAuthFor($client, $blogId),
            $missing,
            is_bool($allowMessages) ? $allowMessages : null,
        );

        $issues[$key] = array_filter([
            'state' => $readiness['state'],
            'remedy' => $readiness['remedy'],
            'missing_scopes' => $missing,
            'allow_messages' => $allowMessages === false ? false : null,
            'checked_at' => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');

        $this->warn(sprintf('   %s: %s', strtoupper($readiness['state']), $readiness['remedy']));
    }

    /**
     * Connection facts, fetched once per run and reused. Both endpoints are
     * account-wide or per-brand static, so re-fetching per provider would
     * multiply calls for no new information.
     *
     * @return array<int,string>
     */
    private function networksFor(MetricoolClient $client, int $blogId): array
    {
        if (! array_key_exists($blogId, $this->networksCache)) {
            try {
                $this->networksCache[$blogId] = $client->brandNetworks($blogId);
            } catch (\Throwable) {
                $this->networksCache[$blogId] = [];
            }
        }

        return $this->networksCache[$blogId];
    }

    /** @return array<string,mixed> */
    private function profileAuthFor(MetricoolClient $client, int $blogId): array
    {
        if ($this->profilesAuthCache === null) {
            try {
                $this->profilesAuthCache = $client->profilesAuth();
            } catch (\Throwable) {
                $this->profilesAuthCache = [];
            }
        }

        return $this->profilesAuthCache[$blogId] ?? [];
    }

    /** @param array<string,mixed> $row */
    private function upsert(Brand $brand, string $capability, string $provider, array $row): void
    {
        $data = InboxNormalizer::normalize($row, $capability, $provider);
        if ($data === null) {
            return;
        }

        try {
            $existing = InboxConversation::where('brand_id', $brand->id)
                ->where('conversation_type', $capability)
                ->where('external_id', $data['external_id'])
                ->first();

            if (! $existing && $this->looksLikeAliasDuplicate($brand, $capability, $data)) {
                // Belt-and-braces behind the alias-group fix: if two provider
                // views ever leak through, do not create a second row for a
                // conversation we already track under a different id.
                $this->totals['alias_dupes']++;
                Log::warning('community:ingest suspected alias duplicate skipped', [
                    'brand_id' => $brand->id, 'type' => $capability, 'external_id' => $data['external_id'],
                ]);

                return;
            }

            if ($existing) {
                $this->fillWithoutClobberingOurReply($existing, $data)->save();
                $this->totals['updated']++;
                $conversation = $existing;
            } else {
                $conversation = InboxConversation::create($data + [
                    'brand_id' => $brand->id,
                    'workspace_id' => $brand->workspace_id,
                    'first_seen_at' => now(),
                ]);
                $this->totals['new']++;
            }

            if (! $conversation->windowIsOpen()) {
                $this->totals['expired']++;
            } elseif ($conversation->status === InboxConversation::STATUS_PENDING && ! $conversation->last_message_from_us) {
                $this->totals['awaiting']++;
            }
        } catch (\Throwable $e) {
            $this->totals['failed']++;
            Log::warning('community:ingest upsert failed', [
                'brand_id' => $brand->id, 'type' => $capability, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A conversation we already track, seen under a different external_id.
     *
     * Matches on the tuple that identifies a real-world thread regardless of
     * which provider view produced it: same brand, same type, same person, same
     * last-message timestamp.
     *
     * @param  array<string,mixed>  $data
     */
    private function looksLikeAliasDuplicate(Brand $brand, string $capability, array $data): bool
    {
        if (empty($data['participant_name']) || empty($data['last_message_at'])) {
            return false; // not enough identity to make the call — don't guess
        }

        return InboxConversation::where('brand_id', $brand->id)
            ->where('conversation_type', $capability)
            ->where('participant_name', $data['participant_name'])
            ->where('last_message_at', $data['last_message_at'])
            ->where('external_id', '!=', $data['external_id'])
            ->exists();
    }

    /**
     * Apply the fetched row WITHOUT overwriting the fact that we already
     * replied.
     *
     * Metricool's view can lag our own send: an unconditional fill would reset
     * status to PENDING and last_message_from_us to false on a thread we
     * answered minutes ago, putting it straight back into the awaiting-reply
     * queue and re-inflating the sidebar badge for the rest of the window.
     *
     * @param  array<string,mixed>  $data
     */
    private function fillWithoutClobberingOurReply(InboxConversation $existing, array $data): InboxConversation
    {
        $ourReply = $existing->our_last_reply_at;
        $incomingAt = $data['last_message_at'] ?? null;

        if ($ourReply && (! $incomingAt || $ourReply->greaterThanOrEqualTo($incomingAt))) {
            unset($data['status'], $data['last_message_from_us']);
        }

        return $existing->fill($data);
    }

    /**
     * Mark drafts whose conversation window has closed. Without this they sit in
     * the approval queue looking actionable, and an operator eventually approves
     * one that can never be delivered.
     */
    private function expireStaleDrafts(): void
    {
        $expired = InboxReplyDraft::query()
            ->where('status', InboxReplyDraft::STATUS_PENDING_APPROVAL)
            ->whereHas('conversation', fn ($q) => $q->windowExpired())
            ->update([
                'status' => InboxReplyDraft::STATUS_EXPIRED,
                'last_error' => 'Reply window closed before approval.',
            ]);

        if ($expired > 0) {
            $this->totals['expired'] += $expired;
            $this->warn("{$expired} pending draft(s) expired — their reply window closed before a human approved them.");
        }
    }

    private function renderSummary(): void
    {
        $this->line('');
        $this->line('--- summary ---');
        foreach ($this->totals as $k => $v) {
            $this->line(sprintf('  %-12s %d', $k, $v));
        }

        if ($this->permissionIssues !== []) {
            $this->line('');
            $this->warn('Permission problems (these look like an EMPTY inbox, but are a lockout):');
            foreach ($this->permissionIssues as $issue) {
                $this->line("  - {$issue}");
            }
        }

        if ($this->totals['awaiting'] > 0) {
            $this->warn(sprintf('%d conversation(s) awaiting a reply inside their window.', $this->totals['awaiting']));
        }
    }

    /** @return array<int,string> */
    private function resolveCapabilities(): array
    {
        $only = trim((string) $this->option('capability'));
        if ($only === '') {
            return InboxCapabilityMap::capabilities();
        }

        return in_array($only, InboxCapabilityMap::capabilities(), true) ? [$only] : [];
    }

    /**
     * One shared agency token covers every brand — brands are blogIds, not
     * separate credentials (the opposite of the Blotato model).
     */
    private function client(): ?MetricoolClient
    {
        try {
            $client = MetricoolClient::fromConfig();
        } catch (\Throwable $e) {
            $this->warn("Metricool client unavailable: {$e->getMessage()}");

            return null;
        }

        if (! $client) {
            $this->warn('Metricool integration is dormant (no resolved token) — skipping.');
        }

        return $client;
    }
}

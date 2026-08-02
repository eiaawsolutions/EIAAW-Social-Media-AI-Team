<?php

namespace App\Services\Inbox;

use App\Models\InboxConversation;

/**
 * Which Metricool inbox capability each network actually supports.
 *
 * WHY THIS EXISTS
 * ---------------
 * The first cut of the ingest called every provider against every endpoint and
 * treated an error as "this brand hasn't connected that network". That was
 * wrong, and the Platforms page disproved it: brand#1 has ACTIVE LinkedIn,
 * TikTok and YouTube connections, yet all three returned
 * 500 {"detail":"provider invalid"} on /v2/inbox/conversations.
 *
 * Probing every (provider, capability) pair on live prod (2026-08-02) showed the
 * real meaning: **"provider invalid" = this network does not support this inbox
 * CAPABILITY**. It is a static product-surface fact, not a per-tenant one. The
 * measured matrix reproduces Metricool's own help-centre matrix exactly:
 *
 *   provider            conversations(DM)  post-comments  reviews
 *   INSTAGRAM           200 (data)         200 (data)     500
 *   INSTAGRAMBUSINESS   200 (data)         200 (data)     500
 *   FACEBOOK            200                200            500
 *   TWITTER             200                500            500
 *   LINKEDIN            500                200            500
 *   TIKTOKBUSINESS      500                200            500
 *   YOUTUBE             500                200            500
 *   GMB                 500                500            200
 *
 * Encoding it here means the hourly ingest stops making 7 guaranteed-failing
 * calls per brand per run, and — more importantly — a genuine error stops being
 * indistinguishable from an unsupported combination.
 *
 * MISSING SCOPES ARE A DIFFERENT THING ENTIRELY. A supported pair with
 * un-granted permissions returns 200 with an EMPTY list while
 * /authorizations reports e.g. {"missingScopes":["comment.list",
 * "comment.list.manage"]}. That is silent data loss dressed as "no activity",
 * so it must be surfaced to an operator rather than read as an empty inbox.
 *
 * Pure — no I/O.
 */
final class InboxCapabilityMap
{
    public const CAP_DM = InboxConversation::TYPE_DM;
    public const CAP_COMMENT = InboxConversation::TYPE_COMMENT;
    public const CAP_REVIEW = 'review';

    /** The API resource path segment each capability lives at. */
    public const RESOURCE = [
        self::CAP_DM => 'conversations',
        self::CAP_COMMENT => 'post-comments',
        self::CAP_REVIEW => 'reviews',
    ];

    /**
     * Measured support matrix. Provider => capabilities it serves.
     *
     * @var array<string,array<int,string>>
     */
    private const SUPPORT = [
        'INSTAGRAM' => [self::CAP_DM, self::CAP_COMMENT],
        'INSTAGRAMBUSINESS' => [self::CAP_DM, self::CAP_COMMENT],
        'FACEBOOK' => [self::CAP_DM, self::CAP_COMMENT],
        'TWITTER' => [self::CAP_DM],
        'LINKEDIN' => [self::CAP_COMMENT],
        'TIKTOKBUSINESS' => [self::CAP_COMMENT],
        'YOUTUBE' => [self::CAP_COMMENT],
        'GMB' => [self::CAP_REVIEW],
    ];

    /**
     * Providers that are two VIEWS OF ONE CONNECTION, not two connections.
     *
     * INSTAGRAM and INSTAGRAMBUSINESS return the SAME Instagram thread under
     * DIFFERENT conversation ids — verified on prod: identical `self`, identical
     * participants, identical message ids, but ids
     * `aWdfZAG06MTpJR01lc3NhZ...` (ig_dm:1:IGMessageThread:...) vs
     * `aWdfZAG06MzQwMjgyMzY2...` (ig_dm:34028236...), and even divergent status
     * (READ vs PENDING).
     *
     * Ingesting both therefore created TWO rows for ONE real conversation, which
     * would have produced two drafts and, if both were approved, TWO messages to
     * the same person. Each group is tried IN ORDER and the first provider that
     * answers wins — brand#1 resolves on INSTAGRAM, while brand#14 (whose
     * INSTAGRAM view 400s with "Cant found a page to verify permissions")
     * resolves on INSTAGRAMBUSINESS, so neither can be hardcoded.
     *
     * @var array<int,array<int,string>>
     */
    private const ALIAS_GROUPS = [
        ['INSTAGRAM', 'INSTAGRAMBUSINESS'],
    ];

    /** Does this network serve this capability at all? */
    public static function supports(string $provider, string $capability): bool
    {
        return in_array($capability, self::SUPPORT[strtoupper(trim($provider))] ?? [], true);
    }

    /** @return array<int,string> every capability this provider serves */
    public static function capabilitiesFor(string $provider): array
    {
        return self::SUPPORT[strtoupper(trim($provider))] ?? [];
    }

    /** @return array<int,string> every provider that serves this capability */
    public static function providersFor(string $capability): array
    {
        $out = [];
        foreach (self::SUPPORT as $provider => $caps) {
            if (in_array($capability, $caps, true)) {
                $out[] = $provider;
            }
        }

        return $out;
    }

    /**
     * The fetch plan for one capability: a list of try-in-order groups. A group
     * with more than one entry is an alias set — attempt each until one answers,
     * then STOP, because the rest are the same connection seen twice.
     *
     * @return array<int,array<int,string>>
     */
    public static function fetchPlan(string $capability): array
    {
        $providers = self::providersFor($capability);
        $grouped = [];
        $claimed = [];

        foreach (self::ALIAS_GROUPS as $group) {
            $members = array_values(array_filter($group, fn ($p) => in_array($p, $providers, true)));
            if ($members !== []) {
                $grouped[] = $members;
                foreach ($members as $m) {
                    $claimed[$m] = true;
                }
            }
        }

        foreach ($providers as $provider) {
            if (! isset($claimed[$provider])) {
                $grouped[] = [$provider];
            }
        }

        return $grouped;
    }

    /** The API path segment for a capability, or null if unknown. */
    public static function resourceFor(string $capability): ?string
    {
        return self::RESOURCE[$capability] ?? null;
    }

    /** @return array<int,string> every capability we know how to fetch */
    public static function capabilities(): array
    {
        return array_keys(self::RESOURCE);
    }
}

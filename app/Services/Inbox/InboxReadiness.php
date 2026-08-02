<?php

namespace App\Services\Inbox;

/**
 * Why a brand's inbox is empty — and therefore what a human must actually DO.
 *
 * WHY THIS EXISTS
 * ---------------
 * `GET /v2/inbox/{resource}/authorizations` returns a `missingScopes` list, and
 * it is tempting to read that as "grant these permissions". That instruction is
 * WRONG for both live cases we have, in two different ways:
 *
 *   TikTok — a controlled experiment across three brands on ONE token, ONE
 *   Metricool account and ONE OAuth app (2026-08-02):
 *       EIAAW    tiktokAccountType=PERSONAL  missing=[comment.list,…]  comments=0
 *       BearHug  tiktokAccountType=BUSINESS  missing=[]                comments=5
 *       Orblly   tiktokAccountType=PERSONAL  missing=[comment.list,…]  comments=0
 *   Perfect correlation. TikTok does not OFFER comment scopes to a personal or
 *   creator account, so re-consenting changes nothing — it just mints another
 *   personal connection with the identical gap. The remedy is an account-type
 *   switch inside the TikTok app, and only then a reconnect.
 *
 *   Instagram — Orblly reported the same shape of `missingScopes`, but
 *   `networksData` is `[tiktokData]` and `instagram` is NULL: there is NO
 *   Instagram connection at all. The authorizations endpoint returns an
 *   indistinguishable payload for "never connected" and "connected but
 *   under-scoped". Telling the operator to "grant permissions" on an account
 *   that was never connected sends them looking for a screen that does not
 *   exist.
 *
 * So the missingScopes list alone cannot name a remedy. This classifier
 * cross-references it with the connection facts (`/admin/profiles-auth` +
 * `networksData`) to produce a state an operator can act on.
 *
 * Pure — callers fetch, this decides.
 */
final class InboxReadiness
{
    /** The network isn't connected to this brand at all. */
    public const NOT_CONNECTED = 'not_connected';

    /** Connected, but the account TYPE cannot carry the capability. */
    public const WRONG_ACCOUNT_TYPE = 'wrong_account_type';

    /** Connected and the right type, but consent didn't include the scopes. */
    public const MISSING_SCOPES = 'missing_scopes';

    /** Nothing to fix. */
    public const READY = 'ready';

    /**
     * Networks whose inbox capability requires a specific account type, and the
     * `/admin/profiles-auth` field that reports it.
     */
    private const ACCOUNT_TYPE_FIELD = [
        'TIKTOKBUSINESS' => ['field' => 'tiktokAccountType', 'required' => 'BUSINESS'],
    ];

    /** networksData key that proves a provider is connected at all. */
    private const NETWORK_DATA_KEY = [
        'INSTAGRAM' => 'instagramData',
        'INSTAGRAMBUSINESS' => 'instagramData',
        'FACEBOOK' => 'facebookData',
        'TWITTER' => 'twitterData',
        'LINKEDIN' => 'linkedinData',
        'TIKTOKBUSINESS' => 'tiktokData',
        'YOUTUBE' => 'youtubeData',
        'GMB' => 'gmbData',
    ];

    /**
     * @param  string                $provider       e.g. TIKTOKBUSINESS
     * @param  array<int,string>     $networksData   keys from /v2/settings/brands/{blogId}
     * @param  array<string,mixed>   $profileAuth    the brand's /admin/profiles-auth row
     * @param  array<int,string>     $missingScopes  from the inbox authorizations endpoint
     * @param  bool|null             $allowMessages  allowAccessToMessages, when reported
     * @return array{state:string, remedy:string}
     */
    public static function classify(
        string $provider,
        array $networksData,
        array $profileAuth,
        array $missingScopes,
        ?bool $allowMessages = null,
    ): array {
        $provider = strtoupper(trim($provider));

        // 1. Connected at all? This has to come first — an absent connection
        //    produces a missingScopes list identical to an under-scoped one.
        $key = self::NETWORK_DATA_KEY[$provider] ?? null;
        if ($key !== null && ! in_array($key, $networksData, true)) {
            return [
                'state' => self::NOT_CONNECTED,
                'remedy' => sprintf(
                    'No %s account is connected to this brand. Connect one in Metricool → Connections; there are no permissions to grant until then.',
                    self::label($provider),
                ),
            ];
        }

        // 2. Right account type? Re-consent cannot fix a type problem, so this
        //    outranks the scope check.
        $rule = self::ACCOUNT_TYPE_FIELD[$provider] ?? null;
        if ($rule !== null) {
            $actual = strtoupper(trim((string) ($profileAuth[$rule['field']] ?? '')));
            if ($actual !== '' && $actual !== $rule['required']) {
                return [
                    'state' => self::WRONG_ACCOUNT_TYPE,
                    'remedy' => sprintf(
                        'This %s account is a %s account. %s only offers comment permissions to %s accounts, so reconnecting will NOT help. Switch it in the %s app (Settings and privacy → Account → Switch to Business Account), THEN reconnect it in Metricool.',
                        self::label($provider), strtolower($actual),
                        self::label($provider), strtolower($rule['required']), self::label($provider),
                    ),
                ];
            }
        }

        // 3. Connected, right type, but consent was incomplete.
        if ($missingScopes !== [] || $allowMessages === false) {
            return [
                'state' => self::MISSING_SCOPES,
                'remedy' => sprintf(
                    'Reconnect the %s account in Metricool → Connections and accept ALL permissions on the consent screen (missing: %s). Deselecting any permission reproduces this.',
                    self::label($provider),
                    $missingScopes === [] ? 'message access' : implode(', ', $missingScopes),
                ),
            ];
        }

        return ['state' => self::READY, 'remedy' => ''];
    }

    /** True when the state needs a human. */
    public static function needsAction(string $state): bool
    {
        return $state !== self::READY;
    }

    private static function label(string $provider): string
    {
        return match ($provider) {
            'TIKTOKBUSINESS' => 'TikTok',
            'INSTAGRAM', 'INSTAGRAMBUSINESS' => 'Instagram',
            'GMB' => 'Google Business Profile',
            'TWITTER' => 'X',
            default => ucfirst(strtolower($provider)),
        };
    }
}

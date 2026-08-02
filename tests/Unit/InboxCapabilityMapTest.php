<?php

namespace Tests\Unit;

use App\Models\InboxConversation;
use App\Services\Inbox\InboxCapabilityMap;
use App\Services\Metricool\MetricoolClient;
use Tests\TestCase;

/**
 * Locks the measured Metricool inbox capability matrix.
 *
 * Every assertion here corresponds to a real HTTP response observed against
 * live prod on 2026-08-02 across two brands. The matrix cannot be discovered at
 * runtime — the OpenAPI spec declares `provider` as an unconstrained string on
 * every inbox input and carries one identical 8-value enum on the OUTPUT models
 * of all three resources — so it is hard-coded, and these tests are what keep
 * the hard-coding honest.
 */
class InboxCapabilityMapTest extends TestCase
{
    /**
     * The observed matrix. true = HTTP 200 and routable,
     * false = HTTP 500 {"detail":"provider invalid"}.
     */
    private const OBSERVED = [
        //                     dm     comment  review
        'INSTAGRAM' => [true, true, false],
        'INSTAGRAMBUSINESS' => [true, true, false],
        'FACEBOOK' => [true, true, false],
        'TWITTER' => [true, false, false],
        'LINKEDIN' => [false, true, false],
        'TIKTOKBUSINESS' => [false, true, false],
        'YOUTUBE' => [false, true, false],
        'GMB' => [false, false, true],
    ];

    public function test_matrix_matches_every_observed_probe_result(): void
    {
        $caps = [InboxCapabilityMap::CAP_DM, InboxCapabilityMap::CAP_COMMENT, InboxCapabilityMap::CAP_REVIEW];

        foreach (self::OBSERVED as $provider => $expected) {
            foreach ($caps as $i => $cap) {
                $this->assertSame(
                    $expected[$i],
                    InboxCapabilityMap::supports($provider, $cap),
                    "matrix disagrees with the live probe for {$provider}/{$cap}",
                );
            }
        }
    }

    public function test_the_asymmetry_that_disproves_not_connected(): void
    {
        // The decisive observation: TWITTER answers 200 on conversations and
        // 500 on post-comments for the SAME account. No property of a
        // connection can vary by resource, so "provider invalid" cannot mean
        // "not connected" — it is a per-(provider, resource) fact.
        $this->assertTrue(InboxCapabilityMap::supports('TWITTER', InboxCapabilityMap::CAP_DM));
        $this->assertFalse(InboxCapabilityMap::supports('TWITTER', InboxCapabilityMap::CAP_COMMENT));
    }

    public function test_comment_capable_networks_that_were_previously_dropped(): void
    {
        // The original bug: these three failed on DMs, and the one-dimensional
        // provider list dropped them from BOTH resources — so their comments
        // were never ingested at all despite active connections.
        foreach (['LINKEDIN', 'TIKTOKBUSINESS', 'YOUTUBE'] as $provider) {
            $this->assertTrue(InboxCapabilityMap::supports($provider, InboxCapabilityMap::CAP_COMMENT));
            $this->assertFalse(InboxCapabilityMap::supports($provider, InboxCapabilityMap::CAP_DM));
        }
    }

    public function test_every_provider_the_api_accepts_is_in_the_matrix(): void
    {
        // A provider in the accepted-token list with no capabilities mapped
        // would be silently unreachable.
        foreach (MetricoolClient::INBOX_PROVIDERS as $provider) {
            $this->assertNotEmpty(
                InboxCapabilityMap::capabilitiesFor($provider),
                "{$provider} is accepted by the API but serves no capability in the map",
            );
        }
    }

    public function test_fetch_plan_collapses_the_instagram_aliases_into_one_group(): void
    {
        // The double-send fix. INSTAGRAM and INSTAGRAMBUSINESS return the SAME
        // thread under different ids; the plan must present them as ONE
        // try-in-order group so the caller stops after the first that answers.
        $plan = InboxCapabilityMap::fetchPlan(InboxCapabilityMap::CAP_DM);

        $aliasGroups = array_values(array_filter($plan, fn ($g) => count($g) > 1));
        $this->assertCount(1, $aliasGroups, 'exactly one alias group expected');
        $this->assertSame(['INSTAGRAM', 'INSTAGRAMBUSINESS'], $aliasGroups[0]);
    }

    public function test_fetch_plan_lists_every_supported_provider_exactly_once(): void
    {
        foreach (InboxCapabilityMap::capabilities() as $cap) {
            $flat = array_merge(...InboxCapabilityMap::fetchPlan($cap));
            sort($flat);

            $expected = InboxCapabilityMap::providersFor($cap);
            sort($expected);

            $this->assertSame($expected, $flat, "fetch plan for {$cap} lost or duplicated a provider");
        }
    }

    public function test_review_plan_is_gmb_only(): void
    {
        $this->assertSame([['GMB']], InboxCapabilityMap::fetchPlan(InboxCapabilityMap::CAP_REVIEW));
    }

    public function test_capabilities_map_to_real_api_resources(): void
    {
        $this->assertSame('conversations', InboxCapabilityMap::resourceFor(InboxCapabilityMap::CAP_DM));
        $this->assertSame('post-comments', InboxCapabilityMap::resourceFor(InboxCapabilityMap::CAP_COMMENT));
        $this->assertSame('reviews', InboxCapabilityMap::resourceFor(InboxCapabilityMap::CAP_REVIEW));
        $this->assertNull(InboxCapabilityMap::resourceFor('nonsense'));
    }

    public function test_capability_keys_line_up_with_the_conversation_types(): void
    {
        // conversation_type is persisted from the capability key, so a drift
        // here would write rows nothing can query.
        $this->assertSame(InboxConversation::TYPE_DM, InboxCapabilityMap::CAP_DM);
        $this->assertSame(InboxConversation::TYPE_COMMENT, InboxCapabilityMap::CAP_COMMENT);
        $this->assertSame(InboxConversation::TYPE_REVIEW, InboxCapabilityMap::CAP_REVIEW);
    }

    public function test_reviews_have_no_reply_deadline(): void
    {
        // Google reviews carry no deadline. A fabricated window would expire
        // replies the platform would still accept.
        $this->assertArrayNotHasKey(InboxConversation::TYPE_REVIEW, InboxConversation::WINDOW_HOURS);
        $this->assertNull(InboxConversation::windowExpiryFor(InboxConversation::TYPE_REVIEW, now()));
    }

    public function test_unsupported_pair_signature_is_recognised(): void
    {
        $unsupported = new \RuntimeException('Metricool inbox conversations (LINKEDIN) failed: HTTP 500 — {"detail":"provider invalid"}');
        $realFailure = new \RuntimeException('Metricool inbox conversations (INSTAGRAM) failed: HTTP 401 — Unauthorized');

        $this->assertTrue(MetricoolClient::isUnsupportedInboxPair($unsupported));
        $this->assertFalse(
            MetricoolClient::isUnsupportedInboxPair($realFailure),
            'a 401 must NEVER be classified as an expected unsupported pair — that is how a revoked token stays invisible',
        );
    }

    public function test_unknown_provider_supports_nothing(): void
    {
        $this->assertSame([], InboxCapabilityMap::capabilitiesFor('MASTODON'));
        $this->assertFalse(InboxCapabilityMap::supports('MASTODON', InboxCapabilityMap::CAP_DM));
    }
}

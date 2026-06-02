<?php

namespace Tests\Unit;

use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Services\Blotato\PlatformRules;
use Tests\TestCase;

/**
 * The Facebook `pageId` required-override gate must be PROVIDER-AWARE.
 *
 * Blotato genuinely requires target_overrides.pageId on every Facebook post
 * (HTTP 400 without it). But Metricool's ScheduledPostFacebookData has NO
 * `pageId` field — it routes to the Page by the connected profile, and sending
 * a pageId is rejected HTTP 400 "Unrecognized field 'pageId'". Enforcing the
 * Blotato gate under Metricool created a deadlock (no pageId → gate blocks;
 * pageId → scheduler rejects). So the gate fires ONLY under blotato.
 *
 * Pure unit test — no DB. In-memory Draft + PlatformConnection.
 */
class PlatformRulesFacebookPageIdProviderTest extends TestCase
{
    private function facebookDraftAndConnNoPageId(): array
    {
        $draft = new Draft();
        $draft->platform = 'facebook';
        $draft->body = 'A short, valid Facebook caption.';
        $draft->hashtags = [];
        $draft->mentions = [];
        // Facebook permits text-only, so no asset_url needed to pass media gate.

        $conn = new PlatformConnection();
        $conn->id = 99;
        $conn->target_overrides = []; // no pageId

        return [$draft, $conn];
    }

    public function test_facebook_requires_page_id_under_blotato(): void
    {
        config()->set('services.publishing.provider', 'blotato');
        [$draft, $conn] = $this->facebookDraftAndConnNoPageId();

        $result = PlatformRules::evaluate($draft, $conn);

        $this->assertFalse($result['passed'], 'Under Blotato, a Facebook post with no pageId must be blocked.');
        $kinds = array_column($result['violations'], 'kind');
        $this->assertContains('missing_facebook_page_id', $kinds);
    }

    public function test_facebook_does_not_require_page_id_under_metricool(): void
    {
        config()->set('services.publishing.provider', 'metricool');
        [$draft, $conn] = $this->facebookDraftAndConnNoPageId();

        $result = PlatformRules::evaluate($draft, $conn);

        $this->assertTrue($result['passed'], 'Under Metricool, the Facebook pageId gate must NOT fire (Metricool has no pageId field).');
        $kinds = array_column($result['violations'], 'kind');
        $this->assertNotContains('missing_facebook_page_id', $kinds);
    }

    public function test_metricool_is_the_default_when_provider_unset(): void
    {
        // Empty/null provider config defaults to metricool — the gate must not fire.
        config()->set('services.publishing.provider', null);
        [$draft, $conn] = $this->facebookDraftAndConnNoPageId();

        $result = PlatformRules::evaluate($draft, $conn);

        $kinds = array_column($result['violations'], 'kind');
        $this->assertNotContains('missing_facebook_page_id', $kinds);
    }
}

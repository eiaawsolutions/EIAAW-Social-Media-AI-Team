<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Blotato\PlatformRules;
use Tests\TestCase;

/**
 * HQ CTA links: every EIAAW HQ post (eiaaw_internal plan) gets the configured
 * call-to-action links appended to its caption — the full labelled URLs on
 * link-friendly platforms, a "links in bio" line on platforms that don't
 * linkify caption URLs. Client brands and non-HQ workspaces get nothing.
 * DB-free: unsaved Brand/Workspace/Draft with relations set in memory.
 */
class HqCtaBlockTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.hq_cta', [
            'enabled' => true,
            'links' => [
                ['label' => 'Chat with our AI', 'url' => 'https://eiaawsolutions.com/#chat'],
                ['label' => 'Talk to us', 'url' => 'https://eiaawsolutions.com/#contact'],
                ['label' => 'Talk to our AI agent', 'url' => 'https://eiaawsolutions.com/#agent'],
            ],
            'link_friendly_platforms' => ['x', 'twitter', 'linkedin', 'facebook', 'threads'],
            'bio_line' => 'Links in bio — chat, contact us, or talk to our AI agent.',
        ]);
    }

    private function draft(string $platform, string $plan): Draft
    {
        $ws = new Workspace(['plan' => $plan]);
        $brand = new Brand(['name' => 'EIAAW SOLUTIONS']);
        $brand->setRelation('workspace', $ws);
        $draft = new Draft(['platform' => $platform, 'body' => 'x']);
        $draft->setRelation('brand', $brand);

        return $draft;
    }

    public function test_link_friendly_platform_gets_all_three_urls(): void
    {
        $block = PlatformRules::hqCtaBlock($this->draft('linkedin', 'eiaaw_internal'));

        $this->assertStringContainsString('https://eiaawsolutions.com/#chat', $block);
        $this->assertStringContainsString('https://eiaawsolutions.com/#contact', $block);
        $this->assertStringContainsString('https://eiaawsolutions.com/#agent', $block);
    }

    public function test_non_link_friendly_platform_gets_bio_line_not_raw_urls(): void
    {
        $block = PlatformRules::hqCtaBlock($this->draft('instagram', 'eiaaw_internal'));

        $this->assertStringContainsString('Links in bio', $block);
        $this->assertStringNotContainsString('https://eiaawsolutions.com/#chat', $block);
    }

    public function test_tiktok_and_youtube_also_use_bio_line(): void
    {
        foreach (['tiktok', 'youtube', 'pinterest'] as $p) {
            $block = PlatformRules::hqCtaBlock($this->draft($p, 'eiaaw_internal'));
            $this->assertStringContainsString('Links in bio', $block, "{$p} should use bio line");
            $this->assertStringNotContainsString('https://', $block, "{$p} should not contain raw URLs");
        }
    }

    public function test_client_brand_gets_no_cta(): void
    {
        $this->assertSame('', PlatformRules::hqCtaBlock($this->draft('linkedin', 'pro')));
        $this->assertSame('', PlatformRules::hqCtaBlock($this->draft('instagram', 'starter')));
    }

    public function test_disabled_config_yields_no_cta_even_for_hq(): void
    {
        config()->set('services.hq_cta.enabled', false);
        $this->assertSame('', PlatformRules::hqCtaBlock($this->draft('linkedin', 'eiaaw_internal')));
    }

    public function test_x_is_link_friendly(): void
    {
        $block = PlatformRules::hqCtaBlock($this->draft('x', 'eiaaw_internal'));
        $this->assertStringContainsString('https://eiaawsolutions.com/#chat', $block);
    }

    public function test_evaluate_counts_the_cta_against_the_caption_cap(): void
    {
        // Single-source guarantee: Compliance's evaluate() assembles the caption
        // including the CTA, so a body that fits alone but overflows once the CTA
        // is appended is correctly flagged (no pass-then-fail-at-publish drift).
        $draft = $this->draft('threads', 'eiaaw_internal'); // cap 500
        // 480-char body alone is fine; + ~3 CTA URL lines (~90 chars) > 500.
        $draft->body = str_repeat('a', 480);

        $res = PlatformRules::evaluate($draft);
        $this->assertFalse($res['passed']);
        $this->assertContains('caption_too_long', array_column($res['violations'], 'kind'));
    }

    public function test_evaluate_clean_for_client_brand_at_same_length(): void
    {
        // The same 480-char body on a CLIENT brand has no CTA appended → fits.
        $draft = $this->draft('threads', 'pro');
        $draft->body = str_repeat('a', 480);

        $this->assertTrue(PlatformRules::evaluate($draft)['passed']);
    }
}

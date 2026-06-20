<?php

namespace Tests\Unit;

use App\Agents\Prompts\WriterPrompt;
use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Blotato\PlatformRules;
use Tests\TestCase;

/**
 * CTA-reservation follow-up: when the brand is HQ, the Writer/Repurpose body cap
 * must reserve room for the appended CTA block so a full-length body isn't
 * …-truncated at publish to fit the links. The reserved amount is the CTA block
 * length + its "\n\n" separator; non-HQ brands reserve nothing (effective cap ==
 * platform cap, byte-identical to before). DB-free (in-memory brand/workspace).
 */
class WriterCtaReservationTest extends TestCase
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

    private function brand(string $plan): Brand
    {
        $ws = new Workspace(['plan' => $plan]);
        $b = new Brand(['name' => 'EIAAW SOLUTIONS']);
        $b->setRelation('workspace', $ws);

        return $b;
    }

    public function test_hq_reserves_chars_on_link_friendly_platform(): void
    {
        $reserved = PlatformRules::hqCtaReservedChars($this->brand('eiaaw_internal'), 'threads');
        // 3 labelled URLs + 2 newlines between them + the "\n\n" separator > 0,
        // and substantial (the URLs are ~60-70 chars each with labels).
        $this->assertGreaterThan(100, $reserved);
    }

    public function test_hq_reserves_fewer_chars_on_bio_line_platform(): void
    {
        $linkFriendly = PlatformRules::hqCtaReservedChars($this->brand('eiaaw_internal'), 'linkedin');
        $bioLine = PlatformRules::hqCtaReservedChars($this->brand('eiaaw_internal'), 'instagram');
        // The bio-line block is shorter than 3 full URLs.
        $this->assertGreaterThan(0, $bioLine);
        $this->assertLessThan($linkFriendly, $bioLine);
    }

    public function test_client_brand_reserves_nothing(): void
    {
        $this->assertSame(0, PlatformRules::hqCtaReservedChars($this->brand('pro'), 'linkedin'));
        $this->assertSame(0, PlatformRules::hqCtaReservedChars($this->brand('starter'), 'instagram'));
    }

    public function test_effective_body_limit_reduces_for_hq_only(): void
    {
        $hq = $this->brand('eiaaw_internal');
        $client = $this->brand('pro');

        $threadsCap = WriterPrompt::PLATFORM_LIMITS['threads']; // 500

        // HQ: reduced by the reserved CTA chars.
        $hqLimit = WriterPrompt::effectiveBodyLimit('threads', $hq);
        $this->assertLessThan($threadsCap, $hqLimit);
        $this->assertSame($threadsCap - PlatformRules::hqCtaReservedChars($hq, 'threads'), $hqLimit);

        // Client: unchanged (byte-identical to the old behaviour).
        $this->assertSame($threadsCap, WriterPrompt::effectiveBodyLimit('threads', $client));
        // Null brand (no brand context): unchanged.
        $this->assertSame($threadsCap, WriterPrompt::effectiveBodyLimit('threads', null));
    }

    public function test_effective_limit_never_collapses_below_a_floor(): void
    {
        // Even if a platform cap were tiny, the body limit stays usable (>= floor).
        $hq = $this->brand('eiaaw_internal');
        $this->assertGreaterThanOrEqual(50, WriterPrompt::effectiveBodyLimit('x', $hq)); // x cap 280
    }

    public function test_invariant_body_plus_cta_fits_platform_cap(): void
    {
        // The whole point: a max-length HQ body + the reserved CTA must not
        // exceed the platform cap, so nothing gets truncated at publish.
        $hq = $this->brand('eiaaw_internal');
        foreach (['threads', 'linkedin', 'facebook', 'instagram', 'tiktok'] as $platform) {
            $cap = WriterPrompt::PLATFORM_LIMITS[$platform];
            $bodyLimit = WriterPrompt::effectiveBodyLimit($platform, $hq);
            $reserved = PlatformRules::hqCtaReservedChars($hq, $platform);
            $this->assertLessThanOrEqual(
                $cap,
                $bodyLimit + $reserved,
                "{$platform}: body limit ({$bodyLimit}) + CTA ({$reserved}) must fit cap ({$cap})"
            );
        }
    }
}

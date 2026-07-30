<?php

namespace Tests\Unit;

use App\Services\Branding\BrandImageStamper;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The second defect behind the blank posts.
 *
 * buildFilterChain() emitted `letter_spacing=2` on the "POWERED BY EIAAW
 * SOLUTIONS" drawtext. drawtext only gained that option in FFmpeg 7.x;
 * production runs 5.1 (Debian 12), where naming an unknown option does not
 * degrade gracefully — it aborts filtergraph initialisation:
 *
 *   [Parsed_drawtext_9] Option 'letter_spacing' not found
 *   [AVFilterGraph] Error initializing filter 'drawtext' with args '...'
 *
 * So EVERY quote-card stamp threw, and DesignerAgent's soft-fallback quietly
 * published the raw FAL still instead. On a photo that is a cosmetic loss; on a
 * poster background — which is deliberately blank — it was the second half of
 * how six empty posts reached six live accounts.
 *
 * These tests pin the filtergraph to options the DEPLOYED FFmpeg actually
 * supports, so the next such option cannot be introduced silently.
 */
class BrandImageStamperFilterOptionsTest extends TestCase
{
    /**
     * drawtext options verified present in FFmpeg 5.1 (the production build).
     * Anything outside this set must be verified against the deployed binary
     * before it is added — `ffmpeg -h filter=drawtext`.
     */
    private const SUPPORTED_DRAWTEXT_OPTIONS = [
        'fontfile', 'text', 'textfile', 'fontcolor', 'fontsize',
        'x', 'y', 'line_spacing', 'box', 'boxcolor', 'boxborderw',
        'borderw', 'bordercolor', 'shadowcolor', 'shadowx', 'shadowy',
        'alpha', 'expansion', 'fix_bounds', 'font', 'text_align',
    ];

    private function filterChain(string $aspect = 'portrait'): string
    {
        $layout = (new ReflectionClass(BrandImageStamper::class))->getConstant('LAYOUTS')[$aspect];

        return (new ReflectionMethod(BrandImageStamper::class, 'buildFilterChain'))->invoke(
            new BrandImageStamper('ffmpeg'),
            $layout,
            '/tmp/logo.png',
            '/tmp/font.ttf',
            '/tmp/quote.txt',
            '/tmp/tag.txt',
        );
    }

    public function test_filter_chain_does_not_use_letter_spacing(): void
    {
        foreach (['square', 'portrait', 'landscape'] as $aspect) {
            $this->assertStringNotContainsString(
                'letter_spacing',
                $this->filterChain($aspect),
                "letter_spacing is FFmpeg 7+; production runs 5.1 and the whole {$aspect} filtergraph fails to initialise.",
            );
        }
    }

    public function test_every_drawtext_option_is_supported_by_the_deployed_ffmpeg(): void
    {
        foreach (['square', 'portrait', 'landscape'] as $aspect) {
            $chain = $this->filterChain($aspect);

            preg_match_all('/drawtext=([^\[;]+)/', $chain, $matches);
            $this->assertNotEmpty($matches[1], 'Expected at least one drawtext block.');

            foreach ($matches[1] as $args) {
                // Split on ':' that separates options, then take the key half.
                foreach (preg_split('/:(?=[a-z_]+=)/', $args) as $pair) {
                    $key = trim(explode('=', $pair, 2)[0]);
                    if ($key === '') {
                        continue;
                    }
                    $this->assertContains(
                        $key,
                        self::SUPPORTED_DRAWTEXT_OPTIONS,
                        "drawtext option '{$key}' ({$aspect}) is not in the verified-supported set for FFmpeg 5.1. ".
                        'Confirm it with `ffmpeg -h filter=drawtext` on the deployed image before using it.',
                    );
                }
            }
        }
    }

    // ── The replacement for letter_spacing ───────────────────────────────

    public function test_tracked_tag_spaces_characters_without_exotic_glyphs(): void
    {
        $this->assertSame('E I A A W', BrandImageStamper::trackedTag('EIAAW'));

        // Word gaps stay visibly wider than letter gaps once every character
        // is separated, so the tag still reads as words.
        $this->assertSame(
            'P O W E R E D   B Y   E I A A W   S O L U T I O N S',
            BrandImageStamper::trackedTag('POWERED BY EIAAW SOLUTIONS'),
        );
    }

    public function test_tracked_tag_uses_only_ascii_spaces(): void
    {
        // A thin/hair space would letterspace more elegantly but renders as tofu
        // in any font that lacks the glyph — unacceptable on a brand asset.
        $tag = BrandImageStamper::trackedTag('POWERED BY EIAAW SOLUTIONS');

        $this->assertSame(1, preg_match('/^[\x20-\x7E]+$/', $tag), 'Tag must stay pure ASCII.');
    }

    public function test_tracked_tag_tolerates_messy_input(): void
    {
        $this->assertSame('A   B', BrandImageStamper::trackedTag('  A   B  '));
        $this->assertSame('', BrandImageStamper::trackedTag('   '));
    }
}

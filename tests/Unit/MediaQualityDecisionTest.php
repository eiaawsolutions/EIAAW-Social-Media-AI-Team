<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use App\Jobs\RedraftFailedDraft;
use App\Services\Imagery\RenderedMediaInspector;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Compliance check 9 (media_quality) — the policy half.
 *
 * Locks the decisions that let six blank posts go live (scheduled_posts
 * #541-#546, 2026-07-29/30): an image the model was told to leave empty, whose
 * text was never composited onto it, sailing through a gate that only ever
 * asked "is an asset_url present?".
 *
 * DB-free: decideMediaQuality() is pure by design, for exactly this reason.
 */
class MediaQualityDecisionTest extends TestCase
{
    private const BLANK_CONTRACT = [
        'route' => 'poster',
        'text_free_background' => true,
        'composed' => false,
    ];

    private const COMPOSED_CONTRACT = [
        'route' => 'poster',
        'text_free_background' => true,
        'composed' => true,
    ];

    private const PHOTO_CONTRACT = [
        'route' => 'photo',
        'text_free_background' => false,
        'composed' => false,
    ];

    /** @return array{ok:bool,verdict:string,reason:string,metrics:array} */
    private function goodPixels(): array
    {
        return [
            'ok' => true,
            'verdict' => RenderedMediaInspector::VERDICT_OK,
            'reason' => 'Image carries real content.',
            'metrics' => ['edge_density' => 0.12],
        ];
    }

    // ── Layer 1: the render contract ─────────────────────────────────────

    public function test_uncomposed_text_free_background_is_blocked_on_the_contract_alone(): void
    {
        // The exact shape of the six live blanks. No pixels are consulted.
        $d = ComplianceAgent::decideMediaQuality(self::BLANK_CONTRACT, null, false);

        $this->assertSame('fail', $d['result']);
        $this->assertSame('render_contract', $d['details']['layer']);
        $this->assertStringContainsString('empty designed background', $d['reason']);
    }

    public function test_contract_blank_blocks_even_when_the_pixels_look_fine(): void
    {
        // Defence in depth runs the RIGHT way round: a proven-blank contract is
        // authoritative and must not be overridden by a passing heuristic.
        $d = ComplianceAgent::decideMediaQuality(self::BLANK_CONTRACT, $this->goodPixels(), false);

        $this->assertSame('fail', $d['result']);
        $this->assertSame('render_contract', $d['details']['layer']);
    }

    public function test_composed_poster_passes(): void
    {
        $d = ComplianceAgent::decideMediaQuality(self::COMPOSED_CONTRACT, $this->goodPixels(), false);

        $this->assertSame('pass', $d['result']);
        $this->assertSame('pixels', $d['details']['layer']);
    }

    public function test_photo_route_is_not_treated_as_a_blank_background(): void
    {
        // composed=false is NORMAL on the photo path — the still is the artwork.
        // Reading composed=false alone as "blank" would fail every photo post.
        $this->assertFalse(ComplianceAgent::contractProvesBlank(self::PHOTO_CONTRACT));

        $d = ComplianceAgent::decideMediaQuality(self::PHOTO_CONTRACT, $this->goodPixels(), false);
        $this->assertSame('pass', $d['result']);
    }

    public function test_missing_contract_falls_through_to_pixels(): void
    {
        // Every asset generated before the contract existed has none. Those must
        // still be vetted, not waved through.
        $this->assertFalse(ComplianceAgent::contractProvesBlank(null));

        $d = ComplianceAgent::decideMediaQuality(null, $this->goodPixels(), false);
        $this->assertSame('pass', $d['result']);
        $this->assertSame('pixels', $d['details']['layer']);
    }

    // ── Layer 2: pixel forensics ─────────────────────────────────────────

    public function test_low_detail_pixels_block(): void
    {
        $d = ComplianceAgent::decideMediaQuality(null, [
            'ok' => false,
            'verdict' => RenderedMediaInspector::VERDICT_LOW_DETAIL,
            'reason' => 'The image has no legible content.',
            'metrics' => ['edge_density' => 0.004],
        ], false);

        $this->assertSame('fail', $d['result']);
        $this->assertStringContainsString('Regenerate', $d['reason']);
    }

    public function test_blank_pixels_block(): void
    {
        $d = ComplianceAgent::decideMediaQuality(null, [
            'ok' => false,
            'verdict' => RenderedMediaInspector::VERDICT_BLANK,
            'reason' => 'The image is effectively blank.',
            'metrics' => ['ink_coverage' => 0.001],
        ], false);

        $this->assertSame('fail', $d['result']);
    }

    // ── Fetch failures: block dead URLs, tolerate flaky ones ─────────────

    public function test_http_error_on_the_asset_url_blocks(): void
    {
        // A URL we get a 404 from is a URL the platform gets a 404 from. That is
        // a real publish-breaking defect, not a transient blip.
        $d = ComplianceAgent::decideMediaQuality(null, [
            'ok' => false,
            'verdict' => RenderedMediaInspector::VERDICT_UNREADABLE,
            'reason' => 'The image URL returned HTTP 404 — the media is not retrievable.',
            'metrics' => [],
        ], false);

        $this->assertSame('fail', $d['result']);
        $this->assertTrue($d['details']['dead_url']);
    }

    public function test_transient_fetch_failure_warns_but_does_not_block(): void
    {
        // Fail-open on OUR flakiness, same posture as dedup without embeddings:
        // a CDN hiccup must never freeze the whole publishing pipeline.
        $d = ComplianceAgent::decideMediaQuality(null, [
            'ok' => false,
            'verdict' => RenderedMediaInspector::VERDICT_UNREADABLE,
            'reason' => 'The image could not be downloaded for inspection.',
            'metrics' => [],
        ], false);

        $this->assertSame('warning', $d['result']);
        $this->assertFalse($d['details']['dead_url']);
    }

    public function test_undecodable_file_blocks(): void
    {
        $d = ComplianceAgent::decideMediaQuality(null, [
            'ok' => false,
            'verdict' => RenderedMediaInspector::VERDICT_UNREADABLE,
            'reason' => 'The file is not a decodable image (corrupt or not an image).',
            'metrics' => [],
        ], false);

        $this->assertSame('fail', $d['result']);
    }

    // ── Video ────────────────────────────────────────────────────────────

    public function test_video_is_not_pixel_vetted(): void
    {
        $d = ComplianceAgent::decideMediaQuality(null, null, true);

        $this->assertSame('pass', $d['result']);
        $this->assertSame('video', $d['details']['asset']);
    }

    public function test_video_detection_ignores_query_strings(): void
    {
        $this->assertTrue(ComplianceAgent::looksLikeVideo('https://cdn.example.com/a/b_video.mp4?sig=abc&x=1'));
        $this->assertTrue(ComplianceAgent::looksLikeVideo('https://cdn.example.com/clip.MOV'));
        $this->assertFalse(ComplianceAgent::looksLikeVideo('https://cdn.example.com/poster.png?mp4=no'));
    }

    public function test_video_contract_blank_still_blocks(): void
    {
        // Skipping pixel work for video must not skip the contract layer.
        $d = ComplianceAgent::decideMediaQuality(self::BLANK_CONTRACT, null, true);

        $this->assertSame('fail', $d['result']);
    }

    // ── Auto-recovery routing ────────────────────────────────────────────

    private function route(array $failures): string
    {
        return (new ReflectionMethod(RedraftFailedDraft::class, 'routeFailures'))
            ->invoke(new RedraftFailedDraft(1), $failures);
    }

    public function test_media_quality_failure_routes_to_the_designer_not_the_writer(): void
    {
        // Rewording a caption cannot fix a blank image. Routing this to Writer
        // would burn an LLM call, leave the empty canvas attached, fail again,
        // and spend the draft's whole revision budget going nowhere.
        $this->assertSame('regenerate_media', $this->route([
            ['check_type' => 'media_quality', 'reason' => 'blank', 'details' => []],
        ]));
    }

    public function test_media_quality_alongside_a_text_failure_still_rewrites_first(): void
    {
        // Existing precedence is preserved: Writer fixes what it can on this
        // tick, and the next compliance pass re-flags the media.
        $this->assertSame('rewrite', $this->route([
            ['check_type' => 'media_quality', 'reason' => 'blank', 'details' => []],
            ['check_type' => 'brand_voice', 'reason' => 'off voice', 'details' => []],
        ]));
    }

    public function test_missing_media_routing_is_unchanged(): void
    {
        $this->assertSame('regenerate_media', $this->route([
            ['check_type' => 'platform_publishability', 'reason' => 'no media', 'details' => ['kinds' => ['media_required']]],
        ]));
    }
}

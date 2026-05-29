<?php

namespace Tests\Unit;

use App\Services\Imagery\FalAiClient;
use Tests\TestCase;

/**
 * Guards the Veo 3 Fast payload-normalisation introduced when the default video
 * model switched from Wan to Google Veo 3 Fast. The traps Veo's schema sets:
 *   - `duration` is a STRING enum "4s"/"6s"/"8s" (Wan took an int) — an int 5
 *     or a stray "5s" must snap to a valid step, not 422.
 *   - aspect is 16:9 / 9:16 ONLY — Veo rejects 1:1, so a square draft must fall
 *     through to vertical.
 *   - model-family detection drives all of the above, so versioned ids must
 *     route correctly.
 */
class FalVideoVeoRoutingTest extends TestCase
{
    public function test_veo_model_family_is_detected_across_endpoint_variants(): void
    {
        $this->assertTrue(FalAiClient::isVeoModel('fal-ai/veo3/fast'));
        $this->assertTrue(FalAiClient::isVeoModel('fal-ai/veo3/fast/image-to-video'));
        $this->assertTrue(FalAiClient::isVeoModel('fal-ai/veo3'));
        // A future Veo id still routes as Veo.
        $this->assertTrue(FalAiClient::isVeoModel('fal-ai/veo4/turbo'));

        // Wan + null are NOT Veo (so the Wan rollback path keeps int duration +
        // negative_prompt + 1:1).
        $this->assertFalse(FalAiClient::isVeoModel('fal-ai/wan-25-preview/text-to-video'));
        $this->assertFalse(FalAiClient::isVeoModel(null));
    }

    public function test_duration_snaps_to_nearest_allowed_veo_step(): void
    {
        // Exact steps pass through.
        $this->assertSame('4s', FalAiClient::veoDurationString(4));
        $this->assertSame('6s', FalAiClient::veoDurationString(6));
        $this->assertSame('8s', FalAiClient::veoDurationString(8));

        // 5 is equidistant from 4 and 6 — round UP so we never under-deliver
        // the voiceover.
        $this->assertSame('6s', FalAiClient::veoDurationString(5));

        // 7 → 8 (closer), 3 → 4 (closer).
        $this->assertSame('8s', FalAiClient::veoDurationString(7));
        $this->assertSame('4s', FalAiClient::veoDurationString(3));

        // Out-of-range clamps to the nearest end.
        $this->assertSame('4s', FalAiClient::veoDurationString(1));
        $this->assertSame('8s', FalAiClient::veoDurationString(30));

        // String forms ("5s") and garbage normalise too.
        $this->assertSame('6s', FalAiClient::veoDurationString('5s'));
        $this->assertSame('8s', FalAiClient::veoDurationString('8s'));
        $this->assertSame('6s', FalAiClient::veoDurationString(0)); // 0 → default 6
    }

    public function test_aspect_is_clamped_to_veo_supported_set(): void
    {
        // Veo supports landscape + vertical only.
        $this->assertSame('16:9', FalAiClient::clampVeoAspect('16:9'));
        $this->assertSame('9:16', FalAiClient::clampVeoAspect('9:16'));

        // 1:1 (which Veo rejects) and anything unknown → vertical, the dominant
        // short-form surface — never an invalid value that would 422.
        $this->assertSame('9:16', FalAiClient::clampVeoAspect('1:1'));
        $this->assertSame('9:16', FalAiClient::clampVeoAspect('4:5'));
        $this->assertSame('9:16', FalAiClient::clampVeoAspect('auto'));
        $this->assertSame('9:16', FalAiClient::clampVeoAspect(''));
    }
}

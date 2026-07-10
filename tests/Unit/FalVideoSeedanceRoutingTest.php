<?php

namespace Tests\Unit;

use App\Services\Imagery\FalAiClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Guards the Seedance 2.0 payload-normalisation added when the default video
 * model switched from Google Veo 3 Fast to ByteDance Seedance 2.0 (Fast).
 *
 * Seedance shares Veo's native-audio + no-negative_prompt shape, but differs in
 * two schema traps this test pins:
 *   - `duration` is a STRINGIFIED integer "4".."15" — NOT Veo's "Ns" enum and
 *     NOT a bare int. A "15s" or int 15 must serialise as "15", never "15s".
 *   - it renders up to 15s in ONE call (no extend endpoint), so a 15s request
 *     must make exactly one HTTP round-trip.
 * The capability helpers (isSeedanceModel / videoModelHasNativeAudio /
 * videoModelSupportsExtend / maxSingleClipSeconds) drive all of the above and
 * keep the Veo + Wan rollback paths intact.
 */
class FalVideoSeedanceRoutingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FalAiClient::clearLockout();
    }

    protected function tearDown(): void
    {
        FalAiClient::clearLockout();
        parent::tearDown();
    }

    // ── model-family classification ─────────────────────────────────────────

    public function test_seedance_family_is_detected_across_tiers_and_endpoints(): void
    {
        $this->assertTrue(FalAiClient::isSeedanceModel('bytedance/seedance-2.0/fast/text-to-video'));
        $this->assertTrue(FalAiClient::isSeedanceModel('bytedance/seedance-2.0/fast/image-to-video'));
        $this->assertTrue(FalAiClient::isSeedanceModel('bytedance/seedance-2.0/text-to-video'));
        // A future Seedance id still routes as Seedance.
        $this->assertTrue(FalAiClient::isSeedanceModel('bytedance/seedance-3.0/pro/text-to-video'));

        // Veo, Wan and null are NOT Seedance.
        $this->assertFalse(FalAiClient::isSeedanceModel('fal-ai/veo3/fast'));
        $this->assertFalse(FalAiClient::isSeedanceModel('fal-ai/wan-25-preview/text-to-video'));
        $this->assertFalse(FalAiClient::isSeedanceModel(null));
    }

    public function test_native_audio_families_are_veo_and_seedance(): void
    {
        $this->assertTrue(FalAiClient::videoModelHasNativeAudio('fal-ai/veo3/fast'));
        $this->assertTrue(FalAiClient::videoModelHasNativeAudio('bytedance/seedance-2.0/fast/text-to-video'));

        // Wan / null have no native audio (they use the FFmpeg composer path).
        $this->assertFalse(FalAiClient::videoModelHasNativeAudio('fal-ai/wan-25-preview/text-to-video'));
        $this->assertFalse(FalAiClient::videoModelHasNativeAudio(null));
    }

    public function test_only_veo_supports_the_extend_endpoint(): void
    {
        $this->assertTrue(FalAiClient::videoModelSupportsExtend('fal-ai/veo3/fast'));
        $this->assertTrue(FalAiClient::videoModelSupportsExtend('fal-ai/veo3.1/extend-video'));

        // Seedance renders 15s in one call — it never chains extends.
        $this->assertFalse(FalAiClient::videoModelSupportsExtend('bytedance/seedance-2.0/fast/text-to-video'));
        $this->assertFalse(FalAiClient::videoModelSupportsExtend('fal-ai/wan-25-preview/text-to-video'));
    }

    public function test_max_single_clip_seconds_per_family(): void
    {
        // Seedance one-shots up to 15s; Veo (and the Wan/other fallthrough) cap a
        // single call at 8s.
        $this->assertSame(15, FalAiClient::maxSingleClipSeconds('bytedance/seedance-2.0/fast/text-to-video'));
        $this->assertSame(8, FalAiClient::maxSingleClipSeconds('fal-ai/veo3/fast'));
        $this->assertSame(8, FalAiClient::maxSingleClipSeconds('fal-ai/wan-25-preview/text-to-video'));
        $this->assertSame(8, FalAiClient::maxSingleClipSeconds(null));
    }

    // ── duration formatting (stringified int, never "Ns") ───────────────────

    public function test_seedance_duration_is_a_bare_integer_string_clamped_to_range(): void
    {
        // Exact values pass through as bare integer strings.
        $this->assertSame('4', FalAiClient::seedanceDurationString(4));
        $this->assertSame('6', FalAiClient::seedanceDurationString(6));
        $this->assertSame('15', FalAiClient::seedanceDurationString(15));

        // Out-of-range clamps to [4,15].
        $this->assertSame('15', FalAiClient::seedanceDurationString(20));
        $this->assertSame('4', FalAiClient::seedanceDurationString(2));

        // 0 / garbage → default 6.
        $this->assertSame('6', FalAiClient::seedanceDurationString(0));

        // A "Ns" string normalises to the bare int (the digits survive).
        $this->assertSame('12', FalAiClient::seedanceDurationString('12s'));
        $this->assertSame('8', FalAiClient::seedanceDurationString('8'));

        // The trap: NEVER a trailing "s" (that's Veo's format and would 422 here).
        foreach (['4', '6', '15', '8'] as $seconds) {
            $this->assertStringNotContainsString('s', FalAiClient::seedanceDurationString((int) $seconds));
        }
    }

    // ── payload shape sent to FAL (the real request the switch produces) ─────

    public function test_generate_video_sends_the_seedance_payload_shape_in_one_call(): void
    {
        Http::fake(['fal.run/*' => Http::response([
            'video' => ['url' => 'https://fal.media/files/clip.mp4', 'content_type' => 'video/mp4'],
            'seed' => 42,
        ], 200)]);

        $client = new FalAiClient(
            apiKey: 'fal_test_key',
            imageModel: 'fal-ai/nano-banana',
            videoModelText: 'bytedance/seedance-2.0/fast/text-to-video',
            videoModelImage: 'bytedance/seedance-2.0/fast/image-to-video',
            videoNativeAudio: true,
        );

        $result = $client->generateVideo('a brand reel', [
            'duration' => 15,
            'aspect_ratio' => '9:16',
            'resolution' => '720p',
            // Forwarded by VideoAgent for a Wan rollback — must be DROPPED here.
            'negative_prompt' => 'morphing face, flickering',
        ]);

        // Exactly ONE request — Seedance never chains an extend round-trip.
        Http::assertSentCount(1);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // Hits the text-to-video endpoint (no image_url given).
            $this->assertStringContainsString('bytedance/seedance-2.0/fast/text-to-video', $request->url());

            // duration is the STRINGIFIED int "15" — not "15s", not int 15.
            $this->assertSame('15', $body['duration'] ?? null);
            $this->assertIsString($body['duration']);
            $this->assertStringNotContainsString('s', (string) $body['duration']);

            // Native audio requested; aspect passed through unchanged (not clamped).
            $this->assertTrue($body['generate_audio'] ?? null);
            $this->assertSame('9:16', $body['aspect_ratio'] ?? null);

            // negative_prompt is dropped (Seedance has no such field).
            $this->assertArrayNotHasKey('negative_prompt', $body);

            return true;
        });

        // The clip is flagged as carrying native audio (skips the FFmpeg composer).
        $this->assertTrue($result['has_native_audio']);
        $this->assertSame('https://fal.media/files/clip.mp4', $result['url']);
        $this->assertSame('bytedance/seedance-2.0/fast/text-to-video', $result['model']);
    }

    public function test_native_audio_off_override_is_honoured_for_seedance(): void
    {
        Http::fake(['fal.run/*' => Http::response([
            'video' => ['url' => 'https://fal.media/files/silent.mp4'],
        ], 200)]);

        $client = new FalAiClient(
            apiKey: 'fal_test_key',
            imageModel: 'fal-ai/nano-banana',
            videoModelText: 'bytedance/seedance-2.0/fast/text-to-video',
            videoNativeAudio: true,
        );

        $result = $client->generateVideo('a silent brand reel', [
            'duration' => 10,
            'generate_audio' => false,
        ]);

        Http::assertSent(function ($request) {
            $body = $request->data();
            $this->assertFalse($body['generate_audio'] ?? null);
            $this->assertSame('10', $body['duration'] ?? null);

            return true;
        });

        $this->assertFalse($result['has_native_audio']);
    }
}

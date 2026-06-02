<?php

namespace Tests\Unit;

use App\Jobs\RegenerateStaleVideoPost;
use Tests\TestCase;

/**
 * DB-free guards for the queued stale-video regen job.
 *
 * The command's --queue flag dispatches this so a slow Veo i2v→t2v generation
 * runs in the worker (330s budget) instead of dying with an interactive ssh
 * session. These lock the two things that must hold regardless of DB:
 *   - urlIsVideo() — the gate that decides requeue (real .mp4) vs restore-still.
 *     It MUST agree with SubmitScheduledPost::draftNeedsVideoButHasImage so we
 *     never requeue a post the publish-time gate will just re-reject.
 *   - the queue contract (tries + timeout under the worker --timeout=330) so a
 *     long generation can't fatal the worker — see [[worker-timeout-contract]].
 */
class RegenerateStaleVideoPostTest extends TestCase
{
    public function test_url_is_video_recognises_video_extensions(): void
    {
        foreach (['https://v3b.fal.media/files/x/clip.mp4', 'https://x/y.MOV', 'https://x/z.webm', 'https://x/a.m4v'] as $url) {
            $this->assertTrue(RegenerateStaleVideoPost::urlIsVideo($url), "should be video: {$url}");
        }
    }

    public function test_url_is_video_rejects_image_extensions(): void
    {
        foreach (['https://x/y.jpg', 'https://x/y.jpeg', 'https://x/y.png', 'https://x/y.webp', 'https://x/y.gif'] as $url) {
            $this->assertFalse(RegenerateStaleVideoPost::urlIsVideo($url), "should NOT be video: {$url}");
        }
    }

    public function test_url_is_video_treats_empty_as_not_video(): void
    {
        // An empty asset_url is NOT a video — the job must restore the still,
        // not requeue a media-less post.
        $this->assertFalse(RegenerateStaleVideoPost::urlIsVideo(''));
    }

    public function test_queue_contract_timeout_stays_under_worker_cap(): void
    {
        $job = new RegenerateStaleVideoPost(1);

        // We manage our own (no) retries; the worker --timeout is 330, and
        // re-arming PHP's own limit inside a job fatals the worker, so the
        // catchable queue timeout must sit safely below 330.
        $this->assertSame(1, $job->tries);
        $this->assertLessThan(330, $job->timeout);
        $this->assertGreaterThanOrEqual(120, $job->timeout, 'needs real headroom for a Veo i2v+t2v generation');
    }
}

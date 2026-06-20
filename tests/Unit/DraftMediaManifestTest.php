<?php

namespace Tests\Unit;

use App\Jobs\SubmitScheduledPost;
use App\Models\Draft;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The publish manifest (SubmitScheduledPost::collectDraftMediaUrls) must send
 * exactly the draft's current primary asset_url — never the asset_urls history
 * ledger, and never an ephemeral /storage/branding/ URL.
 *
 * Root cause of the 2026-06-20 HQ publish failures: collectDraftMediaUrls dumped
 * the whole asset_urls history as a carousel, shipping stale .mp4s, duplicate
 * images, and a dead /storage/branding/ URL that 404'd on-platform ("Error
 * downloading the image"). DB-free: in-memory Draft + ReflectionMethod.
 */
class DraftMediaManifestTest extends TestCase
{
    private function collect(Draft $draft): array
    {
        $m = new ReflectionMethod(SubmitScheduledPost::class, 'collectDraftMediaUrls');
        $m->setAccessible(true);

        return $m->invoke(new SubmitScheduledPost(0), $draft);
    }

    private function isEphemeral(string $url): bool
    {
        $m = new ReflectionMethod(SubmitScheduledPost::class, 'isEphemeralMediaUrl');
        $m->setAccessible(true);

        return (bool) $m->invoke(new SubmitScheduledPost(0), $url);
    }

    public function test_returns_only_primary_asset_url_for_single_image_draft_with_polluted_history(): void
    {
        $primary = 'https://smt-assets.eiaawsolutions.com/branding/387-382782f3bb6a.jpg';

        $draft = new Draft();
        $draft->asset_url = $primary;
        // The real-world polluted history: an old fal.media URL, a stale .mp4
        // from a prior video attempt, a dead ephemeral image, plus the primary.
        $draft->asset_urls = [
            'https://v3b.fal.media/files/b/0a9dc045/old-still.jpg',
            'https://v3b.fal.media/files/b/0a9dc045/stale-video.mp4',
            'https://smt.eiaawsolutions.com/storage/branding/387-0e3ddd5587c1.jpg',
            $primary,
        ];

        $this->assertSame([$primary], $this->collect($draft));
    }

    public function test_never_returns_a_storage_branding_url(): void
    {
        $draft = new Draft();
        $draft->asset_url = 'https://smt-assets.eiaawsolutions.com/branding/388-d43d9bf13741.jpg';
        $draft->asset_urls = [
            'https://smt.eiaawsolutions.com/storage/branding/388-39df8a0d390e.jpg',
            'https://smt.eiaawsolutions.com/storage/branding/388-c80262c38704.jpg',
            'https://smt.eiaawsolutions.com/storage/branding/388-195b1fc19db0.jpg',
        ];

        foreach ($this->collect($draft) as $url) {
            $this->assertStringNotContainsString('/storage/branding/', $url);
        }
    }

    public function test_returns_empty_when_primary_is_ephemeral(): void
    {
        // Defense-in-depth: an ephemeral primary must NOT be sent — return []
        // so the publishability gate holds the post (it needs regeneration),
        // never a 404 on-platform.
        $draft = new Draft();
        $draft->asset_url = 'https://smt.eiaawsolutions.com/storage/branding/386-55b32a97630b.jpg';
        $draft->asset_urls = [];

        $this->assertSame([], $this->collect($draft));
    }

    public function test_returns_empty_when_no_asset(): void
    {
        $draft = new Draft();
        $draft->asset_url = null;
        $draft->asset_urls = null;

        $this->assertSame([], $this->collect($draft));
    }

    public function test_ephemeral_detector_distinguishes_durable_from_ephemeral(): void
    {
        $this->assertTrue($this->isEphemeral('https://smt.eiaawsolutions.com/storage/branding/x.jpg'));
        $this->assertFalse($this->isEphemeral('https://smt-assets.eiaawsolutions.com/branding/x.jpg'));
        $this->assertFalse($this->isEphemeral('https://v3b.fal.media/files/b/abc/x.jpg'));
    }
}

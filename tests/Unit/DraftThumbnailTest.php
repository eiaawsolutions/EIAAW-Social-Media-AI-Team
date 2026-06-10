<?php

namespace Tests\Unit;

use App\Models\Draft;
use Tests\TestCase;

/**
 * Locks the drafts-list thumbnail logic: the list column can't render a video
 * asset_url inside an <img>, so video drafts must thumbnail their keyframe (or
 * fall through to a placeholder) instead of showing an empty box.
 */
class DraftThumbnailTest extends TestCase
{
    private function draft(?string $assetUrl, array $assetUrls = []): Draft
    {
        $d = new Draft(['platform' => 'instagram']);
        $d->setAttribute('asset_url', $assetUrl);
        $d->setAttribute('asset_urls', $assetUrls);

        return $d;
    }

    public function test_urlIsVideo_classifies_by_extension(): void
    {
        $this->assertTrue(Draft::urlIsVideo('https://x.test/a.mp4'));
        $this->assertTrue(Draft::urlIsVideo('https://x.test/a.MOV'));
        $this->assertTrue(Draft::urlIsVideo('https://x.test/a.webm?sig=abc'));
        $this->assertFalse(Draft::urlIsVideo('https://x.test/a.png'));
        $this->assertFalse(Draft::urlIsVideo('https://x.test/a.jpg?w=1'));
        $this->assertFalse(Draft::urlIsVideo(null));
        $this->assertFalse(Draft::urlIsVideo(''));
    }

    public function test_image_asset_url_is_its_own_thumbnail(): void
    {
        $d = $this->draft('https://x.test/poster.png');

        $this->assertSame('https://x.test/poster.png', $d->displayThumbnailUrl());
        $this->assertFalse($d->hasVideoAsset());
    }

    public function test_video_draft_thumbnails_the_latest_keyframe_image(): void
    {
        // Mirrors draft #361's shape: keyframe png then the mp4 in history.
        $d = $this->draft('https://x.test/clip.mp4', [
            'https://x.test/keyframe.png',
            'https://x.test/clip.mp4',
        ]);

        $this->assertTrue($d->hasVideoAsset());
        $this->assertSame('https://x.test/keyframe.png', $d->displayThumbnailUrl());
    }

    public function test_video_draft_with_no_keyframe_has_no_thumbnail_url(): void
    {
        $d = $this->draft('https://x.test/clip.mp4', ['https://x.test/clip.mp4']);

        $this->assertTrue($d->hasVideoAsset());
        $this->assertNull($d->displayThumbnailUrl(), 'placeholder is shown by the column, not a URL');
    }

    public function test_no_asset_has_no_thumbnail_and_is_not_video(): void
    {
        $d = $this->draft(null, []);

        $this->assertFalse($d->hasVideoAsset());
        $this->assertNull($d->displayThumbnailUrl());
    }

    public function test_most_recent_image_in_history_wins(): void
    {
        $d = $this->draft('https://x.test/clip.mp4', [
            'https://x.test/old-keyframe.png',
            'https://x.test/clip.mp4',
            'https://x.test/new-keyframe.jpg',
        ]);

        $this->assertSame('https://x.test/new-keyframe.jpg', $d->displayThumbnailUrl());
    }
}

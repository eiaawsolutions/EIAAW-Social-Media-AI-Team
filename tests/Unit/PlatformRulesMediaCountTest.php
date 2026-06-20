<?php

namespace Tests\Unit;

use App\Models\Draft;
use App\Models\PlatformConnection;
use App\Services\Blotato\PlatformRules;
use Tests\TestCase;

/**
 * The media_required gate counts only PUBLISHABLE media — exactly what
 * SubmitScheduledPost::collectDraftMediaUrls actually sends: the primary
 * asset_url, and only when it is durable.
 *
 * Before PR#50's follow-up, countMedia summed asset_url + every asset_urls
 * entry. That over-reported and let the gate PASS drafts that then failed
 * on-platform:
 *   - media living ONLY in the asset_urls history (never published) → counted ≥1
 *   - an ephemeral /storage/branding/ primary (the 2026-06-20 failure) → 404s,
 *     not real media, but counted 1
 *
 * These tests lock the gate to the publish reality. Pure unit — no DB.
 */
class PlatformRulesMediaCountTest extends TestCase
{
    private function instagramDraft(): Draft
    {
        // Instagram: media_required = true, media_min = 1.
        $draft = new Draft();
        $draft->platform = 'instagram';
        $draft->body = 'A short, valid Instagram caption.';
        $draft->hashtags = [];
        $draft->mentions = [];

        return $draft;
    }

    private function mediaViolated(array $result): bool
    {
        return in_array('media_required', array_column($result['violations'], 'kind'), true);
    }

    public function test_durable_primary_passes_the_media_gate(): void
    {
        $draft = $this->instagramDraft();
        $draft->asset_url = 'https://smt-assets.eiaawsolutions.com/branding/387-good.jpg';
        $draft->asset_urls = [$draft->asset_url];

        $result = PlatformRules::evaluate($draft, new PlatformConnection());

        $this->assertFalse($this->mediaViolated($result), 'A durable primary asset_url must satisfy media_required.');
    }

    public function test_ephemeral_primary_is_held_by_the_media_gate(): void
    {
        // The 2026-06-20 class: a /storage/branding/ primary 404s on-platform, so
        // it is NOT real media — the gate must hold the draft for regeneration.
        $draft = $this->instagramDraft();
        $draft->asset_url = 'https://smt.eiaawsolutions.com/storage/branding/386-dead.jpg';
        $draft->asset_urls = [$draft->asset_url];

        $result = PlatformRules::evaluate($draft, new PlatformConnection());

        $this->assertTrue($this->mediaViolated($result), 'An ephemeral /storage/branding/ primary must NOT satisfy media_required.');
    }

    public function test_media_only_in_history_is_held_by_the_media_gate(): void
    {
        // asset_urls is a history ledger that is never published. A draft whose
        // ONLY media lives there (no primary) must count as 0 and be held — the
        // old count summed history and wrongly passed.
        $draft = $this->instagramDraft();
        $draft->asset_url = null;
        $draft->asset_urls = [
            'https://smt-assets.eiaawsolutions.com/branding/387-old-still.jpg',
            'https://v3b.fal.media/files/b/x/stale.mp4',
        ];

        $result = PlatformRules::evaluate($draft, new PlatformConnection());

        $this->assertTrue($this->mediaViolated($result), 'Media present only in asset_urls history must NOT satisfy media_required.');
    }

    public function test_no_media_is_held_by_the_media_gate(): void
    {
        $draft = $this->instagramDraft();
        $draft->asset_url = null;
        $draft->asset_urls = null;

        $result = PlatformRules::evaluate($draft, new PlatformConnection());

        $this->assertTrue($this->mediaViolated($result), 'A draft with no media must fail media_required.');
    }
}

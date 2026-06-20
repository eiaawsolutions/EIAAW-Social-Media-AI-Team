<?php

namespace Tests\Unit;

use App\Models\Draft;
use Tests\TestCase;

/**
 * Covers the body-fingerprint bookkeeping that lets the UI tell when a draft's
 * generated still/video no longer reflects the (edited) caption — the signal
 * "Generate video" uses to decide whether to regenerate the keyframe from the
 * new text. All pure model logic, no DB.
 */
class DraftMediaStalenessTest extends TestCase
{
    private function draft(array $attrs = []): Draft
    {
        $draft = new Draft(['body' => $attrs['body'] ?? 'Original caption about hiring for competence.']);

        if (array_key_exists('branding_payload', $attrs)) {
            $draft->setAttribute('branding_payload', $attrs['branding_payload']);
        }
        if (array_key_exists('asset_url', $attrs)) {
            $draft->setAttribute('asset_url', $attrs['asset_url']);
        }
        if (array_key_exists('asset_urls', $attrs)) {
            $draft->setAttribute('asset_urls', $attrs['asset_urls']);
        }

        return $draft;
    }

    public function test_hash_is_stable_and_ignores_cosmetic_whitespace_and_case(): void
    {
        $a = Draft::hashBody('Confidence is not competence.');
        $b = Draft::hashBody("  confidence   is not\ncompetence.  ");

        $this->assertSame($a, $b, 'whitespace/case-only differences must not change the hash');
    }

    public function test_hash_changes_when_wording_changes(): void
    {
        $this->assertNotSame(
            Draft::hashBody('We screen for competence.'),
            Draft::hashBody('We screen for confidence.'),
        );
    }

    public function test_body_hash_matches_static_hash_of_current_body(): void
    {
        $draft = $this->draft(['body' => 'A fresh caption.']);

        $this->assertSame(Draft::hashBody('A fresh caption.'), $draft->bodyHash());
    }

    public function test_media_is_not_stale_when_there_is_no_media(): void
    {
        $draft = $this->draft(['asset_url' => null, 'branding_payload' => null]);

        $this->assertFalse($draft->mediaIsStaleForBody(), 'no still → nothing to be stale');
    }

    public function test_media_is_fresh_when_hash_matches_current_body(): void
    {
        $body = 'The exact body the still was made from.';
        $draft = $this->draft([
            'body' => $body,
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody($body)],
        ]);

        $this->assertFalse($draft->mediaIsStaleForBody());
    }

    public function test_media_is_stale_after_the_caption_is_edited(): void
    {
        // Still was made from the OLD body; the body has since been edited.
        $draft = $this->draft([
            'body' => 'The edited, new caption.',
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody('The old caption.')],
        ]);

        $this->assertTrue($draft->mediaIsStaleForBody());
    }

    public function test_media_is_stale_when_hash_is_missing_but_a_still_exists(): void
    {
        // Legacy draft (predates the stamp) OR branding_payload cleared by an
        // edit. Treated as stale so we regenerate rather than risk an
        // off-message keyframe.
        $draft = $this->draft([
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => null,
        ]);

        $this->assertNull($draft->mediaBodyHash());
        $this->assertTrue($draft->mediaIsStaleForBody());
    }

    // ── The #436 desync: media_body_hash "fresh" over a stale distillation ──

    public function test_media_is_stale_when_distillation_signals_are_stale_despite_a_fresh_media_hash(): void
    {
        // The exact prod shape of draft #436: media_body_hash equals the CURRENT
        // body (so the old gate read "fresh"), but the distilled signals
        // (quote/voiceover/infographic_*) are from an older caption and carry NO
        // distilled_body_hash. The media is rendered FROM those stale signals, so
        // it must read as stale and force a rebuild.
        $body = 'Who is SMT best suited for and why.';
        $draft = $this->draft([
            'body' => $body,
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => [
                'quote' => 'Flat per-brand pricing makes the math transparent.',
                'voiceover' => 'Per-seat pricing was built for enterprise budgets.',
                'infographic_title' => 'Per-seat vs flat',
                'media_body_hash' => Draft::hashBody($body),
            ],
        ]);

        $this->assertSame($draft->mediaBodyHash(), $draft->bodyHash(), 'precondition: media hash reads fresh');
        $this->assertFalse($draft->distillationIsFreshForBody(), 'precondition: distillation is not fresh');
        $this->assertTrue(
            $draft->mediaIsStaleForBody(),
            'media built from stale distilled signals must read stale even when media_body_hash matches',
        );
    }

    public function test_media_stays_fresh_when_distillation_is_stamped_fresh_and_media_hash_matches(): void
    {
        $body = 'A caption about reliable on-time publishing.';
        $draft = $this->draft([
            'body' => $body,
            'asset_url' => 'https://cdn.example/still.jpg',
            'branding_payload' => [
                'quote' => 'Posts go out on time.',
                'distilled_body_hash' => Draft::hashBody($body),
                'media_body_hash' => Draft::hashBody($body),
            ],
        ]);

        $this->assertFalse($draft->mediaIsStaleForBody());
    }

    public function test_library_media_with_no_distilled_signals_is_not_marked_stale_by_a_missing_distill_stamp(): void
    {
        $body = 'A client-brand caption with a library image.';
        $draft = $this->draft([
            'body' => $body,
            'asset_url' => 'https://cdn.example/library.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody($body)],
        ]);

        $this->assertFalse($draft->hasDistilledSignals());
        $this->assertFalse($draft->distillationIsFreshForBody());
        $this->assertFalse(
            $draft->mediaIsStaleForBody(),
            'non-distilled media whose media_body_hash matches must read fresh',
        );
    }

    public function test_library_media_is_stale_when_caption_edited_even_without_distilled_signals(): void
    {
        $draft = $this->draft([
            'body' => 'The edited client caption.',
            'asset_url' => 'https://cdn.example/library.jpg',
            'branding_payload' => ['media_body_hash' => Draft::hashBody('The original client caption.')],
        ]);

        $this->assertFalse($draft->hasDistilledSignals());
        $this->assertTrue($draft->mediaIsStaleForBody());
    }

    public function test_media_in_history_only_after_primary_deleted_is_still_subject_to_staleness(): void
    {
        $body = 'Who is SMT best suited for and why.';
        $draft = $this->draft([
            'body' => $body,
            'asset_url' => null,
            'asset_urls' => [
                'https://v3b.fal.media/files/b/old/clip-a.mp4',
                'https://v3b.fal.media/files/b/old/clip-b.mp4',
            ],
            'branding_payload' => [
                'quote' => 'Flat per-brand pricing makes the math transparent.',
                'infographic_title' => 'Per-seat vs flat',
                'media_body_hash' => Draft::hashBody($body),
            ],
        ]);

        $this->assertTrue($draft->hasAnyMedia(), 'history media counts as media');
        $this->assertTrue(
            $draft->mediaIsStaleForBody(),
            'history-only media over stale signals must read stale after primary delete',
        );
    }

    public function test_no_media_anywhere_is_never_stale(): void
    {
        $draft = $this->draft([
            'body' => 'Fresh caption, no media yet.',
            'asset_url' => null,
            'asset_urls' => [],
            'branding_payload' => [
                'quote' => 'A stale quote from an older body.',
            ],
        ]);

        $this->assertFalse($draft->hasAnyMedia());
        $this->assertFalse($draft->mediaIsStaleForBody());
    }

    // ── distillationIsFreshForBody — gates the distiller cache ──────────────

    public function test_distillation_fresh_when_stamp_matches_current_body(): void
    {
        $body = 'A caption about reliability and on-time publishing.';
        $draft = $this->draft([
            'body' => $body,
            'branding_payload' => [
                'quote' => 'Posts go out on time.',
                'distilled_body_hash' => Draft::hashBody($body),
            ],
        ]);

        $this->assertTrue($draft->distillationIsFreshForBody());
    }

    public function test_distillation_stale_when_stamp_is_for_a_different_body(): void
    {
        // The #436 case: cache exists but was distilled from an OLDER body —
        // the caption never changed through the editor, yet the distillation
        // doesn't match. Must read as a cache miss.
        $draft = $this->draft([
            'body' => 'Who is SMT best suited for and why.',
            'branding_payload' => [
                'quote' => 'Flat per-brand pricing makes the math transparent.',
                'distilled_body_hash' => Draft::hashBody('A completely different older caption about pricing.'),
            ],
        ]);

        $this->assertFalse($draft->distillationIsFreshForBody());
    }

    public function test_distillation_stale_when_stamp_is_missing(): void
    {
        // Legacy cache that predates the stamp → must re-distil, never trust it.
        $draft = $this->draft([
            'branding_payload' => ['quote' => 'Some old quote', 'voiceover' => 'Some old voiceover'],
        ]);

        $this->assertFalse($draft->distillationIsFreshForBody());
    }

    public function test_distillation_stale_when_no_payload_at_all(): void
    {
        $draft = $this->draft(['branding_payload' => null]);

        $this->assertFalse($draft->distillationIsFreshForBody());
    }
}

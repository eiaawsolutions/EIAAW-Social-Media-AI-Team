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

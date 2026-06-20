<?php

namespace Tests\Unit;

use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Services\Imagery\DraftSceneBrief;
use Tests\TestCase;

/**
 * When a draft's distillation is STALE for the current body (caption drifted
 * from what the Writer/Strategist authored — e.g. draft #436's pricing headline
 * + pricing visual_direction under a "who SMT suits" caption), the non-body-
 * gated signals (headline-hook, CTA, calendar-entry visual_direction) must be
 * suppressed so they can't drag off-message art direction into the visual. When
 * the distillation IS fresh, those authored signals are honored as before.
 */
class DraftSceneBriefStaleSuppressionTest extends TestCase
{
    private function draft(bool $fresh): Draft
    {
        $body = 'Who is SMT best suited for and why. SMT is built for boutique agencies.';
        $draft = new Draft(['body' => $body]);

        $branding = [
            'quote' => 'Built for the people doing the work.',
            'distilled_body_hash' => Draft::hashBody($fresh ? $body : 'an old pricing-math caption'),
        ];
        $draft->setAttribute('branding_payload', $branding);
        $draft->setAttribute('platform_payload', [
            'headline' => 'Per-seat pricing was built for enterprise IT budgets',
            'cta' => 'Run the numbers',
        ]);

        $entry = new CalendarEntry([
            'visual_direction' => 'Carousel: bold cost comparison headline; line-by-line pricing math.',
        ]);
        $draft->setRelation('calendarEntry', $entry);

        return $draft;
    }

    public function test_stale_distillation_suppresses_headline_cta_and_visual_direction(): void
    {
        $brief = DraftSceneBrief::for($this->draft(fresh: false), 24);

        // The stale pricing headline, CTA and art direction must NOT appear.
        $this->assertStringNotContainsString('Per-seat pricing', $brief);
        $this->assertStringNotContainsString('Run the numbers', $brief);
        $this->assertStringNotContainsString('pricing math', $brief);
        $this->assertStringNotContainsString('cost comparison', $brief);

        // The hook falls back to the body's first sentence; the fresh quote stays.
        $this->assertStringContainsString('Who is SMT best suited for', $brief);
        $this->assertStringContainsString('Built for the people doing the work', $brief);
    }

    public function test_fresh_distillation_honors_authored_signals(): void
    {
        $brief = DraftSceneBrief::for($this->draft(fresh: true), 24);

        // When the distillation matches the body, the authored headline, CTA and
        // art direction are trusted and included.
        $this->assertStringContainsString('Per-seat pricing was built for enterprise', $brief);
        $this->assertStringContainsString('Run the numbers', $brief);
        $this->assertStringContainsString('cost comparison', $brief);
    }
}

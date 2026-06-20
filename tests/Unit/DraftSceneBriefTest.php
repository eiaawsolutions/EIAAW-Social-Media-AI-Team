<?php

namespace Tests\Unit;

use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Services\Imagery\DraftSceneBrief;
use Tests\TestCase;

class DraftSceneBriefTest extends TestCase
{
    private function makeDraft(array $draftAttrs = [], array $entryAttrs = []): Draft
    {
        $draft = new Draft(array_merge([
            'platform' => 'instagram',
            'body' => 'Hiring is broken because we screen for confidence, not competence. Here is what we changed.',
        ], $draftAttrs));

        // Attributes that aren't mass-assignable in the test still need to read
        // back; force them so the JSON-cast accessors return arrays.
        foreach (['platform_payload', 'branding_payload'] as $jsonCol) {
            if (array_key_exists($jsonCol, $draftAttrs)) {
                $draft->setAttribute($jsonCol, $draftAttrs[$jsonCol]);
            }
        }

        // These tests exercise the NORMAL path where the distillation matches the
        // body, so the authored headline/CTA/visual_direction are trusted. Stamp
        // a fresh distilled_body_hash so the freshness gate
        // (Draft::distillationIsFreshForBody) reads true.
        $bp = is_array($draft->branding_payload) ? $draft->branding_payload : [];
        if (! array_key_exists('distilled_body_hash', $bp)) {
            $bp['distilled_body_hash'] = Draft::hashBody($draft->body);
            $draft->setAttribute('branding_payload', $bp);
        }

        $entry = new CalendarEntry(array_merge([
            'visual_direction' => 'A real hiring manager reviewing CVs at a sunlit desk',
        ], $entryAttrs));
        if (array_key_exists('research_brief', $entryAttrs)) {
            $entry->setAttribute('research_brief', $entryAttrs['research_brief']);
        }

        $draft->setRelation('calendarEntry', $entry);

        return $draft;
    }

    public function test_brief_anchors_to_scripted_artefacts_not_just_body(): void
    {
        $draft = $this->makeDraft([
            'platform_payload' => [
                'headline' => 'Confidence is not competence',
                'cta' => 'Rethink your next hire',
            ],
            'branding_payload' => [
                'quote' => 'We screen for competence, not for confidence.',
            ],
            'research_brief' => null,
        ], [
            'research_brief' => ['creative' => ['target_emotion' => 'quiet conviction']],
        ]);

        $brief = DraftSceneBrief::for($draft, 24);

        // The scripted hook, distilled quote, CTA, emotion and art direction
        // must all surface — this is the whole point: the visual depicts what
        // the post SAYS, not the first 24 words of the body.
        $this->assertStringContainsString('Confidence is not competence', $brief);
        $this->assertStringContainsString('We screen for competence, not for confidence.', $brief);
        $this->assertStringContainsString('Rethink your next hire', $brief);
        $this->assertStringContainsString('quiet conviction', $brief);
        $this->assertStringContainsString('A real hiring manager reviewing CVs', $brief);

        // And it must instruct the model NOT to render the text literally.
        $this->assertStringContainsStringIgnoringCase('do NOT render this text', $brief);
    }

    public function test_brief_falls_back_to_body_first_sentence_as_hook(): void
    {
        // No headline → the hook is the body's first sentence (Writer contract).
        $draft = $this->makeDraft([
            'platform_payload' => [],
            'branding_payload' => [],
        ], [
            'visual_direction' => '',
            'research_brief' => null,
        ]);

        $brief = DraftSceneBrief::for($draft, 24);

        $this->assertStringContainsString('Hiring is broken', $brief);
    }

    public function test_brief_strips_hashtags_mentions_and_urls(): void
    {
        $draft = $this->makeDraft([
            'platform_payload' => ['headline' => 'Big news @acme see https://x.co/abc #hiring #growth'],
            'branding_payload' => [],
        ]);

        $brief = DraftSceneBrief::for($draft, 24);

        $this->assertStringNotContainsString('@acme', $brief);
        $this->assertStringNotContainsString('https://x.co/abc', $brief);
        $this->assertStringNotContainsString('#hiring', $brief);
    }

    public function test_voiceover_reads_from_branding_payload(): void
    {
        $draft = $this->makeDraft([
            'branding_payload' => [
                'voiceover' => 'We stopped rewarding confidence. We started measuring what people can actually do.',
            ],
        ]);

        $this->assertSame(
            'We stopped rewarding confidence. We started measuring what people can actually do.',
            DraftSceneBrief::voiceover($draft),
        );
    }

    public function test_empty_draft_yields_empty_brief(): void
    {
        $draft = $this->makeDraft([
            'body' => '',
            'platform_payload' => [],
            'branding_payload' => [],
        ], [
            'visual_direction' => '',
            'research_brief' => null,
        ]);

        $this->assertSame('', DraftSceneBrief::for($draft, 24));
    }
}

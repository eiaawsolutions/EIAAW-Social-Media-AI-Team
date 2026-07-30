<?php

namespace Tests\Unit;

use App\Agents\ComplianceAgent;
use App\Agents\DesignerAgent;
use App\Models\Brand;
use App\Models\CalendarEntry;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Branding\PosterContentWriter;
use App\Services\Imagery\ImageCreativeDirection;
use App\Services\Llm\LlmGateway;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The routing bug that published six blank posts (scheduled_posts #541-#546,
 * 2026-07-29/30).
 *
 * buildPrompt() decided "this is a hook poster", so it asked the image model
 * for a background with ABSOLUTELY NO TEXT — the headline is drawn on
 * afterwards by InfographicComposer. handle() then RE-DERIVED that same
 * decision from the calendar entry to decide whether to run the composer, but
 * its copy of the condition (added before PR#84) tested only isPosterFormat()
 * and isInfographicFormat() — never shouldRouteToHookPoster().
 *
 * Since shouldRouteToHookPoster() returns false whenever isPosterFormat() is
 * true, the two are mutually exclusive BY CONSTRUCTION. So a draft routed by
 * the hook-poster net evaluated handle()'s gate as false with certainty: the
 * composer never ran, and the deliberately-empty canvas was published as-is.
 * Not a flake — a 100% reproduction on every hook-poster draft.
 *
 * The fix records the decision once ($pendingRouteKind) and reads it back
 * instead of re-deriving it. These tests lock both the predicate relationship
 * that made the old code silently wrong, and the recorded-decision contract
 * that replaced it.
 *
 * DB-free: relations are attached to unsaved models, the LLM distiller is
 * faked in the container.
 */
class DesignerPosterComposeGateTest extends TestCase
{
    private function designer(): DesignerAgent
    {
        return new DesignerAgent(new LlmGateway);
    }

    private function readProp(DesignerAgent $d, string $prop): mixed
    {
        $p = new ReflectionProperty(DesignerAgent::class, $prop);

        return $p->getValue($d);
    }

    private function writeProp(DesignerAgent $d, string $prop, mixed $value): void
    {
        (new ReflectionProperty(DesignerAgent::class, $prop))->setValue($d, $value);
    }

    /** A single_image draft with no photo-first signal — the PR#84 default. */
    private function hookPosterDraft(): Draft
    {
        $workspace = new Workspace(['name' => 'EIAAW HQ', 'plan' => 'eiaaw_internal']);

        $brand = new Brand(['name' => 'EIAAW Solutions']);
        $brand->setRelation('workspace', $workspace);

        $entry = new CalendarEntry([
            'format' => 'single_image',
            'pillar' => 'thought_leadership',
            'visual_direction' => 'editorial graphic',
        ]);

        $draft = new Draft([
            'platform' => 'instagram',
            'body' => 'Thirty days of questions from Malaysian founders and ops leaders.',
        ]);
        $draft->setRelation('calendarEntry', $entry);
        $draft->setRelation('brand', $brand);

        return $draft;
    }

    private function fakeDistiller(): void
    {
        $fake = new class extends PosterContentWriter
        {
            public function __construct() {}

            public function distil(Draft $draft, Brand $brand): array
            {
                return [
                    'title' => 'Real questions from Malaysian tech leaders',
                    'points' => ['First point', 'Second point', 'Third point', 'Fourth point'],
                ];
            }
        };

        $this->app->instance(PosterContentWriter::class, $fake);
    }

    // ── The predicate relationship that guaranteed the bug ────────────────

    public function test_hook_poster_route_and_poster_format_are_mutually_exclusive(): void
    {
        // This is WHY re-deriving the route in a second place was fatal rather
        // than merely redundant: any gate that tests isPosterFormat() alone is
        // guaranteed — not merely likely — to be false for a hook-poster draft.
        config(['services.branding.hook_posters' => true]);

        $isPoster = ImageCreativeDirection::isPosterFormat('single_image', 'thought_leadership', 'editorial graphic');
        $isHook = ImageCreativeDirection::shouldRouteToHookPoster('single_image', 'thought_leadership', 'editorial graphic', null);

        $this->assertFalse($isPoster);
        $this->assertTrue($isHook);
        $this->assertNotSame($isPoster, $isHook, 'The two poster predicates never agree — either alone is an incomplete gate.');
    }

    // ── The recorded decision ────────────────────────────────────────────

    public function test_hook_poster_draft_records_both_the_route_and_the_compose_payload(): void
    {
        config([
            'services.branding.hook_posters' => true,
            'services.branding.compose_infographics' => true,
            'services.branding.enabled' => true,
            'services.fal.image_model' => 'fal-ai/nano-banana',
        ]);
        $this->fakeDistiller();

        $designer = $this->designer();
        $draft = $this->hookPosterDraft();

        $prompt = (new ReflectionMethod(DesignerAgent::class, 'buildPrompt'))
            ->invoke($designer, $draft->brand, $draft);

        // The prompt asked for an EMPTY canvas...
        $this->assertStringContainsString('NO TEXT', $prompt);

        // ...so the compose step is mandatory, and the route must be recorded.
        $this->assertSame('poster', $this->readProp($designer, 'pendingRouteKind'));
        $this->assertNotNull(
            $this->readProp($designer, 'pendingCompose'),
            'A text-free background with no compose payload is an unpublishable blank.',
        );
    }

    public function test_photo_route_records_no_poster_state(): void
    {
        // isPhotoFirst → stays a real photograph; nothing to compose.
        config(['services.branding.hook_posters' => true, 'services.fal.image_model' => 'fal-ai/nano-banana']);

        $designer = $this->designer();
        $draft = $this->hookPosterDraft();
        $draft->calendarEntry->pillar = 'brand_moment';
        $draft->calendarEntry->visual_direction = 'lifestyle photo of the team';

        (new ReflectionMethod(DesignerAgent::class, 'buildPrompt'))->invoke($designer, $draft->brand, $draft);

        $this->assertNull($this->readProp($designer, 'pendingRouteKind'));
        $this->assertNull($this->readProp($designer, 'pendingCompose'));
    }

    // ── The contract handed to the compliance gate ───────────────────────

    public function test_uncomposed_poster_produces_a_contract_compliance_blocks(): void
    {
        $designer = $this->designer();
        $this->writeProp($designer, 'pendingRouteKind', 'poster');
        $this->writeProp($designer, 'pendingCompose', ['kind' => 'poster', 'title' => 't', 'points' => ['a', 'b', 'c']]);

        $contract = (new ReflectionMethod(DesignerAgent::class, 'renderContract'))->invoke($designer, false);

        $this->assertTrue($contract['text_free_background']);
        $this->assertFalse($contract['composed']);
        $this->assertTrue(
            ComplianceAgent::contractProvesBlank($contract),
            'An uncomposed text-free background must be blockable without inspecting a single pixel.',
        );
    }

    public function test_composed_poster_produces_a_passing_contract(): void
    {
        $designer = $this->designer();
        $this->writeProp($designer, 'pendingRouteKind', 'poster');
        $this->writeProp($designer, 'pendingCompose', ['kind' => 'poster', 'title' => 't', 'points' => ['a', 'b', 'c']]);

        $contract = (new ReflectionMethod(DesignerAgent::class, 'renderContract'))->invoke($designer, true);

        $this->assertTrue($contract['composed']);
        $this->assertFalse(ComplianceAgent::contractProvesBlank($contract));
    }

    public function test_photo_contract_is_never_read_as_blank(): void
    {
        // The photo path legitimately has composed=false. Treating that as
        // "blank" would hold every photographic post ever generated.
        $designer = $this->designer();

        $contract = (new ReflectionMethod(DesignerAgent::class, 'renderContract'))->invoke($designer, false);

        $this->assertSame('photo', $contract['route']);
        $this->assertFalse($contract['text_free_background']);
        $this->assertFalse(ComplianceAgent::contractProvesBlank($contract));
    }
}

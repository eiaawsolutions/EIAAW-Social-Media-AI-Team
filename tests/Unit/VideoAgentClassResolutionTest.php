<?php

namespace Tests\Unit;

use App\Agents\VideoAgent;
use Tests\TestCase;

/**
 * Regression guard for the class of bug that took down the Veo 3 video path in
 * prod: VideoAgent::buildPrompt() referenced DraftSceneBrief without a `use`
 * import, so PHP resolved it to App\Agents\DraftSceneBrief (non-existent) and
 * threw "Class not found" only at runtime — never at lint, never in the unit
 * suite. This asserts every collaborator VideoAgent statically references
 * actually resolves to a real, loadable class.
 */
class VideoAgentClassResolutionTest extends TestCase
{
    public function test_all_collaborator_classes_referenced_by_video_agent_exist(): void
    {
        // The fully-qualified collaborators VideoAgent depends on. If a `use`
        // import is dropped (or points at the wrong namespace) the class won't
        // load and this fails — before it can blow up a real generation.
        $collaborators = [
            \App\Services\Imagery\DraftSceneBrief::class,
            \App\Services\Imagery\ImageCreativeDirection::class,
            \App\Services\Imagery\EiaawBrandLock::class,
            \App\Services\Imagery\FalAiClient::class,
            \App\Services\Imagery\BrandAssetPicker::class,
            \App\Services\Branding\BrandVideoComposer::class,
            \App\Services\Branding\FalTtsClient::class,
            \App\Services\Branding\QuoteWriter::class,
            \App\Services\Billing\PlanCaps::class,
            \App\Services\Blotato\BlotatoClient::class,
            \App\Models\AiCost::class,
            \App\Models\Draft::class,
            \App\Models\Brand::class,
        ];

        foreach ($collaborators as $fqcn) {
            $this->assertTrue(
                class_exists($fqcn),
                "VideoAgent collaborator {$fqcn} does not resolve — a `use` import is likely missing or wrong.",
            );
        }
    }

    public function test_video_agent_uses_the_imagery_namespace_for_scene_brief(): void
    {
        // The specific import that was missing. Assert the source file imports
        // the Imagery DraftSceneBrief (not an accidental App\Agents one).
        $source = file_get_contents((new \ReflectionClass(VideoAgent::class))->getFileName());

        $this->assertStringContainsString(
            'use App\Services\Imagery\DraftSceneBrief;',
            $source,
            'VideoAgent must import App\Services\Imagery\DraftSceneBrief — without it, buildPrompt() resolves DraftSceneBrief to App\Agents and throws at runtime.',
        );
    }
}

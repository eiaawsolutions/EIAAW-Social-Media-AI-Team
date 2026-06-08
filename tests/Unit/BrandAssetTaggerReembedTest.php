<?php

namespace Tests\Unit;

use App\Services\Imagery\BrandAssetTagger;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks the description-editor's re-embed path: reembed() is PUBLIC, embeds the
 * CURRENT description+tags, and does NOT run Claude vision (that's tag()'s job
 * and an unnecessary cost when the operator already wrote the text). Also guards
 * that the editor would surface a failure (reembed re-throws; the tagging flow
 * keeps best-effort via a separate wrapper).
 *
 * DB-FREE by design — reflection + source assertions; no LLM call, no DB.
 */
class BrandAssetTaggerReembedTest extends TestCase
{
    public function test_reembed_is_public(): void
    {
        $m = new ReflectionMethod(BrandAssetTagger::class, 'reembed');
        $this->assertTrue($m->isPublic(), 'reembed() must be public for the editor page to call it');
    }

    public function test_reembed_does_not_invoke_vision(): void
    {
        $src = $this->source();

        // The whole vision path is describeViaClaude(); reembed must not call it.
        preg_match('/public function reembed\(.*?\n    \}/s', $src, $m);
        $body = $m[0] ?? '';
        $this->assertNotSame('', $body, 'could not isolate reembed() body');

        $this->assertStringNotContainsString('describeViaClaude', $body,
            'reembed() must NOT run Claude vision');
        // It DOES embed the description + tags blob.
        $this->assertStringContainsString('embed', $body);
        $this->assertStringContainsString('description', $body);
        $this->assertStringContainsString('tags', $body);
    }

    public function test_reembed_rethrows_but_tagging_stays_best_effort(): void
    {
        $src = $this->source();

        // reembed() itself has no try/catch (it re-throws so the editor can toast).
        preg_match('/public function reembed\(.*?\n    \}/s', $src, $m);
        $reembedBody = $m[0] ?? '';
        $this->assertStringNotContainsString('try {', $reembedBody,
            'reembed() must re-throw, not swallow — the editor surfaces the failure');

        // The tagging paths keep best-effort via a wrapper that DOES catch.
        $this->assertStringContainsString('reembedSafely', $src);
        $this->assertMatchesRegularExpression('/reembedSafely\(.*?\{.*?try \{/s', $src,
            'reembedSafely() must wrap reembed() in try/catch for the tagging flow');
    }

    public function test_tagging_paths_use_the_safe_wrapper_not_raw_reembed(): void
    {
        $src = $this->source();

        // tag() and tagFromFilename() must call reembedSafely(), never the
        // re-throwing reembed() directly (a vision-tag failure must not lose the row).
        foreach (['public function tag(', 'private function tagFromFilename('] as $needle) {
            $this->assertStringContainsString($needle, $src);
        }
        // Count direct reembed() calls — should be exactly one, inside reembedSafely().
        $directCalls = preg_match_all('/\$this->reembed\(/', $src);
        $this->assertSame(1, $directCalls,
            'only reembedSafely() may call reembed() directly; tagging paths go through the wrapper');
    }

    private function source(): string
    {
        $file = (new ReflectionClass(BrandAssetTagger::class))->getFileName();
        $this->assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}

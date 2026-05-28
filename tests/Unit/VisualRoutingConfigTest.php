<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Guards the routing knobs that decide whether a post's visual comes from the
 * stock library or from a bespoke scripted-brief generation. These defaults
 * are load-bearing: loosening the floor or flipping internal_prefers_ai off is
 * what let a generic stock photo (unrelated to the caption) land on a post.
 */
class VisualRoutingConfigTest extends TestCase
{
    public function test_library_match_floor_is_strict_by_default(): void
    {
        // 0.32 (strict) — not the old 0.45 (loose). A higher value reintroduces
        // the bug where an off-topic stock asset wins a weak semantic match.
        $this->assertSame(0.32, (float) config('services.fal.library_match_distance'));
        $this->assertLessThanOrEqual(0.35, (float) config('services.fal.library_match_distance'));
    }

    public function test_internal_brand_prefers_bespoke_ai_by_default(): void
    {
        // The EIAAW house brand must default to generating a bespoke on-message
        // visual rather than reusing a generic library asset.
        $this->assertTrue((bool) config('services.fal.internal_prefers_ai'));
    }
}

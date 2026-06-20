<?php

namespace Tests\Unit;

use App\Agents\OptimizerAgent;
use App\Models\PostMetric;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Collection;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P1 fix — buildSummary() used array_key_first() on a possibly-empty mix, which
 * returns null when every post in a dimension has a null pillar/format. The null
 * was coerced via (string) into the sentence, producing garbled output like
 * "Across N posts:  reached the most ...  pillar is your best-performing voice".
 * This locks a neutral fallback so the summary is always well-formed. DB-free:
 * buildSummary takes a Collection + three arrays; we build plain row stubs.
 */
class OptimizerSummaryTest extends TestCase
{
    private function summary(Collection $rows, array $pillar, array $format, array $platform): string
    {
        $agent = new OptimizerAgent(new LlmGateway);
        $m = new ReflectionMethod($agent, 'buildSummary');

        return $m->invoke($agent, $rows, $pillar, $format, $platform);
    }

    /** Minimal unsaved PostMetric rows exposing the fields buildSummary sums. */
    private function rows(int $count): Collection
    {
        return collect(range(1, $count))->map(function () {
            $m = new PostMetric;
            $m->impressions = 100;
            $m->likes = 5;
            $m->comments = 1;
            $m->shares = 0;
            $m->saves = 0;

            return $m;
        });
    }

    public function test_summary_is_well_formed_with_full_mixes(): void
    {
        $s = $this->summary(
            $this->rows(4),
            ['educational' => 0.6, 'community' => 0.4],
            ['carousel' => 0.7, 'single_image' => 0.3],
            ['instagram' => 0.8, 'linkedin' => 0.2],
        );

        $this->assertStringContainsString('Instagram', $s);
        $this->assertStringContainsString('Educational', $s);
        $this->assertStringNotContainsString('  ', $s); // no double-space gaps
    }

    public function test_summary_does_not_garble_when_a_dimension_is_empty(): void
    {
        // Every post had a null format → formatMix is empty.
        $s = $this->summary(
            $this->rows(3),
            ['educational' => 1.0],
            [], // empty format dimension
            ['instagram' => 1.0],
        );

        // No empty leader token, no double-space where the format clause was.
        $this->assertStringNotContainsString('  ', $s);
        // A neutral fallback phrase is present instead of a blank.
        $this->assertMatchesRegularExpression('/no clear .* leader|not enough/i', $s);
    }

    public function test_summary_neutral_when_all_dimensions_empty(): void
    {
        $s = $this->summary($this->rows(3), [], [], []);
        $this->assertStringNotContainsString('  ', $s);
        $this->assertNotSame('', trim($s));
    }
}

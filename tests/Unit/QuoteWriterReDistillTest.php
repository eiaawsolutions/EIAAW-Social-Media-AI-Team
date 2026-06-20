<?php

namespace Tests\Unit;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\Workspace;
use App\Services\Branding\QuoteWriter;
use App\Services\Llm\LlmCallResult;
use App\Services\Llm\LlmGateway;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * The fix for "edited caption ignored by Generate image/video" relies on the
 * distillers RE-RUNNING once the editor clears branding_payload. These tests
 * pin the cache boundary that makes that work:
 *
 *   - quote + voiceover present  → cache hit  → LLM NOT called (cheap re-run).
 *   - keys cleared (post-edit)   → cache miss → LLM called with the EDITED body.
 *
 * No DB: the LLM is mocked, and the cache-hit case never reaches a save().
 */
class QuoteWriterReDistillTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private function brand(): Brand
    {
        $brand = new Brand(['name' => 'EIAAW Solutions']);
        // distil() passes brand->workspace to the gateway; stub the relation so
        // no query runs.
        $brand->setRelation('workspace', new Workspace(['name' => 'HQ']));

        return $brand;
    }

    private function draft(array $attrs): Draft
    {
        $draft = new Draft(['body' => $attrs['body'] ?? 'A caption about competence over confidence.']);
        if (array_key_exists('branding_payload', $attrs)) {
            $draft->setAttribute('branding_payload', $attrs['branding_payload']);
        }

        return $draft;
    }

    public function test_returns_cache_without_calling_llm_when_payload_present(): void
    {
        $gateway = Mockery::mock(LlmGateway::class);
        $gateway->shouldNotReceive('call');

        $draft = $this->draft([
            'branding_payload' => [
                'quote' => 'We screen for competence, not confidence.',
                'voiceover' => 'We stopped rewarding confidence. We started measuring what people can do.',
            ],
        ]);

        $result = (new QuoteWriter($gateway))->distil($draft, $this->brand());

        $this->assertSame('cache', $result['source']);
        $this->assertSame('We screen for competence, not confidence.', $result['quote']);
    }

    public function test_re_distills_from_edited_body_when_payload_cleared(): void
    {
        // Post-edit state: the editor nulled branding_payload. The cache MUST be
        // a miss so the new quote/voiceover come from the edited caption.
        $gateway = Mockery::mock(LlmGateway::class);
        $gateway->shouldReceive('call')
            ->once()
            ->andReturn(new LlmCallResult(
                modelId: 'claude-haiku-4-5',
                promptVersion: QuoteWriter::PROMPT_VERSION,
                rawText: '{}',
                parsedJson: [
                    'quote' => 'The edited message, distilled.',
                    'voiceover' => 'A fresh voiceover that reflects the newly edited caption body text.',
                ],
                inputTokens: 120,
                outputTokens: 60,
                latencyMs: 40,
                costUsd: 0.0005,
                stopReason: 'end_turn',
                rawResponse: [],
            ));

        $draft = $this->draft([
            'body' => 'The brand-new edited caption with a different point entirely.',
            'branding_payload' => null,
        ]);

        $result = (new QuoteWriter($gateway))->distil($draft, $this->brand());

        $this->assertSame('llm', $result['source']);
        $this->assertSame('The edited message, distilled.', $result['quote']);
        // Mockery's ->once() expectation (verified in tearDown) proves the cache
        // was skipped and the LLM ran against the edited body.
    }
}

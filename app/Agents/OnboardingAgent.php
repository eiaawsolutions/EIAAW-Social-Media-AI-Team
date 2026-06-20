<?php

namespace App\Agents;

use App\Agents\Prompts\OnboardingPrompt;
use App\Models\Brand;
use App\Models\BrandStyle;
use App\Services\Embeddings\EmbeddingService;
use App\Services\Llm\LlmGateway;
use App\Services\Readiness\SetupReadiness;
use GuzzleHttp\Client as Http;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Synthesises a brand_style.md from a brand's website. Stage 2 of the wizard.
 *
 * Flow:
 *   1. Fetch the brand's website_url HTML.
 *   2. Strip to plain text (head/title/nav/main/p tags).
 *   3. Send to Claude with the OnboardingPrompt system + structured-output schema.
 *   4. Persist BrandStyle row (versioned, marked is_current=true).
 *   5. Embed the brand_style_md into pgvector for retrieval.
 *
 * Failure modes that surface to the user:
 *   - Brand has no website_url → user-actionable error.
 *   - Website returns 4xx/5xx → user-actionable error.
 *   - LLM returns malformed JSON → retry once, then fail loud.
 */
class OnboardingAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_created'];

    public function __construct(
        LlmGateway $llm,
        private readonly EmbeddingService $embeddings,
        private readonly Http $http = new Http(['timeout' => 30, 'allow_redirects' => true]),
    ) {
        parent::__construct($llm);
    }

    public function role(): string { return 'onboarding'; }
    public function promptVersion(): string { return OnboardingPrompt::VERSION; }

    /**
     * Whether a synthesised brand-style.md is long enough to be a real style
     * guide. The prompt targets 600-1200 words; we hard-floor at
     * OnboardingPrompt::MIN_STYLE_WORDS to reject a truncated/broken generation
     * before it's persisted. Pure (no DB / no I/O) so it's unit-testable.
     */
    public static function isAcceptableStyleLength(string $brandStyleMd): bool
    {
        return str_word_count(trim($brandStyleMd)) >= OnboardingPrompt::MIN_STYLE_WORDS;
    }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        if (empty($brand->website_url)) {
            return AgentResult::fail('This brand has no website URL. Add one in the brand profile and try again.');
        }

        // 1. Scrape
        $evidence = $this->scrapeEvidence($brand->website_url);
        if ($evidence === null) {
            return AgentResult::fail('Could not fetch the brand website. Check the URL is reachable and try again.');
        }

        // 2. Synthesise via Claude (structured output)
        $userMessage = "BRAND NAME: {$brand->name}\n"
            ."WEBSITE: {$brand->website_url}\n"
            .($brand->industry ? "INDUSTRY: {$brand->industry}\n" : "")
            ."\n--- EVIDENCE (verbatim from the website) ---\n"
            .$evidence['text']
            ."\n--- END EVIDENCE ---\n\n"
            ."Synthesise the brand-style.md and voice attributes per the schema.";

        $result = $this->llm->call(
            promptVersion: $this->promptVersion(),
            systemPrompt: OnboardingPrompt::system(),
            userMessage: $userMessage,
            brand: $brand,
            workspace: $brand->workspace,
            modelId: config('services.anthropic.default_model'),
            maxTokens: 8000,
            jsonSchema: OnboardingPrompt::schema(),
            agentRole: $this->role(),
        );

        $payload = $result->parsedJson;
        if (! $payload || empty($payload['brand_style_md'])) {
            Log::warning('Onboarding: LLM returned no usable JSON', [
                'brand_id' => $brand->id,
                'raw_sample' => substr($result->rawText, 0, 200),
            ]);
            return AgentResult::fail('The brand voice synthesis came back unstructured. Try again — the model occasionally needs a second attempt.');
        }

        // Reject an obviously-truncated brand-style.md rather than persisting a
        // half-written style guide (the prompt targets 600-1200 words). A
        // sub-floor document is a broken generation, not a usable voice spec —
        // surface the same "try again" path the unstructured branch uses.
        if (! self::isAcceptableStyleLength((string) $payload['brand_style_md'])) {
            Log::warning('Onboarding: brand_style_md below word-count floor', [
                'brand_id' => $brand->id,
                'word_count' => str_word_count((string) $payload['brand_style_md']),
            ]);
            return AgentResult::fail('The brand voice came back too short to be usable. Try again — the model occasionally truncates the first attempt.');
        }

        // 3. Persist BrandStyle (versioned + bump current pointer)
        $brandStyle = DB::transaction(function () use ($brand, $payload, $result, $evidence) {
            // Demote the previous current row, if any
            BrandStyle::where('brand_id', $brand->id)
                ->where('is_current', true)
                ->update(['is_current' => false]);

            $nextVersion = (int) (BrandStyle::where('brand_id', $brand->id)->max('version') ?? 0) + 1;

            return BrandStyle::create([
                'brand_id' => $brand->id,
                'version' => $nextVersion,
                'is_current' => true,
                'content_md' => $payload['brand_style_md'],
                'voice_attributes' => $payload['voice_attributes'] ?? [],
                'evidence_sources' => array_map(
                    fn (array $q) => [
                        'url' => $q['source_url'],
                        'quote' => $q['quote'],
                        'scraped_at' => now()->toIso8601String(),
                    ],
                    $payload['evidence_quotes'] ?? []
                ),
                'created_by_user_id' => auth()->id(),
            ]);
        });

        // 4. Embed for retrieval
        try {
            $vector = $this->embeddings->embed(
                text: $brandStyle->content_md,
                brand: $brand,
                workspace: $brand->workspace,
            );
            $brandStyle->update(['embedding' => $vector]);
        } catch (\Throwable $e) {
            Log::error('Onboarding: embedding failed but brand_style saved', [
                'brand_id' => $brand->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the whole agent — the BrandStyle row is still useful.
            // Stage 2 will show 90% of the receipt; user can re-run to embed.
        }

        // 5. Bust readiness cache so the wizard reflects the new stage immediately
        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'brand_style_id' => $brandStyle->id,
            'version' => $brandStyle->version,
            'word_count' => str_word_count($brandStyle->content_md),
            'evidence_count' => count($brandStyle->evidence_sources ?? []),
        ], [
            'model' => $result->modelId,
            'prompt_version' => $result->promptVersion,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => $result->costUsd,
            'latency_ms' => $result->latencyMs,
        ]);
    }

    /** @return array{text: string, url: string}|null */
    private function scrapeEvidence(string $url): ?array
    {
        try {
            $response = $this->http->get($url, [
                'headers' => [
                    'User-Agent' => 'EIAAW-SocialMediaTeam/1.0 (+https://eiaawsolutions.com)',
                    'Accept' => 'text/html,application/xhtml+xml',
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Onboarding: scrape failed', ['url' => $url, 'error' => $e->getMessage()]);
            return null;
        }

        $html = (string) $response->getBody();
        if (strlen($html) < 200) return null;

        // Strip <script>, <style>, <nav>, <footer> blocks before extracting text.
        $cleaned = preg_replace(
            ['/<script\b[^>]*>.*?<\/script>/is', '/<style\b[^>]*>.*?<\/style>/is', '/<nav\b[^>]*>.*?<\/nav>/is', '/<footer\b[^>]*>.*?<\/footer>/is'],
            ' ',
            $html
        );

        $text = trim(html_entity_decode(strip_tags($cleaned), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);

        // Cap at ~30k chars (~7.5k tokens) — enough context, predictable cost.
        // mb_substr (not substr): a byte cut can split a UTF-8 multibyte char,
        // and the malformed bytes then fail the Anthropic SDK's json_encode.
        if (mb_strlen($text) > 30_000) {
            $text = self::safeExcerpt($text, 30_000);
        }

        if (strlen($text) < 200) {
            return null; // mostly empty page
        }

        return ['text' => $text, 'url' => $url];
    }
}

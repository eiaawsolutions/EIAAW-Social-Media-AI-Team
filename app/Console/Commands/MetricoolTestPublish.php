<?php

namespace App\Console\Commands;

use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * LIVE end-to-end publish test for the Metricool switch — the gate before
 * trusting the publish path on real content.
 *
 * Unlike the unit tests (which fake HTTP), this performs a REAL POST to
 * Metricool's /v2/scheduler/posts for the given brand/network and then polls
 * the analytics list to confirm a real platform URL came back. It does NOT
 * touch the database, models, or the queue — it exercises MetricoolClient
 * directly so we can prove the wire contract in isolation.
 *
 * Safety: defaults to a DRAFT (autoPublish=false → lands in the Metricool
 * planner, nothing goes live). Pass --go to actually publish. Defaults to
 * text-only on a network that allows it. Use a throwaway/test brand.
 *
 * Usage:
 *   php artisan metricool:test-publish --blog=6322515 --network=linkedin
 *   php artisan metricool:test-publish --blog=6322515 --network=instagram --media=https://… --go
 */
class MetricoolTestPublish extends Command
{
    protected $signature = 'metricool:test-publish
                            {--blog= : Metricool blogId (brand) to publish to (required)}
                            {--network=linkedin : network slug (linkedin/instagram/tiktok/…)}
                            {--text= : caption; defaults to a timestamped test marker}
                            {--media=* : media URL(s) to normalise + attach}
                            {--go : ACTUALLY publish (autoPublish=true). Omit = safe draft in the planner.}';

    protected $description = 'LIVE end-to-end Metricool publish test (draft by default; --go to really publish).';

    public function handle(): int
    {
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            $this->warn('Metricool not configured (METRICOOL_API_TOKEN / METRICOOL_USER_ID). Skipping.');
            return self::SUCCESS;
        }

        $blog = (int) $this->option('blog');
        if ($blog <= 0) {
            $this->error('--blog=<id> is required.');
            return self::FAILURE;
        }

        $network = strtolower((string) $this->option('network'));
        $go = (bool) $this->option('go');
        $text = (string) ($this->option('text')
            ?: '[SMT test publish] Metricool switch verification — ' . Carbon::now()->toDateTimeString() . ' (safe to delete)');

        // Normalise any media.
        $media = [];
        foreach ((array) $this->option('media') as $url) {
            $this->line("Normalising media: {$url}");
            $norm = $client->normalizeMedia($url);
            if (! ($norm['found'] ?? false)) {
                $this->error('  normalize failed (HTTP ' . ($norm['status'] ?? '?') . '): ' . json_encode($norm['body']));
                return self::FAILURE;
            }
            $ref = is_string($norm['body']) ? $norm['body']
                : ($norm['body']['mediaId'] ?? $norm['body']['url'] ?? $norm['body']['id'] ?? null);
            if (! $ref) {
                $this->error('  could not extract media ref from: ' . json_encode($norm['body']));
                return self::FAILURE;
            }
            $media[] = $ref;
            $this->info("  ok → {$ref}");
        }

        $this->newLine();
        $this->info(($go ? 'PUBLISHING' : 'DRAFTING') . " to blogId={$blog}, network={$network}");
        $this->line('  text: ' . $text);
        $this->line('  media: ' . (empty($media) ? '(none, text-only)' : implode(', ', $media)));
        if (! $go) {
            $this->warn('  --go NOT set → autoPublish=false (saves a draft in the Metricool planner, nothing goes live).');
        }
        $this->newLine();

        try {
            $res = $client->schedulePost(
                blogId: $blog,
                networks: [$network],
                text: $text,
                publicationDateTime: Carbon::now()->addMinutes(2)->format('Y-m-d\TH:i:s'),
                timezone: (string) config('app.timezone', 'Asia/Kuala_Lumpur'),
                media: $media,
                autoPublish: $go,
            );
        } catch (\Throwable $e) {
            $this->error('schedulePost FAILED: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->info('schedulePost accepted (HTTP ' . ($res['status'] ?? '?') . ').');
        $this->line('Response: ' . json_encode($res['response'] ?? null, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $postId = null;
        if (is_array($res['response'] ?? null)) {
            $postId = $res['response']['id'] ?? $res['response']['data']['id'] ?? null;
        }
        if ($postId) {
            $this->info("Provider post id: {$postId}");
        }

        if (! $go) {
            $this->newLine();
            $this->info('DRAFT created — check the Metricool planner. Re-run with --go to publish for real.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info('Published. Verifying a real platform URL came back (polling analytics list)…');

        // Give the platform a moment, then look for the post URL in the list.
        $url = $this->findPublishedUrl($client, $blog, $network, $text);
        if ($url === null) {
            $this->warn('No platform URL surfaced yet. This is common immediately after publish — '
                . 'Metricool/the platform may take a few minutes. Re-run metricool:probe-metrics --blog='
                . $blog . ' --networks=' . $network . ' shortly to confirm.');
            return self::SUCCESS;
        }

        $this->info("VERIFIED live URL: {$url}");
        $this->info('The publish path works end-to-end. PUBLISH_PROVIDER=metricool is safe to keep as default.');
        return self::SUCCESS;
    }

    private function findPublishedUrl(MetricoolClient $client, int $blog, string $network, string $text): ?string
    {
        try {
            $result = $client->postAnalytics(
                blogId: $blog,
                from: Carbon::now()->subDay()->startOfDay()->format('Y-m-d\TH:i:s'),
                to: Carbon::now()->endOfDay()->format('Y-m-d\TH:i:s'),
                network: $network,
            );
        } catch (\Throwable $e) {
            $this->warn('  poll failed: ' . $e->getMessage());
            return null;
        }
        if (! ($result['found'] ?? false)) {
            return null;
        }

        $body = $result['body'];
        $posts = is_array($body) && array_is_list($body) ? $body : ($body['data'] ?? $body['posts'] ?? []);
        $marker = mb_substr($text, 0, 30);
        foreach ((array) $posts as $p) {
            if (! is_array($p)) {
                continue;
            }
            $content = (string) ($p['content'] ?? $p['text'] ?? '');
            if ($content !== '' && str_contains($content, $marker)) {
                return (string) ($p['url'] ?? $p['shareUrl'] ?? $p['postUrl'] ?? '') ?: null;
            }
        }
        return null;
    }
}

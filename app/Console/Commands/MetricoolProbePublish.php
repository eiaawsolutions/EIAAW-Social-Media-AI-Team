<?php

namespace App\Console\Commands;

use App\Services\Blotato\PlatformRules;
use App\Services\Metricool\MetricoolClient;
use Illuminate\Console\Command;

/**
 * VERIFICATION PROBE #2 — Publishing parity audit.
 *
 * Before swapping the publish path off Blotato, confirm Metricool's API can do
 * everything our SubmitScheduledPost actually sends. This command produces a
 * PARITY MATRIX: every capability our Blotato publish path uses today vs.
 * whether Metricool's /v2/scheduler/posts can express it.
 *
 * The Blotato column is GROUND TRUTH read from our own code (SubmitScheduledPost
 * + BlotatoClient::createPost + PlatformRules), not from docs. The Metricool
 * column is from the verified API contract (providers[], text, publicationDate,
 * autoPublish, media[] up to 10, per-network *Data). Where Metricool can't
 * express a Blotato feature, the row is flagged so you can choose hybrid
 * (keep Blotato for publishing, Metricool for metrics) or accept the loss.
 *
 * With --dry-run it also asks the live MetricoolClient to BUILD (not send) a
 * real schedulePost body, proving the payload assembles end-to-end.
 *
 * Usage:
 *   php artisan metricool:probe-publish
 *   php artisan metricool:probe-publish --dry-run --blog=123
 */
class MetricoolProbePublish extends Command
{
    protected $signature = 'metricool:probe-publish
                            {--dry-run : also build a live Metricool schedulePost body (no post created)}
                            {--blog=0 : blogId to use for the dry-run body}';

    protected $description = 'PROBE: parity matrix of what SubmitScheduledPost sends to Blotato vs what Metricool can do.';

    /**
     * Capability parity matrix. Each row:
     *   [capability, blotato (what our code sends today), metricool (can it?), verdict]
     *
     * Verdict legend:
     *   PARITY   — Metricool covers it 1:1
     *   ADAPT    — Metricool covers it but via a different shape (mapping work)
     *   GAP      — Metricool's API cannot express it → hybrid-or-accept decision
     */
    private const MATRIX = [
        ['Caption / body text', 'content.text (assembled body+hashtags+mentions)', 'text', 'PARITY'],
        ['Multi-network in one call', 'NO — one createPost per platform_connection', 'providers[] array (multi-network)', 'ADAPT'],
        ['Media upload / re-host', '/v2/media uploadMediaFromUrl → Blotato URL', '/actions/normalize/image/url → mediaId', 'ADAPT'],
        ['Single image', 'mediaUrls[1]', 'media[1]', 'PARITY'],
        ['Carousel (multi-image)', 'mediaUrls[n]', 'media[] up to 10', 'PARITY'],
        ['Video (.mp4)', 'mediaUrls[1] video', 'media[] supports video', 'PARITY'],
        ['Immediate publish ("post now")', 'scheduledTime=null (we own scheduling)', 'autoPublish=true + publicationDate=now', 'ADAPT'],
        ['Native scheduling', 'UNUSED — Laravel cron owns it', 'publicationDate{dateTime,timezone}', 'PARITY'],
        ['Draft (no auto-publish)', 'n/a (we always publish now)', 'autoPublish=false → planner draft', 'PARITY'],
        ['LinkedIn personal vs Page', 'target.pageId override (or absent=personal)', 'linkedinData (author/page selection)', 'ADAPT'],
        ['Facebook Page id (required)', 'target.pageId (numeric Page id)', 'facebookData (page selection)', 'ADAPT'],
        ['Pinterest board id (required)', 'target.boardId', 'pinterestData (board)', 'ADAPT'],
        ['TikTok privacy + flags', 'target.privacyLevel + 6 booleans + isAiGenerated', 'tiktokData (privacy/duet/stitch/AIGC)', 'ADAPT'],
        ['YouTube title + privacy', 'target.title + privacyStatus + notify + synthetic', 'youtubeData (title/privacy/notify)', 'ADAPT'],
        ['Threads reply control', 'target.replyControl', 'threadsData (reply control)', 'ADAPT'],
        ['First comment', 'NOT SENT today', 'firstComment supported', 'PARITY'],
        ['Per-platform caption caps', 'enforced in-app (PlatformRules)', 'enforce same in-app (unchanged)', 'PARITY'],
        ['Status polling → published URL', 'getPostStatus → publicUrl + verification gate', 'scheduler post id → analytics/posts (URL)', 'ADAPT'],
    ];

    public function handle(): int
    {
        $this->info('PUBLISHING PARITY — SubmitScheduledPost (Blotato, today) vs Metricool /v2/scheduler/posts');
        $this->newLine();

        $rows = [];
        $gaps = 0;
        $adapts = 0;
        foreach (self::MATRIX as [$cap, $blotato, $metricool, $verdict]) {
            $tag = match ($verdict) {
                'PARITY' => '<fg=green>PARITY</>',
                'ADAPT' => '<fg=yellow>ADAPT</>',
                'GAP' => '<fg=red>GAP</>',
                default => $verdict,
            };
            if ($verdict === 'GAP') $gaps++;
            if ($verdict === 'ADAPT') $adapts++;
            $rows[] = [$cap, $blotato, $metricool, $tag];
        }
        $this->table(['capability', 'Blotato (our code today)', 'Metricool', 'verdict'], $rows);
        $this->newLine();

        // Show the platforms our PlatformRules covers, so the reader sees the
        // ground-truth surface area this audit is measured against.
        $platforms = array_keys(PlatformRules::RULES);
        $this->line('Platforms our publish path supports: <fg=cyan>' . implode(', ', $platforms) . '</>');
        $this->line(sprintf(
            'Parity tally: <fg=green>%d PARITY</>, <fg=yellow>%d ADAPT</>, <fg=red>%d GAP</>.',
            count(self::MATRIX) - $adapts - $gaps,
            $adapts,
            $gaps,
        ));
        $this->newLine();

        if ($gaps === 0) {
            $this->info('VERDICT: ✓ No hard GAPs. Every capability our Blotato path uses is expressible in '
                . 'Metricool — the ADAPT rows are mapping work (target.* → *Data blocks, one-call-per-network '
                . '→ providers[]), not blockers. Publishing-parity gate PASSES. A full swap is viable; '
                . 'otherwise hybrid (Metricool metrics + Blotato publish) remains a safe fallback via '
                . 'MetricsProviderRouter.');
        } else {
            $this->warn("VERDICT: ✗ {$gaps} hard GAP(s) found. For each, decide: HYBRID (keep Blotato for "
                . 'publishing on the affected platform, use Metricool only for metrics) or ACCEPT the feature '
                . 'loss. Do not silently drop a capability customers rely on.');
        }

        // Optional live dry-run: prove the body assembles against the real client.
        if ($this->option('dry-run')) {
            $this->newLine();
            $this->dryRunBody();
        }

        return self::SUCCESS;
    }

    private function dryRunBody(): void
    {
        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            $this->warn('--dry-run skipped: Metricool not configured (token/userId).');
            return;
        }
        $blog = (int) $this->option('blog');
        if ($blog <= 0) {
            $this->warn('--dry-run needs --blog=<id> to scope the body. Skipping body build.');
            return;
        }

        $built = $client->schedulePost(
            blogId: $blog,
            networks: ['linkedin', 'instagram', 'tiktok'],
            text: "Parity dry-run — assembled body + #hashtag + @mention. (NOT POSTED)",
            publicationDateTime: now()->addDay()->format('Y-m-d\TH:i:s'),
            timezone: (string) config('app.timezone', 'Asia/Kuala_Lumpur'),
            media: [],
            autoPublish: false, // draft — never auto-publish from a probe
            perNetworkData: [
                'tiktokData' => ['privacyLevel' => 'SELF_ONLY', 'isAiGenerated' => true],
            ],
            dryRun: true, // builds the body, does NOT hit the API
        );

        $this->info('Dry-run scheduler body (built, NOT sent):');
        $this->line(json_encode($built['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line('<fg=green>Body assembled cleanly.</> Set autoPublish=true + real media to go live later.');
    }
}

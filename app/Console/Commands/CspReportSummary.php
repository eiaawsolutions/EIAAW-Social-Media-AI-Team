<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Aggregate CSP violation reports from `storage/logs/csp-*.log` and decide
 * whether it's safe to flip `CSP_ENFORCE=true`.
 *
 * Usage:
 *   php artisan csp:report-summary           — last 7 days
 *   php artisan csp:report-summary --days=14
 *   php artisan csp:report-summary --since=2026-04-25
 *
 * Reports the top violated directives, top blocked URIs, and a
 * recommendation. The signal that "you're ready to enforce" is:
 *   - zero violations from your own origin (everything reported is from
 *     extensions / random scanners hitting endpoints you don't own), AND
 *   - any remaining violations look like browser quirks or noise.
 */
class CspReportSummary extends Command
{
    protected $signature = 'csp:report-summary
        {--days=7 : look back N days from today}
        {--since= : explicit start date (YYYY-MM-DD), overrides --days}
    ';

    protected $description = 'Summarise CSP violation reports and recommend whether to enforce.';

    public function handle(): int
    {
        $logDir = storage_path('logs');
        $files  = glob($logDir.'/csp-*.log') ?: [];
        if (empty($files)) {
            $this->info('No CSP report logs found in '.$logDir.'.');
            $this->line('If the report endpoint is wired correctly, browsers will start populating this once a violation occurs in the wild. Until you see entries, treat the policy as untested.');
            return self::SUCCESS;
        }

        $since = $this->option('since')
            ? \DateTimeImmutable::createFromFormat('Y-m-d', $this->option('since'))
            : (new \DateTimeImmutable())->modify('-'.((int) $this->option('days')).' days');

        if (! $since) {
            $this->error('Could not parse --since/--days into a date.');
            return self::FAILURE;
        }

        $byDirective = [];
        $byBlocked   = [];
        $byDocument  = [];
        $total       = 0;
        $malformed   = 0;

        foreach ($files as $file) {
            $fh = fopen($file, 'r');
            if (! $fh) continue;
            while (($line = fgets($fh)) !== false) {
                // Each line: `[YYYY-MM-DD HH:MM:SS] env.LEVEL: csp.report {json}`
                if (! preg_match('/^\[(?<ts>\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})\][^{]+(?<json>\{.*\})\s*$/', $line, $m)) {
                    continue;
                }
                $ts = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', str_replace('T', ' ', $m['ts']))
                    ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $m['ts']);
                if (! $ts || $ts < $since) continue;

                $payload = json_decode($m['json'], true);
                if (! is_array($payload)) { $malformed++; continue; }

                $total++;
                $dir = $payload['effective-directive']
                    ?? $payload['violated-directive']
                    ?? 'unknown';
                $byDirective[$dir] = ($byDirective[$dir] ?? 0) + 1;

                if (! empty($payload['blocked-uri'])) {
                    $b = (string) $payload['blocked-uri'];
                    $byBlocked[$b] = ($byBlocked[$b] ?? 0) + 1;
                }
                if (! empty($payload['document-uri'])) {
                    $d = (string) $payload['document-uri'];
                    $byDocument[$d] = ($byDocument[$d] ?? 0) + 1;
                }
            }
            fclose($fh);
        }

        $this->newLine();
        $this->info("CSP report summary — since {$since->format('Y-m-d')}");
        $this->line("Total violations: {$total}");
        if ($malformed > 0) {
            $this->line("Malformed entries skipped: {$malformed}");
        }

        $this->renderBreakdown('Top violated directives', $byDirective);
        $this->renderBreakdown('Top blocked URIs', $byBlocked);
        $this->renderBreakdown('Top affected pages', $byDocument);

        $this->newLine();
        $this->recommend($total, $byDirective, $byBlocked);

        return self::SUCCESS;
    }

    /** @param array<string,int> $counts */
    private function renderBreakdown(string $title, array $counts, int $limit = 10): void
    {
        if (empty($counts)) return;
        arsort($counts);
        $rows = array_slice($counts, 0, $limit, preserve_keys: true);
        $this->newLine();
        $this->line("<options=bold>{$title}</> (top ".count($rows)."):");
        foreach ($rows as $key => $n) {
            $this->line(sprintf('  %-6d  %s', $n, $key));
        }
    }

    /**
     * @param array<string,int> $byDirective
     * @param array<string,int> $byBlocked
     */
    private function recommend(int $total, array $byDirective, array $byBlocked): void
    {
        if ($total === 0) {
            $this->info('Recommendation: SAFE TO ENFORCE.');
            $this->line('No violations reported in the window. Set CSP_ENFORCE=true on production and watch the log for one more day before declaring victory.');
            return;
        }

        // Heuristic: if every blocked URI is on your own origin, the policy
        // is probably too tight (you're blocking your own resources). If
        // they're all third-party / scanner noise, the policy is correct.
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST) ?: '';
        $selfBlocked = 0;
        foreach ($byBlocked as $uri => $n) {
            if ($appHost && str_contains($uri, $appHost)) {
                $selfBlocked += $n;
            }
        }

        if ($selfBlocked > 0) {
            $this->warn("Recommendation: NOT YET. {$selfBlocked} violation(s) involve your own origin ({$appHost}) — flipping CSP_ENFORCE will break those resources for real users.");
            $this->line('Investigate the entries above, expand the policy where they are legitimate, then re-run.');
            return;
        }

        $this->info('Recommendation: LIKELY SAFE.');
        $this->line('All blocked URIs appear to be third-party (extensions, scanners, embedded widgets). Consider flipping CSP_ENFORCE=true after a quick spot-check of the entries above.');
    }
}

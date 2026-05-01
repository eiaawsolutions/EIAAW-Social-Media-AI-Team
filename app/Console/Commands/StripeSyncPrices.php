<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * StripeSyncPrices — create/verify the 6 recurring Prices that EIAAW SMT
 * bills against (Solo / Studio / Agency × monthly + annual).
 *
 * Mirrors the employee-portal pattern. Idempotent via lookup_key on Prices.
 * Refuses to mutate a live (sk_live_) account without --apply AND
 * --i-know-this-is-live. After --apply, prints the .env block of price IDs
 * to paste into Infisical (eiaaw-smt-prod / prod / STRIPE_PRICE_*).
 *
 * Per the EIAAW Deploy Contract, raw price IDs should NEVER be pasted into
 * Railway env directly. The expected flow is:
 *   1. Run this command against the LIVE key with --emit-env
 *   2. Copy the printed STRIPE_PRICE_* lines into Infisical at the
 *      eiaaw-smt-prod/prod path
 *   3. Reference them via secret://... handles in .env.example
 */
class StripeSyncPrices extends Command
{
    protected $signature = 'stripe:sync-prices
        {--dry-run : Report what would be created, no writes (default for live keys)}
        {--apply : Actually create/update Stripe objects}
        {--i-know-this-is-live : Required alongside --apply when STRIPE_SECRET is a live key}
        {--emit-env : After sync, print the .env block to paste into Infisical}';

    protected $description = 'Create (or verify) the 6 Stripe Prices that back EIAAW SMT subscriptions.';

    private const PRODUCT_NAME_PREFIX = 'EIAAW Social Media Team — ';
    private const METADATA_OWNER = 'eiaaw-smt-sync';

    /**
     * 3 tiers × monthly/annual = 6 Prices. MYR-denominated to match the
     * landing-page pricing (RM 99 / 299 / 799). Annual price = 10× monthly
     * (i.e. 2 months free); change here if the policy shifts.
     */
    private const TIERS = [
        'solo' => [
            'name' => 'Solo',
            'tagline' => 'For founders running their own brand.',
            'monthly_myr' => 99,
        ],
        'studio' => [
            'name' => 'Studio',
            'tagline' => 'For freelancers and small studios. White-label included.',
            'monthly_myr' => 299,
        ],
        'agency' => [
            'name' => 'Agency',
            'tagline' => 'For agencies with full client portal + per-client guardrail isolation.',
            'monthly_myr' => 799,
        ],
    ];

    private const ANNUAL_FACTOR = 10; // 12 - 2 months free

    public function handle(): int
    {
        $secret = env('STRIPE_SECRET');
        if (empty($secret)) {
            $this->error('STRIPE_SECRET is not set.');
            return self::FAILURE;
        }

        $isLive = str_starts_with($secret, 'sk_live_');
        $apply = (bool) $this->option('apply');
        $dryRun = (bool) $this->option('dry-run') || ! $apply;

        if ($isLive && $apply && ! $this->option('i-know-this-is-live')) {
            $this->error('Refusing to write to a LIVE Stripe account without --i-know-this-is-live.');
            return self::FAILURE;
        }

        $mode = $isLive ? 'LIVE' : 'test';
        $action = $dryRun ? '[dry-run] ' : '';
        $this->info("{$action}Syncing Stripe Prices against {$mode} account.");

        $envLines = [];

        foreach (self::TIERS as $tierKey => $tier) {
            $this->line("→ Tier {$tier['name']}");

            $product = $this->ensureProduct($secret, $tierKey, $tier, $dryRun);
            if (! $product) {
                $this->error("  failed to ensure product for {$tierKey}");
                return self::FAILURE;
            }

            foreach (['monthly', 'annual'] as $period) {
                $unit = $period === 'monthly'
                    ? $tier['monthly_myr']
                    : ($tier['monthly_myr'] * self::ANNUAL_FACTOR);
                $interval = $period === 'monthly' ? 'month' : 'year';
                $lookupKey = "eiaaw_smt_{$tierKey}_myr_{$period}";

                $price = $this->ensurePrice(
                    secret: $secret,
                    productId: $product['id'],
                    lookupKey: $lookupKey,
                    unitAmount: $unit * 100, // smallest currency unit (sen)
                    currency: 'myr',
                    interval: $interval,
                    dryRun: $dryRun,
                );

                $envKey = strtoupper("STRIPE_PRICE_{$tierKey}_MYR_{$period}");
                $priceId = $price['id'] ?? 'price_(would-create)';
                $amount = number_format($unit, 0);
                $this->line("    {$envKey}={$priceId}  (MYR {$amount} / {$interval})");
                $envLines[$envKey] = $priceId;
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->comment('Dry run complete. Re-run with --apply to actually create the objects.');
        } else {
            $this->info('Sync complete. Save these IDs to Infisical at eiaaw-smt-prod / prod:');
        }

        if ($this->option('emit-env') || ! $dryRun) {
            $this->newLine();
            $this->line('# ── Stripe Price IDs (paste into Infisical) ──');
            foreach ($envLines as $k => $v) {
                $this->line("{$k}={$v}");
            }
        }

        return self::SUCCESS;
    }

    private function ensureProduct(string $secret, string $tierKey, array $tier, bool $dryRun): ?array
    {
        $name = self::PRODUCT_NAME_PREFIX . $tier['name'];

        $search = $this->request($secret, 'GET', 'products/search', [
            'query' => "name:'{$name}' AND active:'true'",
            'limit' => 1,
        ]);

        if ($search->successful() && ! empty($search['data'])) {
            $existing = $search['data'][0];
            $this->line("  product exists: {$existing['id']}");
            return $existing;
        }

        if ($dryRun) {
            $this->line("  product would be CREATED: {$name}");
            return ['id' => 'prod_(would-create)'];
        }

        $created = $this->request($secret, 'POST', 'products', [
            'name' => $name,
            'description' => $tier['tagline'] ?? '',
            'metadata[owner]' => self::METADATA_OWNER,
            'metadata[tier]' => $tierKey,
        ]);

        if (! $created->successful()) {
            $this->error('  product create failed: ' . $created->body());
            return null;
        }

        $this->line("  product created: {$created['id']}");
        return $created->json();
    }

    private function ensurePrice(
        string $secret,
        string $productId,
        string $lookupKey,
        int $unitAmount,
        string $currency,
        string $interval,
        bool $dryRun,
    ): array {
        $existing = $this->request($secret, 'GET', 'prices', [
            'lookup_keys[]' => $lookupKey,
            'active' => 'true',
            'limit' => 1,
        ]);

        if ($existing->successful() && ! empty($existing['data'])) {
            $p = $existing['data'][0];
            if ($p['unit_amount'] !== $unitAmount || $p['currency'] !== $currency) {
                $this->warn("    ! price {$lookupKey} EXISTS but amount/currency differs ({$p['unit_amount']}/{$p['currency']} vs {$unitAmount}/{$currency}) — Stripe does not allow editing; archive + recreate manually");
            }
            return $p;
        }

        if ($dryRun) {
            return ['id' => 'price_(would-create)', 'lookup_key' => $lookupKey];
        }

        $created = $this->request($secret, 'POST', 'prices', [
            'product' => $productId,
            'currency' => $currency,
            'unit_amount' => $unitAmount,
            'lookup_key' => $lookupKey,
            'recurring[interval]' => $interval,
            'billing_scheme' => 'per_unit',
            'metadata[owner]' => self::METADATA_OWNER,
        ]);

        if (! $created->successful()) {
            $this->error("    price create failed ({$lookupKey}): " . $created->body());
            return ['id' => null];
        }

        return $created->json();
    }

    private function request(string $secret, string $method, string $path, array $params = []): Response
    {
        $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
        $client = Http::withBasicAuth($secret, '')
            ->timeout(15)
            ->acceptJson();

        if ($method === 'GET') {
            return $client->get($url, $params);
        }
        return $client->asForm()->post($url, $params);
    }
}

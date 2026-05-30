<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Services\Metricool\MetricoolClient;
use App\Services\Metricool\MetricoolConnectionService;
use Illuminate\Console\Command;

/**
 * brand:set-metricool-blog — map an SMT brand to a Metricool brand (blogId),
 * the Metricool analogue of workspace:set-blotato-handle.
 *
 * Unlike the Blotato handle, the blogId is NOT a secret — it's an
 * account-scoped numeric id that pairs with the single shared token. So it's a
 * plain argument, no Infisical handle. One shared Metricool account holds every
 * brand ([[metricool-multitenancy]]); isolation is by always scoping calls to
 * the right blogId.
 *
 * Typical flow:
 *   1. Operator creates the brand in Metricool (or it already exists), notes
 *      its blogId.
 *   2. php artisan brand:set-metricool-blog 10 6322515
 *   3. Customer connects their socials via the Metricool share-link.
 *   4. php artisan brand:set-metricool-blog 10 --detect
 *      (or the wizard's "Check connection" button) → mirrors connected networks
 *      into platform_connections and stamps metricool_connected_at.
 *
 * Usage:
 *   php artisan brand:set-metricool-blog 10 6322515         # map
 *   php artisan brand:set-metricool-blog 10 --detect        # detect + sync connections
 *   php artisan brand:set-metricool-blog 10 --mark-link-sent # record the connect-link was shared
 *   php artisan brand:set-metricool-blog 10 --clear         # unmap
 *   php artisan brand:set-metricool-blog --list             # list brands needing mapping
 */
class BrandSetMetricoolBlog extends Command
{
    protected $signature = 'brand:set-metricool-blog
                            {brand? : Brand ID}
                            {blogId? : Metricool blogId (numeric, NOT a secret)}
                            {--detect : Read /admin/profile and sync connected networks into platform_connections}
                            {--mark-link-sent : Stamp metricool_connect_link_sent_at (the share-link was given to the customer)}
                            {--clear : Unmap the brand from Metricool}
                            {--list : List brands and their Metricool mapping/connection state}';

    protected $description = 'Map an SMT brand to a Metricool blogId and detect connected networks (operator-only).';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listBrands();
        }

        $brandId = (int) $this->argument('brand');
        if ($brandId <= 0) {
            $this->error('Brand ID is required (or use --list).');
            return self::FAILURE;
        }
        $brand = Brand::find($brandId);
        if (! $brand) {
            $this->error("Brand #{$brandId} not found.");
            return self::FAILURE;
        }

        if ($this->option('clear')) {
            $brand->update([
                'metricool_blog_id' => null,
                'metricool_connect_link_sent_at' => null,
                'metricool_connected_at' => null,
            ]);
            $this->info("Brand #{$brandId} unmapped from Metricool.");
            return self::SUCCESS;
        }

        if ($this->option('mark-link-sent')) {
            if (empty($brand->metricool_blog_id)) {
                $this->error('Map a blogId first before marking the connect-link sent.');
                return self::FAILURE;
            }
            $brand->update(['metricool_connect_link_sent_at' => now()]);
            $this->info("Brand #{$brandId}: connect-link marked sent.");
            return self::SUCCESS;
        }

        // Map a blogId if one was provided.
        $blogId = $this->argument('blogId');
        if ($blogId !== null) {
            if (! ctype_digit((string) $blogId)) {
                $this->error("blogId must be numeric (got '{$blogId}').");
                return self::FAILURE;
            }
            $brand->update(['metricool_blog_id' => (string) $blogId]);
            $this->info("Brand #{$brandId} ({$brand->name}) mapped to Metricool blogId {$blogId}.");
        }

        // Detect + sync connections.
        if ($this->option('detect')) {
            return $this->detect($brand);
        }

        $this->line('State: ' . $brand->fresh()->metricoolSetupState());
        return self::SUCCESS;
    }

    private function detect(Brand $brand): int
    {
        if (empty($brand->metricool_blog_id)) {
            $this->error('Brand has no metricool_blog_id. Map one first.');
            return self::FAILURE;
        }

        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            $this->warn('Metricool not configured (token/userId). Cannot detect.');
            return self::SUCCESS;
        }

        $service = new MetricoolConnectionService($client);
        $result = $service->sync($brand);

        if ($result['synced'] === 0 && empty($result['networks'])) {
            $this->warn('No connected networks found yet. Has the customer connected via the share-link?');
            $this->line('State: ' . $brand->fresh()->metricoolSetupState());
            return self::SUCCESS;
        }

        // First successful detection stamps connected_at.
        if ($brand->metricool_connected_at === null) {
            $brand->update(['metricool_connected_at' => now()]);
        }

        $this->info(sprintf(
            'Synced %d connection(s)%s: %s',
            $result['synced'],
            $result['revoked'] > 0 ? " ({$result['revoked']} revoked)" : '',
            implode(', ', $result['networks']),
        ));
        $this->line('State: ' . $brand->fresh()->metricoolSetupState());
        return self::SUCCESS;
    }

    private function listBrands(): int
    {
        $brands = Brand::query()->orderBy('id')->get(['id', 'name', 'metricool_blog_id', 'metricool_connected_at']);
        if ($brands->isEmpty()) {
            $this->warn('No brands.');
            return self::SUCCESS;
        }
        $rows = $brands->map(fn (Brand $b) => [
            $b->id,
            $b->name,
            $b->metricool_blog_id ?: '—',
            $b->metricoolSetupState(),
        ])->all();
        $this->table(['id', 'brand', 'blogId', 'state'], $rows);
        return self::SUCCESS;
    }
}

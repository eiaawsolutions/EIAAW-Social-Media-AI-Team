<?php

namespace App\Jobs;

use App\Models\Brand;
use App\Services\Metricool\AccountGrowthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Warms the account-growth cache OFF the web request.
 *
 * The /agency/performance page used to call AccountGrowthService::forBrand()
 * synchronously — up to ~13 serial Metricool HTTP calls (7 networks × followers
 * timeline + post analytics, 6s each, 18s budget) INSIDE the web request. On a
 * single-worker web tier that pinned the one request slot for up to 18s and
 * everyone else saw "nonstop loading" (see memory prod_web_is_artisan_serve_dev_server).
 *
 * Now the page reads AccountGrowthService::cachedForBrand() — cache-hit or a
 * 'warming' scaffold — and this job does the blocking pull on the worker fleet
 * (the 'metrics' queue the worker already consumes), writing the SAME
 * metricool:growth:{blogId}:{window} cache key the page reads. The web request
 * never touches Metricool.
 *
 * Idempotent: forBrand() is itself Cache::remember, so if the cache is already
 * warm when this runs, the pull is skipped. tries=1 mirrors the worker's
 * --tries=1; a failed pull just leaves the page in its honest 'warming'/degraded
 * state until the next dispatch. Dispatched by id (never serialize a whole model).
 */
class RefreshAccountGrowthJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Well under the worker's --timeout=330; the pull has its own 18s budget. */
    public int $timeout = 120;

    public function __construct(
        public int $brandId,
        public int $windowDays = 30,
    ) {
    }

    public function handle(AccountGrowthService $growth): void
    {
        $brand = Brand::find($this->brandId);

        // Brand deleted / unmapped since dispatch → nothing to warm.
        if ($brand === null || empty($brand->metricool_blog_id)) {
            $this->releaseLock((int) ($brand->metricool_blog_id ?? 0));

            return;
        }

        try {
            // Synchronous pull on the WORKER (not the web request). forBrand()
            // is Cache::remember, so this populates metricool:growth:{blogId}:{window}
            // — exactly the key cachedForBrand() serves the page from.
            $growth->forBrand($brand, $this->windowDays);
        } finally {
            $this->releaseLock((int) $brand->metricool_blog_id);
        }
    }

    /** Free the stampede guard so the next miss / manual refresh can re-dispatch. */
    private function releaseLock(int $blogId): void
    {
        if ($blogId > 0) {
            Cache::forget(AccountGrowthService::refreshLockKey($blogId, $this->windowDays));
        }
    }
}

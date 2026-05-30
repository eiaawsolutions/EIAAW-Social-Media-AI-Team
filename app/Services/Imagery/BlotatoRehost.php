<?php

namespace App\Services\Imagery;

use App\Models\Brand;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Support\Facades\Log;

/**
 * Soft-failing helper that re-hosts an external media URL on a brand's
 * workspace-scoped Blotato account. Mirrors the re-host step in
 * DesignerAgent's library-pick path, extracted so CustomisedPostScheduler can
 * reuse it (and so it can be swapped/faked in tests).
 *
 * Returns the Blotato-scoped URL, or null on any failure — callers fall back
 * to the original URL. SubmitScheduledPost re-uploads media at publish time
 * regardless, so a null here only loses the up-front warm-up, never the post.
 */
class BlotatoRehost
{
    public function forBrand(Brand $brand, string $url): ?string
    {
        $workspace = $brand->workspace;
        if (! $workspace) {
            return null;
        }

        try {
            return BlotatoClient::forWorkspace($workspace)->uploadMediaFromUrl($url);
        } catch (\Throwable $e) {
            Log::warning('BlotatoRehost: re-host failed; using source URL', [
                'brand_id' => $brand->id,
                'workspace_id' => $brand->workspace_id,
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}

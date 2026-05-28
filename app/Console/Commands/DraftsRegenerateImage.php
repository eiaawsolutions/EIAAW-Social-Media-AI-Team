<?php

namespace App\Console\Commands;

use App\Agents\DesignerAgent;
use App\Agents\VideoAgent;
use App\Models\Draft;
use Illuminate\Console\Command;

/**
 * Regenerate a draft's visual from the current scripted-content scene brief.
 *
 * The Designer/Video agents are idempotent — they no-op if a draft already
 * has an asset_url. So after a prompt/routing improvement, existing drafts
 * keep their stale (or generic library) image. This command clears the asset
 * and forces a fresh generation down the FAL path (force_fal => true), which
 * is the path that runs DraftSceneBrief — so the new visual depicts what the
 * post actually says.
 *
 * Examples:
 *   php artisan drafts:regenerate-image 248
 *   php artisan drafts:regenerate-image 248 --video
 *   php artisan drafts:regenerate-image 248 --dry-run
 *   php artisan drafts:regenerate-image 248 --force   # skip the confirm prompt
 */
class DraftsRegenerateImage extends Command
{
    protected $signature = 'drafts:regenerate-image
                            {id : the Draft id to regenerate the visual for}
                            {--video : regenerate a video (Wan) instead of a still image (Flux)}
                            {--force : skip the confirmation prompt (for non-interactive/prod use)}
                            {--dry-run : show what would happen without clearing or generating}';

    protected $description = 'Clear a draft\'s asset and regenerate it from the scripted scene brief via FAL (image or --video).';

    public function handle(): int
    {
        $id = (int) $this->argument('id');
        $isVideo = (bool) $this->option('video');
        $dry = (bool) $this->option('dry-run');
        $mediaWord = $isVideo ? 'video' : 'image';

        $draft = Draft::find($id);
        if (! $draft) {
            $this->error("Draft #{$id} not found.");

            return self::FAILURE;
        }

        $brand = $draft->brand;
        if (! $brand) {
            $this->error("Draft #{$id} has no brand — cannot regenerate.");

            return self::FAILURE;
        }

        $this->line("Draft #{$draft->id} ({$draft->platform}) — brand: {$brand->name}");
        $this->line('Current asset: '.($draft->asset_url ? $draft->asset_url : '(none)'));
        $this->line("Will regenerate: {$mediaWord} (force_fal — from the scripted scene brief)");

        if ($dry) {
            $this->info('[dry-run] No changes made.');

            return self::SUCCESS;
        }

        if (! $this->option('force')
            && ! $this->confirm("Clear the current asset and regenerate the {$mediaWord} now?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        // Preserve the old asset in history, then clear the primary so the
        // idempotent agent actually regenerates rather than no-op'ing.
        $history = is_array($draft->asset_urls) ? $draft->asset_urls : [];
        if ($draft->asset_url && ! in_array($draft->asset_url, $history, true)) {
            $history[] = $draft->asset_url;
        }
        $draft->update([
            'asset_url' => null,
            'asset_urls' => array_values($history),
        ]);

        /** @var DesignerAgent|VideoAgent $agent */
        $agent = app($isVideo ? VideoAgent::class : DesignerAgent::class);

        $result = $agent->run($brand, [
            'draft_id' => $draft->id,
            'force_fal' => true,
        ]);

        if (! $result->ok) {
            $this->error("Regeneration failed: {$result->errorMessage}");

            return self::FAILURE;
        }

        $fresh = $draft->fresh();
        $this->info("Regenerated {$mediaWord} for draft #{$draft->id}.");
        $this->line('New asset:   '.($fresh->asset_url ?? '(none)'));
        $this->line('Source:      '.($result->data['source'] ?? 'unknown'));
        if (isset($result->data['cost_usd'])) {
            $this->line('Cost (USD):  '.$result->data['cost_usd']);
        }
        if (! empty($result->data['prompt'])) {
            $this->line('');
            $this->line('--- prompt sent to FAL (verify it reflects the post) ---');
            $this->line($result->data['prompt']);
        }

        return self::SUCCESS;
    }
}

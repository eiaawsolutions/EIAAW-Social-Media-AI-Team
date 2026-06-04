<?php

use App\Services\Billing\PlanCaps;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grandfather existing subscribers onto the caps that were live BEFORE the
 * 2026-06-04 allowance tightening (Solo 60→25 image / 5→4 video, Studio
 * 180→75 / 15→12, Agency 12 brands·720·60 → 10·300·48).
 *
 * WHY A DATA MIGRATION
 * --------------------
 * The new (lower) caps live in config/billing.php and PlanCaps reads them live
 * UNLESS a per-workspace snapshot exists (workspaces.settings.plan_caps_snapshot).
 * Without this backfill, every existing paying workspace would silently drop to
 * the new lower allowance the moment the config deploy lands — including the live
 * Bear Hug Solo workspace (it had 60 image / 5 video). The locked product
 * decision (2026-06-04) is to grandfather: existing subscribers keep what they
 * signed up for. So we stamp each existing workspace with a snapshot of the OLD
 * numbers here. New signups get the new numbers via SignupProvisioner.
 *
 * The OLD numbers are hard-coded below because config no longer holds them — this
 * migration is the historical record of "what the caps were on 2026-06-04".
 *
 * IDEMPOTENT + SAFE
 * -----------------
 *  - Only touches workspaces that do NOT already have a snapshot (re-runnable).
 *  - Skips eiaaw_internal + enterprise (unlimited — PlanCaps handles them without
 *    a snapshot; stamping one would be wrong if their config later changes).
 *  - Merges into existing settings JSON rather than overwriting it.
 *  - down() removes ONLY the snapshot key, leaving the rest of settings intact.
 */
return new class extends Migration
{
    /**
     * Caps that were live immediately BEFORE 2026-06-04. Keyed by plan slug.
     * published = image + video (the total publish ceiling).
     *
     * @var array<string, array<string,int>>
     */
    private array $oldCaps = [
        'solo' => [
            'max_brands' => 1,
            'max_ai_image_posts_per_month' => 60,
            'max_published_posts_per_month' => 65,   // 60 + 5
            'max_ai_videos_per_month' => 5,
        ],
        'studio' => [
            'max_brands' => 3,
            'max_ai_image_posts_per_month' => 180,
            'max_published_posts_per_month' => 195,  // 180 + 15
            'max_ai_videos_per_month' => 15,
        ],
        'agency' => [
            'max_brands' => 12,
            'max_ai_image_posts_per_month' => 720,
            'max_published_posts_per_month' => 780,  // 720 + 60
            'max_ai_videos_per_month' => 60,
        ],
    ];

    public function up(): void
    {
        $key = PlanCaps::SNAPSHOT_SETTINGS_KEY;

        DB::table('workspaces')
            ->whereIn('plan', array_keys($this->oldCaps))
            ->orderBy('id')
            ->each(function ($workspace) use ($key) {
                $settings = $this->decodeSettings($workspace->settings);

                // Idempotent: never overwrite an existing snapshot.
                if (isset($settings[$key]) && is_array($settings[$key])) {
                    return;
                }

                $settings[$key] = $this->oldCaps[$workspace->plan];

                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->update(['settings' => json_encode($settings)]);
            });
    }

    public function down(): void
    {
        $key = PlanCaps::SNAPSHOT_SETTINGS_KEY;

        DB::table('workspaces')
            ->whereIn('plan', array_keys($this->oldCaps))
            ->orderBy('id')
            ->each(function ($workspace) use ($key) {
                $settings = $this->decodeSettings($workspace->settings);

                if (! array_key_exists($key, $settings)) {
                    return;
                }

                unset($settings[$key]);

                DB::table('workspaces')
                    ->where('id', $workspace->id)
                    ->update(['settings' => $settings === [] ? null : json_encode($settings)]);
            });
    }

    /** @return array<string,mixed> */
    private function decodeSettings(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
};

<?php

namespace App\Console\Commands;

use App\Models\PlatformConnection;
use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use Illuminate\Console\Command;

/**
 * audit:blotato-leakage — non-destructive audit for cross-tenant
 * platform_connections rows that pre-date per-workspace Blotato isolation.
 *
 * Background: before 2026-05-27, every workspace synced against a single
 * shared BLOTATO_API_KEY. GET /v2/users/me/accounts returned every social
 * account connected to that one Blotato account, and PlatformSyncService
 * upserted ALL of them onto whichever brand asked. Result: a customer's
 * brand could have HQ's TikTok handle (or another customer's IG handle)
 * sitting in their platform_connections — wrong attribution, wrong
 * publishing target, wrong metrics.
 *
 * What this audit does:
 *   1. Lists every workspace with a Blotato handle configured.
 *   2. For each, fetches the legitimate set of blotato_account_ids from
 *      THAT workspace's Blotato account.
 *   3. Diffs against platform_connections rows owned by brands in that
 *      workspace — anything in the DB that's NOT in the legitimate set
 *      is flagged as leaked (probably pre-isolation sync residue).
 *   4. Prints a per-row report. With --mark-revoked, flips suspicious
 *      rows to status='revoked' (keeps the row for audit trail). With
 *      --delete, actually deletes them (last resort — breaks any
 *      ScheduledPost FKs).
 *
 * Default is dry-run. Operator reviews output, then re-runs with
 * --mark-revoked to clean up. Never auto-runs.
 *
 * Usage:
 *   php artisan audit:blotato-leakage                    # dry-run, all workspaces
 *   php artisan audit:blotato-leakage --workspace=42     # one workspace
 *   php artisan audit:blotato-leakage --mark-revoked     # actually revoke leaked rows
 */
class AuditBlotatoLeakage extends Command
{
    protected $signature = 'audit:blotato-leakage
                            {--workspace= : Audit a single workspace by ID}
                            {--mark-revoked : Flip leaked rows to status=revoked (safe — preserves FKs)}
                            {--delete : Hard-delete leaked rows (DANGEROUS — breaks ScheduledPost links)}';

    protected $description = 'Detect platform_connections rows that leaked across workspaces under the old shared-Blotato model.';

    public function handle(): int
    {
        $markRevoked = (bool) $this->option('mark-revoked');
        $hardDelete = (bool) $this->option('delete');
        if ($markRevoked && $hardDelete) {
            $this->error('Pass --mark-revoked OR --delete, not both.');
            return self::FAILURE;
        }

        $workspaceQ = Workspace::query()->whereNotNull('blotato_api_key_handle');
        if ($this->option('workspace')) {
            $workspaceQ->where('id', (int) $this->option('workspace'));
        }
        $workspaces = $workspaceQ->orderBy('id')->get();

        if ($workspaces->isEmpty()) {
            $this->warn('No workspaces with blotato_api_key_handle set. Nothing to audit.');
            return self::SUCCESS;
        }

        $totalChecked = 0;
        $totalLeaked = 0;
        $totalActioned = 0;

        foreach ($workspaces as $ws) {
            $this->newLine();
            $this->info("─── Workspace #{$ws->id} ({$ws->slug}) ───");

            try {
                $client = BlotatoClient::forWorkspace($ws);
            } catch (\Throwable $e) {
                $this->warn('  Skipped: ' . $e->getMessage());
                continue;
            }

            if (! $client->ping()) {
                $this->warn('  Skipped: Blotato ping failed.');
                continue;
            }

            try {
                $accounts = $client->listAccounts();
            } catch (\Throwable $e) {
                $this->warn('  Skipped: listAccounts failed — ' . $e->getMessage());
                continue;
            }

            $legitimateIds = array_filter(array_map(
                fn ($a) => (string) ($a['id'] ?? ''),
                $accounts,
            ));
            $this->line('  Legitimate Blotato account IDs: ' . count($legitimateIds));

            // Every active platform_connection row whose brand is in this
            // workspace — should ALL have a blotato_account_id in the
            // legitimate set. Anything else is residue from the pre-2026-05-27
            // shared-key era and shouldn't be there.
            $rows = PlatformConnection::query()
                ->whereHas('brand', fn ($b) => $b->where('workspace_id', $ws->id))
                ->where('status', 'active')
                ->whereNotNull('blotato_account_id')
                ->with('brand:id,name,workspace_id')
                ->get();

            $totalChecked += $rows->count();
            $leaked = $rows->filter(fn ($r) => ! in_array((string) $r->blotato_account_id, $legitimateIds, true));

            if ($leaked->isEmpty()) {
                $this->info("  ✓ Clean. {$rows->count()} active row(s), all match legitimate accounts.");
                continue;
            }

            $totalLeaked += $leaked->count();
            $this->warn("  ⚠ Found {$leaked->count()} leaked row(s) (of {$rows->count()} active):");
            foreach ($leaked as $row) {
                $this->line(sprintf(
                    '    PC#%d brand=%s platform=%s handle=%s blotato_id=%s',
                    $row->id,
                    $row->brand?->name ?? '?',
                    $row->platform,
                    $row->display_handle ?? '-',
                    $row->blotato_account_id,
                ));
            }

            if ($markRevoked) {
                $touched = PlatformConnection::query()
                    ->whereIn('id', $leaked->pluck('id'))
                    ->update(['status' => 'revoked']);
                $totalActioned += $touched;
                $this->info("  → Flipped {$touched} row(s) to status=revoked.");
            } elseif ($hardDelete) {
                $touched = PlatformConnection::query()
                    ->whereIn('id', $leaked->pluck('id'))
                    ->delete();
                $totalActioned += $touched;
                $this->info("  → Hard-deleted {$touched} row(s).");
            }
        }

        $this->newLine();
        $this->info('──────── Summary ────────');
        $this->info("Active rows checked: {$totalChecked}");
        $this->info("Leaked rows found:   {$totalLeaked}");
        if (! $markRevoked && ! $hardDelete) {
            $this->warn('DRY-RUN — no changes made. Re-run with --mark-revoked to clean up.');
        } else {
            $this->info("Rows actioned:       {$totalActioned}");
        }

        return self::SUCCESS;
    }
}

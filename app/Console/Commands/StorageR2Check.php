<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * storage:r2-check — end-to-end verifier that the Cloudflare R2 disk is wired
 * and actually serving objects publicly.
 *
 * Background ([[brand-asset-storage-ephemeral]]): brand-asset uploads land on
 * the disk ManageBrandAssets::resolvePreferredDisk() chooses — R2 when it's
 * configured, else the local `public` disk. On a stateless production container
 * the local disk is ephemeral (wiped every redeploy) so uploads silently
 * vanish. R2 is the only durable option. Wiring R2 has FIVE moving parts that
 * must ALL be right, and a single wrong one breaks previews:
 *
 *   1. r2.key / r2.secret  — resolved from Infisical handles (the credentials)
 *   2. r2.bucket           — the bucket name (also the on/off switch for
 *                            preferredDisk(); empty => falls back to `public`)
 *   3. r2.endpoint         — https://<account>.r2.cloudflarestorage.com (S3 API)
 *   4. r2.url              — the PUBLIC base url (custom domain in prod; the
 *                            rate-limited *.r2.dev is dev-only per Cloudflare)
 *
 * This command exercises the whole chain: config presence -> authenticated
 * put -> exists -> the generated public URL actually returns the bytes over
 * HTTP -> cleanup. It NEVER prints secret values (only lengths). Exits FAILURE
 * on any broken link so it can gate a deploy / health check.
 *
 * Usage:
 *   php artisan storage:r2-check          # verify the chain, exit non-zero on failure
 *   php artisan storage:r2-check --keep   # leave the probe object in place
 */
class StorageR2Check extends Command
{
    protected $signature = 'storage:r2-check {--keep : Do not delete the probe object after the check}';

    protected $description = 'Verify the Cloudflare R2 disk is configured and serving objects publicly end-to-end.';

    public function handle(): int
    {
        $this->info('─── 1) R2 config presence ───');

        $key      = (string) config('filesystems.disks.r2.key');
        $secret   = (string) config('filesystems.disks.r2.secret');
        $bucket   = (string) config('filesystems.disks.r2.bucket');
        $endpoint = (string) config('filesystems.disks.r2.endpoint');
        $publicUrl = (string) config('filesystems.disks.r2.url');

        // Credentials: presence only, never the value.
        $this->line('  r2.key       : ' . ($key !== '' ? 'present (len ' . strlen($key) . ')' : '✗ MISSING'));
        $this->line('  r2.secret    : ' . ($secret !== '' ? 'present (len ' . strlen($secret) . ')' : '✗ MISSING'));
        // Non-secret config: safe to echo, and you need to SEE these to debug.
        $this->line('  r2.bucket    : ' . ($bucket !== '' ? $bucket : '✗ MISSING'));
        $this->line('  r2.endpoint  : ' . ($endpoint !== '' ? $endpoint : '✗ MISSING'));
        $this->line('  r2.url       : ' . ($publicUrl !== '' ? $publicUrl : '✗ MISSING'));

        $missing = [];
        foreach (['r2.key' => $key, 'r2.secret' => $secret, 'r2.bucket' => $bucket, 'r2.endpoint' => $endpoint, 'r2.url' => $publicUrl] as $name => $val) {
            if ($val === '') {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            $this->newLine();
            $this->error('✗ FAIL — R2 is not fully configured. Missing: ' . implode(', ', $missing));
            $this->line('  Brand-asset uploads will fall back to the ephemeral local disk and vanish on redeploy.');
            $this->line('  Set the Infisical handles (r2.key/r2.secret) + R2_BUCKET/R2_ENDPOINT/R2_PUBLIC_URL.');
            return self::FAILURE;
        }

        // A stable probe path; varying only by PID so concurrent runs don't clash
        // (Math.random/Date are unavailable in some sandboxes — getmypid is fine here).
        $probePath = 'diag/r2-check-' . substr(md5((string) getmypid()), 0, 10) . '.txt';
        $payload = 'r2-check-ok';

        $this->newLine();
        $this->info('─── 2) Authenticated round-trip (put → exists) ───');
        try {
            Storage::disk('r2')->put($probePath, $payload);
            $exists = Storage::disk('r2')->exists($probePath);
            $this->line('  put     : ✓ wrote ' . $probePath);
            $this->line('  exists  : ' . ($exists ? '✓ object present' : '✗ object NOT found after put'));
            if (! $exists) {
                $this->error('✗ FAIL — wrote the object but it is not readable back. Check bucket name + credentials scope.');
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('✗ FAIL — authenticated put failed: ' . $e->getMessage());
            $this->line('  Usually: wrong endpoint, wrong credentials, or the credentials lack write scope on this bucket.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('─── 3) Public HTTP fetch (the bit that drives previews) ───');
        $url = Storage::disk('r2')->url($probePath);
        $this->line('  generated url : ' . $url);

        $httpOk = false;
        try {
            $resp = Http::timeout(15)->get($url);
            $code = $resp->status();
            $body = $resp->body();
            $this->line("  GET           : HTTP {$code} (" . strlen($body) . ' bytes)');
            $httpOk = $code === 200 && $body === $payload;
        } catch (\Throwable $e) {
            $this->line('  GET           : ✗ ' . $e->getMessage());
        }

        if (! $httpOk) {
            $this->error('✗ FAIL — the object is not publicly fetchable at its generated URL.');
            $this->line('  The bucket likely isn\'t public, or R2_PUBLIC_URL doesn\'t map to this bucket.');
            $this->line('  In prod use a custom domain bound to the bucket (the *.r2.dev URL is dev-only + rate-limited).');
            $this->cleanup($probePath);
            return self::FAILURE;
        }
        $this->line('  serving       : ✓ public URL returns the object');

        $this->cleanup($probePath);

        $this->newLine();
        $this->info('✓ PASS — R2 is configured, authenticated, and serving objects publicly. Brand-asset uploads are durable.');
        return self::SUCCESS;
    }

    private function cleanup(string $probePath): void
    {
        if ($this->option('keep')) {
            $this->line('  cleanup       : skipped (--keep), probe left at ' . $probePath);
            return;
        }
        try {
            Storage::disk('r2')->delete($probePath);
            $this->line('  cleanup       : ✓ probe object deleted');
        } catch (\Throwable $e) {
            $this->warn('  cleanup       : could not delete probe (' . $e->getMessage() . ') — remove ' . $probePath . ' manually');
        }
    }
}

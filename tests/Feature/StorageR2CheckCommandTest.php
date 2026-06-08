<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-free tests for `storage:r2-check` ([[brand-asset-storage-ephemeral]]).
 *
 * The command is the gate we run on prod the moment R2 secrets land, so its
 * pass/fail verdict must be trustworthy. We fake the r2 disk + HTTP so no real
 * Cloudflare call happens and no DB is touched.
 */
class StorageR2CheckCommandTest extends TestCase
{
    public function test_fails_fast_when_config_is_missing(): void
    {
        config([
            'filesystems.disks.r2.key' => '',
            'filesystems.disks.r2.secret' => '',
            'filesystems.disks.r2.bucket' => '',
            'filesystems.disks.r2.endpoint' => '',
            'filesystems.disks.r2.url' => '',
        ]);

        $this->artisan('storage:r2-check')
            ->expectsOutputToContain('R2 is not fully configured')
            ->assertExitCode(1);
    }

    public function test_passes_when_disk_round_trips_and_serves_publicly(): void
    {
        $this->configureR2();
        Storage::fake('r2');

        // The faked disk serves objects under a local URL; make that URL return
        // the exact bytes the command wrote, so the public-fetch leg passes.
        Http::fake(function ($request) {
            return Http::response('r2-check-ok', 200);
        });

        $this->artisan('storage:r2-check')
            ->expectsOutputToContain('PASS')
            ->assertExitCode(0);

        // Probe object cleaned up.
        $this->assertEmpty(Storage::disk('r2')->files('diag'));
    }

    public function test_fails_when_public_url_does_not_serve_the_object(): void
    {
        $this->configureR2();
        Storage::fake('r2');

        // Auth round-trip will pass (fake disk), but the public URL 404s — the
        // exact "bucket not public / wrong R2_PUBLIC_URL" failure mode.
        Http::fake(fn ($request) => Http::response('not found', 404));

        $this->artisan('storage:r2-check')
            ->expectsOutputToContain('not publicly fetchable')
            ->assertExitCode(1);
    }

    public function test_keep_option_leaves_probe_in_place(): void
    {
        $this->configureR2();
        Storage::fake('r2');
        Http::fake(fn ($request) => Http::response('r2-check-ok', 200));

        $this->artisan('storage:r2-check --keep')
            ->assertExitCode(0);

        $this->assertNotEmpty(Storage::disk('r2')->files('diag'));
    }

    private function configureR2(): void
    {
        config([
            'filesystems.disks.r2.key' => 'fake-key',
            'filesystems.disks.r2.secret' => 'fake-secret',
            'filesystems.disks.r2.bucket' => 'eiaaw-smt-prod',
            'filesystems.disks.r2.endpoint' => 'https://acct.r2.cloudflarestorage.com',
            'filesystems.disks.r2.url' => 'https://assets.eiaawsolutions.com',
        ]);
    }
}

<?php

namespace Tests\Unit;

use App\Filament\Agency\Resources\BrandAssets\Pages\ManageBrandAssets;
use Tests\TestCase;

/**
 * DB-free unit tests for the brand-asset storage selection + durability guard
 * ([[brand-asset-storage-ephemeral]]).
 *
 * The bug: uploads silently landed on the ephemeral local `public` disk in a
 * stateless production container (wiped each redeploy). These lock in:
 *   - preferredDisk picks R2 only when the bucket is configured, else `public`.
 *   - storageIsDurable treats object storage as durable always, and the local
 *     disk as durable ONLY outside production.
 *
 * No DB. We only flip config + the app environment.
 */
class BrandAssetStorageDurabilityTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        parent::tearDown();
    }

    public function test_preferred_disk_is_public_when_r2_bucket_unset(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        $this->assertSame('public', ManageBrandAssets::resolvePreferredDisk());

        config(['filesystems.disks.r2.bucket' => '']);
        $this->assertSame('public', ManageBrandAssets::resolvePreferredDisk());
    }

    public function test_preferred_disk_is_r2_when_bucket_configured(): void
    {
        config(['filesystems.disks.r2.bucket' => 'eiaaw-smt-prod']);
        $this->assertSame('r2', ManageBrandAssets::resolvePreferredDisk());
    }

    public function test_object_storage_is_always_durable(): void
    {
        // r2 + s3 both use the s3 driver.
        $this->assertTrue(ManageBrandAssets::storageIsDurable('r2'));
        $this->assertTrue(ManageBrandAssets::storageIsDurable('s3'));
    }

    public function test_local_public_disk_is_not_durable_in_production(): void
    {
        app()['env'] = 'production';
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertFalse(
            ManageBrandAssets::storageIsDurable('public'),
            'The local public disk must be treated as non-durable in production (Railway wipes it on redeploy).',
        );
    }

    public function test_local_public_disk_is_durable_outside_production(): void
    {
        $this->app->detectEnvironment(fn () => 'local');

        $this->assertTrue(
            ManageBrandAssets::storageIsDurable('public'),
            'Locally the public disk persists, so uploads there are fine.',
        );
    }
}

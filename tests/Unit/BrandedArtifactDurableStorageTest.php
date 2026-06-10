<?php

namespace Tests\Unit;

use App\Agents\BaseAgent;
use App\Models\Brand;
use App\Services\Llm\LlmGateway;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Locks down the durable-disk fix for branded post artifacts
 * ([[brand-asset-storage-ephemeral]] — the SECOND occurrence, on the
 * agent compositor path rather than the customer-upload path).
 *
 * The bug: DesignerAgent and VideoAgent published their branded image/video to
 * the LOCAL `public` disk and stored a hand-built `<APP_URL>/storage/branding/…`
 * URL on the draft. On Railway the public disk is ephemeral AND has no
 * `storage:link` symlink, so that URL returns 404 — the draft preview shows
 * "Media preview unavailable" and Metricool's normalize endpoint can't fetch it
 * at publish time either. Regenerating doesn't help: it re-writes the same
 * unreachable URL.
 *
 * The fix: branded artifacts go to the durable preferred disk (R2 when
 * configured, else public for local dev), and the public URL comes from the
 * disk driver (`Storage::disk($disk)->url()`) — which for R2 is the
 * `smt-assets.eiaawsolutions.com` custom domain, not the broken /storage/ path.
 *
 * Pure unit test — no DB, no network. We exercise BaseAgent::publishArtifact()
 * directly via reflection against fake Storage disks.
 */
class BrandedArtifactDurableStorageTest extends TestCase
{
    protected function tearDown(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        parent::tearDown();
    }

    /** Minimal concrete agent so we can reach the protected helper on BaseAgent. */
    private function agent(): BaseAgent
    {
        return new class($this->createMock(LlmGateway::class)) extends BaseAgent
        {
            public function role(): string
            {
                return 'test';
            }

            public function promptVersion(): string
            {
                return 'v0';
            }

            protected function handle(Brand $brand, array $input): \App\Agents\AgentResult
            {
                return \App\Agents\AgentResult::ok([]);
            }
        };
    }

    private function invokePublish(BaseAgent $agent, string $localPath, string $relativePath): string
    {
        $m = new ReflectionMethod($agent, 'publishArtifact');
        $m->setAccessible(true);

        return $m->invoke($agent, $localPath, $relativePath);
    }

    private function tempFile(string $bytes = 'fake-image-bytes'): string
    {
        $p = tempnam(sys_get_temp_dir(), 'artifact');
        file_put_contents($p, $bytes);

        return $p;
    }

    public function test_publishes_to_r2_and_returns_r2_public_url_when_configured(): void
    {
        config(['filesystems.disks.r2.bucket' => 'eiaaw-smt-prod']);
        // Pin a custom-domain URL on the faked disk so we can prove the returned
        // URL comes from the disk DRIVER (Storage::disk()->url()) and is NOT a
        // hand-built <APP_URL>/storage/branding/… path (the exact bug).
        Storage::fake('r2', ['url' => 'https://smt-assets.eiaawsolutions.com']);

        $local = $this->tempFile('branded-bytes');
        $url = $this->invokePublish($this->agent(), $local, 'branding/388-abc.jpg');

        // Bytes durably stored on R2, NOT the local public disk.
        Storage::disk('r2')->assertExists('branding/388-abc.jpg');
        $this->assertSame('branded-bytes', Storage::disk('r2')->get('branding/388-abc.jpg'));

        // The returned URL must come from the R2 disk driver (custom domain),
        // never a hand-built /storage/ path on APP_URL.
        $this->assertStringStartsWith('https://smt-assets.eiaawsolutions.com', $url);
        $this->assertStringContainsString('branding/388-abc.jpg', $url);

        @unlink($local);
    }

    public function test_falls_back_to_public_disk_for_local_dev_when_r2_unset(): void
    {
        config(['filesystems.disks.r2.bucket' => null]);
        Storage::fake('public');

        $local = $this->tempFile('dev-bytes');
        $url = $this->invokePublish($this->agent(), $local, 'branding/77-xyz.jpg');

        Storage::disk('public')->assertExists('branding/77-xyz.jpg');
        $this->assertStringContainsString('branding/77-xyz.jpg', $url);

        @unlink($local);
    }

    public function test_resolves_r2_when_bucket_configured_else_public(): void
    {
        config(['filesystems.disks.r2.bucket' => 'eiaaw-smt-prod']);
        $this->assertSame('r2', BaseAgent::durableArtifactDisk());

        config(['filesystems.disks.r2.bucket' => '']);
        $this->assertSame('public', BaseAgent::durableArtifactDisk());

        config(['filesystems.disks.r2.bucket' => null]);
        $this->assertSame('public', BaseAgent::durableArtifactDisk());
    }
}

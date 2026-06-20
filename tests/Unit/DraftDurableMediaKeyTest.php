<?php

namespace Tests\Unit;

use App\Models\Draft;
use Tests\TestCase;

/**
 * Locks Draft::durableMediaKey() — the safety gate behind the "Delete media"
 * action. A media URL only resolves to a storage key (and so becomes
 * deletable) when it lives on a durable disk WE control: R2 in prod, the local
 * `public` disk in dev. Provider-hosted / remote URLs must resolve to null so
 * the delete action never issues a storage delete against a bucket we don't own.
 */
class DraftDurableMediaKeyTest extends TestCase
{
    private function useR2(string $publicUrl = 'https://smt-assets.eiaawsolutions.com'): void
    {
        config([
            'filesystems.disks.r2.bucket' => 'smt-assets',
            'filesystems.disks.r2.url' => $publicUrl,
        ]);
    }

    private function usePublicDisk(string $appUrl = 'https://smt.eiaawsolutions.com'): void
    {
        // No R2 bucket → durable disk falls back to `public`, whose url base is
        // <APP_URL>/storage (see config/filesystems.php).
        config([
            'filesystems.disks.r2.bucket' => null,
            'filesystems.disks.public.url' => $appUrl.'/storage',
        ]);
    }

    public function test_r2_url_resolves_to_object_key(): void
    {
        $this->useR2();

        $this->assertSame(
            'branding/388-abc.jpg',
            Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com/branding/388-abc.jpg'),
        );
    }

    public function test_r2_url_strips_query_string_from_key(): void
    {
        $this->useR2();

        $this->assertSame(
            'branding/388-clip.mp4',
            Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com/branding/388-clip.mp4?sig=xyz&t=1'),
        );
    }

    public function test_public_disk_url_resolves_to_object_key(): void
    {
        $this->usePublicDisk();

        $this->assertSame(
            'branding/388-abc.jpg',
            Draft::durableMediaKey('https://smt.eiaawsolutions.com/storage/branding/388-abc.jpg'),
        );
    }

    public function test_remote_provider_url_is_not_ours_to_delete(): void
    {
        $this->useR2();

        // Blotato / Metricool / customer-supplied hosts don't match our durable
        // disk base → null → the delete action skips the file delete entirely.
        $this->assertNull(Draft::durableMediaKey('https://media.blotato.com/abc/clip.mp4'));
        $this->assertNull(Draft::durableMediaKey('https://cdn.metricool.com/xyz/image.jpg'));
        $this->assertNull(Draft::durableMediaKey('https://example.com/some/photo.png'));
    }

    public function test_empty_or_null_url_resolves_to_null(): void
    {
        $this->useR2();

        $this->assertNull(Draft::durableMediaKey(null));
        $this->assertNull(Draft::durableMediaKey(''));
        $this->assertNull(Draft::durableMediaKey('   '));
    }

    public function test_base_url_itself_with_no_object_path_resolves_to_null(): void
    {
        $this->useR2();

        // The bare base (no trailing object key) isn't a deletable object.
        $this->assertNull(Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com'));
        $this->assertNull(Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com/'));
    }

    public function test_trailing_slash_on_configured_base_is_tolerated(): void
    {
        // A base accidentally configured with a trailing slash must still match.
        $this->useR2('https://smt-assets.eiaawsolutions.com/');

        $this->assertSame(
            'branding/388-abc.jpg',
            Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com/branding/388-abc.jpg'),
        );
    }

    public function test_lookalike_host_prefix_does_not_falsely_match(): void
    {
        $this->useR2('https://smt-assets.eiaawsolutions.com');

        // A different host that merely shares a string prefix must NOT match —
        // the base is compared with a trailing "/" boundary to prevent this.
        $this->assertNull(
            Draft::durableMediaKey('https://smt-assets.eiaawsolutions.com.evil.test/branding/x.jpg'),
        );
    }
}

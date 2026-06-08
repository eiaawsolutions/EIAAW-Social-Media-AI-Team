<?php

namespace Tests\Unit;

use App\Models\BrandAsset;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DB-free unit tests for BrandAsset's preview-safety helpers (displayUrl +
 * bytesAvailable). These guard the fix for the broken brand-asset preview
 * ([[brand-asset-storage-ephemeral]]): a row whose file is gone must be
 * detectable so the UI shows an honest placeholder instead of a broken image.
 *
 * DB-free by design (local .env DB == prod). We build models in memory with
 * setRawAttributes and fake the disk — never persist a row.
 */
class BrandAssetDisplayUrlTest extends TestCase
{
    private function asset(array $attrs): BrandAsset
    {
        $a = new BrandAsset();
        $a->setRawAttributes($attrs, true);

        return $a;
    }

    public function test_display_url_returns_null_for_blank_or_whitespace(): void
    {
        $this->assertNull($this->asset(['public_url' => null])->displayUrl());
        $this->assertNull($this->asset(['public_url' => ''])->displayUrl());
        $this->assertNull($this->asset(['public_url' => '   '])->displayUrl());
    }

    public function test_display_url_returns_trimmed_url_when_present(): void
    {
        $this->assertSame(
            'https://cdn.example.com/a.png',
            $this->asset(['public_url' => '  https://cdn.example.com/a.png  '])->displayUrl(),
        );
    }

    public function test_bytes_available_is_false_without_disk_or_path(): void
    {
        $this->assertFalse($this->asset([])->bytesAvailable());
        $this->assertFalse($this->asset(['storage_disk' => 'public'])->bytesAvailable());
        $this->assertFalse($this->asset(['storage_path' => 'x.png'])->bytesAvailable());
    }

    public function test_bytes_available_reflects_disk_state(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand-assets/9/present.png', 'bytes');

        $present = $this->asset(['storage_disk' => 'public', 'storage_path' => 'brand-assets/9/present.png']);
        $gone = $this->asset(['storage_disk' => 'public', 'storage_path' => 'brand-assets/9/gone.png']);

        $this->assertTrue($present->bytesAvailable());
        $this->assertFalse($gone->bytesAvailable());
    }

    public function test_bytes_available_swallows_disk_errors(): void
    {
        // A disk that doesn't exist must not throw out of a view render.
        $a = $this->asset(['storage_disk' => 'no-such-disk', 'storage_path' => 'x.png']);

        $this->assertFalse($a->bytesAvailable());
    }
}

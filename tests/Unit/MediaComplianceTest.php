<?php

namespace Tests\Unit;

use App\Services\Blotato\PlatformMediaRules;
use App\Services\Imagery\ImageAutoCompressor;
use App\Services\Imagery\MediaComplianceChecker;
use Tests\TestCase;

class MediaComplianceTest extends TestCase
{
    /** @var array<int,string> temp files to clean up */
    private array $temp = [];

    protected function tearDown(): void
    {
        foreach ($this->temp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    /** Write a solid-colour JPEG of given dimensions to a temp file. */
    private function makeJpeg(int $w, int $h, int $quality = 92): string
    {
        $img = imagecreatetruecolor($w, $h);
        // Noise so JPEG can't trivially shrink to nothing — exercises the
        // quality ladder realistically.
        for ($i = 0; $i < 4000; $i++) {
            $c = imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255));
            imagesetpixel($img, random_int(0, $w - 1), random_int(0, $h - 1), $c);
        }
        $path = tempnam(sys_get_temp_dir(), 'mctest_') . '.jpg';
        imagejpeg($img, $path, $quality);
        imagedestroy($img);
        $this->temp[] = $path;
        return $path;
    }

    public function test_rules_resolve_with_fallback(): void
    {
        $ig = PlatformMediaRules::for('instagram', 'image');
        $this->assertArrayHasKey('max_bytes', $ig);
        $this->assertSame(['jpg', 'jpeg', 'png'], $ig['formats']);

        // Unknown platform falls back to DEFAULT_RULE.
        $unknown = PlatformMediaRules::for('myspace', 'image');
        $this->assertSame(PlatformMediaRules::DEFAULT_RULE['image'], $unknown);

        // Unknown media type coerces to image.
        $coerced = PlatformMediaRules::for('instagram', 'hologram');
        $this->assertSame($ig, $coerced);
    }

    public function test_compliant_square_image_passes_instagram(): void
    {
        $path = $this->makeJpeg(1080, 1080);
        $result = app(MediaComplianceChecker::class)->check($path, 'instagram', 'image');

        $this->assertTrue($result['passed'], json_encode($result['violations']));
        $this->assertSame(1080, $result['probe']['width']);
    }

    public function test_oversize_dimensions_flagged_as_compressible(): void
    {
        // 4000x4000 exceeds IG's 1440x1800 cap.
        $path = $this->makeJpeg(4000, 4000);
        $result = app(MediaComplianceChecker::class)->check($path, 'instagram', 'image');

        $this->assertFalse($result['passed']);
        $kinds = collect($result['violations'])->pluck('kind')->all();
        $this->assertContains('oversize_dimensions', $kinds);

        $oversize = collect($result['violations'])->firstWhere('kind', 'oversize_dimensions');
        $this->assertTrue($oversize['fixable_by_compression']);
        $this->assertNotEmpty($oversize['suggestion']);
    }

    public function test_aspect_out_of_band_is_not_compressible(): void
    {
        // 1500x500 = 3.0 aspect, well above IG's 1.91 max.
        $path = $this->makeJpeg(1500, 500);
        $result = app(MediaComplianceChecker::class)->check($path, 'instagram', 'image');

        $this->assertFalse($result['passed']);
        $aspect = collect($result['violations'])->firstWhere('kind', 'aspect_out_of_band');
        $this->assertNotNull($aspect);
        $this->assertFalse($aspect['fixable_by_compression']);
    }

    public function test_too_small_is_not_compressible(): void
    {
        $path = $this->makeJpeg(100, 100);
        $result = app(MediaComplianceChecker::class)->check($path, 'instagram', 'image');

        $this->assertFalse($result['passed']);
        $small = collect($result['violations'])->firstWhere('kind', 'too_small');
        $this->assertNotNull($small);
        $this->assertFalse($small['fixable_by_compression']);
    }

    public function test_autocompressor_brings_oversize_image_within_dimensions(): void
    {
        $path = $this->makeJpeg(4000, 4000);
        $out = app(ImageAutoCompressor::class)->compressForPlatform($path, 'instagram');
        $this->temp[] = $out['path'];

        $rule = PlatformMediaRules::for('instagram', 'image');
        $this->assertLessThanOrEqual($rule['max_width'], $out['width']);
        $this->assertLessThanOrEqual($rule['max_height'], $out['height']);
        $this->assertLessThanOrEqual($rule['max_bytes'], $out['bytes']);

        // And the compressed result now passes the checker.
        $recheck = app(MediaComplianceChecker::class)->check($out['path'], 'instagram', 'image');
        $this->assertTrue($recheck['passed'], json_encode($recheck['violations']));
    }

    public function test_human_bytes_formats_readably(): void
    {
        $this->assertSame('8 MB', PlatformMediaRules::humanBytes(8 * 1024 * 1024));
        $this->assertSame('1.5 MB', PlatformMediaRules::humanBytes((int) (1.5 * 1024 * 1024)));
    }
}

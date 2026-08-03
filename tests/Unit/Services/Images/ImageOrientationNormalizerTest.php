<?php

namespace Tests\Unit\Services\Images;

use App\Services\Images\ImageOrientationNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * The "sideways photo" fix (property 6118): a phone captures a portrait shot as
 * landscape pixels + an EXIF Orientation tag. GD re-encoding downstream drops the
 * tag without rotating, so the photo lands sideways. This normalizer bakes the
 * rotation into the pixels at ingest and strips the tag, so every surface — and
 * every client — renders it upright.
 *
 * Fixture: tests/Fixtures/Images/portrait-exif6.jpg is 900x600 (landscape pixels)
 * tagged Orientation=6 ("rotate 90 CW to display upright") → corrected = 600x900.
 */
class ImageOrientationNormalizerTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        // Operate on a throwaway copy — normalizeInPlace() rewrites the file.
        $this->work = tempnam(sys_get_temp_dir(), 'orient').'.jpg';
        copy(base_path('tests/Fixtures/Images/portrait-exif6.jpg'), $this->work);
    }

    protected function tearDown(): void
    {
        @unlink($this->work);
        parent::tearDown();
    }

    public function test_it_rotates_pixels_upright_and_strips_the_exif_tag(): void
    {
        // Precondition: the fixture really is landscape pixels tagged orientation 6.
        [$w0, $h0] = getimagesize($this->work);
        $this->assertSame(900, $w0);
        $this->assertSame(600, $h0);
        $this->assertSame(6, (int) (@exif_read_data($this->work)['Orientation'] ?? 0));

        $changed = (new ImageOrientationNormalizer)->normalizeInPlace($this->work);

        $this->assertTrue($changed, 'A photo needing rotation must report it was rewritten.');

        // Pixels are now upright (portrait) …
        [$w1, $h1] = getimagesize($this->work);
        $this->assertSame(600, $w1, 'Corrected width must be the old height.');
        $this->assertSame(900, $h1, 'Corrected height must be the old width.');

        // … and the orientation tag is gone, so no downstream viewer double-rotates.
        $exif = @exif_read_data($this->work);
        $this->assertArrayNotHasKey('Orientation', $exif ?: [],
            'The EXIF Orientation tag must be stripped after baking it into pixels.');
    }

    public function test_it_is_idempotent_on_an_already_upright_image(): void
    {
        $svc = new ImageOrientationNormalizer;
        $svc->normalizeInPlace($this->work);      // first pass corrects it
        $before = md5_file($this->work);

        $second = $svc->normalizeInPlace($this->work);

        $this->assertFalse($second, 'An already-upright image needs no rewrite.');
        $this->assertSame($before, md5_file($this->work),
            'A no-op must not re-encode the file (no silent quality loss).');
    }

    public function test_it_is_a_no_op_for_a_non_jpeg(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'orient').'.png';
        $img = imagecreatetruecolor(40, 30);
        imagepng($img, $png);
        imagedestroy($img);

        $this->assertFalse((new ImageOrientationNormalizer)->normalizeInPlace($png),
            'PNGs carry no JPEG EXIF orientation — the normalizer must leave them alone.');

        @unlink($png);
    }

    public function test_it_is_a_no_op_for_a_missing_file(): void
    {
        $this->assertFalse(
            (new ImageOrientationNormalizer)->normalizeInPlace('/no/such/file.jpg')
        );
    }

    /**
     * Property 6142 (2026-08-03): a HUAWEI Mate X6 writes Orientation=0 — not a
     * valid EXIF value — while the pixel buffer is still stored sideways in a
     * portrait canvas. The old code treated 0 exactly like "already upright"
     * and silently shipped the photo sideways. Fixture carries a distinct color
     * in each corner so the test can verify the ACTUAL rotation direction, not
     * just "some" rotation happened.
     */
    public function test_it_applies_the_huawei_orientation_0_heuristic(): void
    {
        $work = tempnam(sys_get_temp_dir(), 'huawei').'.jpg';
        copy(base_path('tests/Fixtures/Images/huawei-orientation0.jpg'), $work);

        [$w0, $h0] = getimagesize($work);
        $this->assertSame(200, $w0);
        $this->assertSame(300, $h0, 'Precondition: fixture is a portrait canvas.');
        $exif = @exif_read_data($work);
        $this->assertSame('HUAWEI', $exif['Make'] ?? null);
        $this->assertSame(0, (int) ($exif['Orientation'] ?? -1));

        $changed = (new ImageOrientationNormalizer)->normalizeInPlace($work);

        $this->assertTrue($changed, 'The HUAWEI orientation-0 signature must be corrected, not skipped.');

        [$w1, $h1] = getimagesize($work);
        $this->assertSame(300, $w1);
        $this->assertSame(200, $h1, 'Canvas must rotate 90°, matching the verified device defect.');

        $img = imagecreatefromjpeg($work);
        $corner = fn (int $x, int $y) => imagecolorsforindex($img, imagecolorat($img, $x, $y));

        // Verified mapping for the case-8 (90° CCW) transform: old top-right
        // (green) lands top-left, old bottom-right (yellow) lands top-right.
        $this->assertEquals(['red' => 0, 'green' => 255, 'blue' => 0, 'alpha' => 0], $this->rounded($corner(5, 5)));
        $this->assertEquals(['red' => 255, 'green' => 255, 'blue' => 0, 'alpha' => 0], $this->rounded($corner($w1 - 5, 5)));
        imagedestroy($img);

        @unlink($work);
    }

    public function test_it_leaves_an_unresolvable_orientation_untouched(): void
    {
        $work = tempnam(sys_get_temp_dir(), 'unknown').'.jpg';
        copy(base_path('tests/Fixtures/Images/unknown-orientation-no-make.jpg'), $work);

        $before = md5_file($work);

        $changed = (new ImageOrientationNormalizer)->normalizeInPlace($work);

        $this->assertFalse($changed, 'No Make/no evidence — must not guess a rotation direction.');
        $this->assertSame($before, md5_file($work), 'An unresolved case must never be re-encoded.');

        @unlink($work);
    }

    /** JPEG re-encoding can shift channel values by 1-2; compare with tolerance. */
    private function rounded(array $rgb): array
    {
        foreach ($rgb as $k => $v) {
            $rgb[$k] = $v > 250 ? 255 : ($v < 5 ? 0 : $v);
        }

        return $rgb;
    }
}

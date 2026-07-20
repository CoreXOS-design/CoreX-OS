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
        $this->work = tempnam(sys_get_temp_dir(), 'orient') . '.jpg';
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

        $changed = (new ImageOrientationNormalizer())->normalizeInPlace($this->work);

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
        $svc = new ImageOrientationNormalizer();
        $svc->normalizeInPlace($this->work);      // first pass corrects it
        $before = md5_file($this->work);

        $second = $svc->normalizeInPlace($this->work);

        $this->assertFalse($second, 'An already-upright image needs no rewrite.');
        $this->assertSame($before, md5_file($this->work),
            'A no-op must not re-encode the file (no silent quality loss).');
    }

    public function test_it_is_a_no_op_for_a_non_jpeg(): void
    {
        $png = tempnam(sys_get_temp_dir(), 'orient') . '.png';
        $img = imagecreatetruecolor(40, 30);
        imagepng($img, $png);
        imagedestroy($img);

        $this->assertFalse((new ImageOrientationNormalizer())->normalizeInPlace($png),
            'PNGs carry no JPEG EXIF orientation — the normalizer must leave them alone.');

        @unlink($png);
    }

    public function test_it_is_a_no_op_for_a_missing_file(): void
    {
        $this->assertFalse(
            (new ImageOrientationNormalizer())->normalizeInPlace('/no/such/file.jpg')
        );
    }
}

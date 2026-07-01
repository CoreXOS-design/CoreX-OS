<?php

namespace Tests\Feature\Syndication;

use App\Models\Property;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Tests\TestCase;

/**
 * P24 rejects a submit whose images together exceed its 60MB data cap. The
 * mapper must pack the gallery under that budget by downscaling the oversized
 * photos — never dropping a photo, never failing the submit. This locks that
 * behaviour so an image-heavy listing can never blow the cap again.
 */
class Property24PayloadBudgetTest extends TestCase
{
    private const BUDGET  = 57 * 1024 * 1024;
    private const HARDCAP = 60 * 1024 * 1024;

    private function fit(array $raw): array
    {
        $mapper = new Property24ListingMapper();
        $method = new \ReflectionMethod($mapper, 'fitPhotosToPayloadBudget');
        $method->setAccessible(true);

        return $method->invoke($mapper, $raw, (new Property())->forceFill(['id' => 1]));
    }

    /** Exact standard-base64 encoded length of a raw byte string. */
    private function b64Len(string $b): int
    {
        return intdiv(strlen($b) + 2, 3) * 4;
    }

    private function totalB64(array $raw): int
    {
        return array_sum(array_map(fn ($e) => $this->b64Len($e['bytes']), $raw));
    }

    /** A ~2MB photographic-noise JPEG that JPEG cannot trivially crush. */
    private function makeHeavyJpeg(int $edge = 1600): string
    {
        $img = imagecreatetruecolor($edge, $edge);
        for ($y = 0; $y < $edge; $y += 2) {
            for ($x = 0; $x < $edge; $x += 2) {
                $c = imagecolorallocate($img, ($x * 7) % 256, ($y * 13) % 256, (($x + $y) * 3) % 256);
                imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, $c);
            }
        }
        ob_start();
        imagejpeg($img, null, 95);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_oversized_gallery_is_packed_under_the_cap_without_dropping_photos(): void
    {
        $one = $this->makeHeavyJpeg();
        $raw = [];
        for ($i = 0; $i < 40; $i++) {
            $raw[] = ['bytes' => $one, 'mime' => 'image/jpeg', 'caption' => null];
        }

        $this->assertGreaterThan(self::HARDCAP, $this->totalB64($raw), 'fixture must start over the cap');

        $out = $this->fit($raw);

        $this->assertCount(40, $out, 'no photo may be dropped');
        $this->assertLessThanOrEqual(self::HARDCAP, $this->totalB64($out), 'must fit under the 60MB cap');
        $this->assertLessThanOrEqual(self::BUDGET, $this->totalB64($out), 'must fit under the packing budget');

        foreach ($out as $entry) {
            $this->assertInstanceOf(\GdImage::class, imagecreatefromstring($entry['bytes']), 'every photo must remain decodable');
        }
    }

    public function test_gallery_within_budget_passes_through_untouched(): void
    {
        $img = imagecreatetruecolor(400, 400);
        imagefilledrectangle($img, 0, 0, 399, 399, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        $raw = [
            ['bytes' => $jpeg, 'mime' => 'image/png', 'caption' => 'x'],
            ['bytes' => $jpeg, 'mime' => 'image/jpeg', 'caption' => null],
        ];

        $out = $this->fit($raw);

        $this->assertSame($jpeg, $out[0]['bytes'], 'under-budget photo must not be re-encoded');
        $this->assertSame($jpeg, $out[1]['bytes'], 'under-budget photo must not be re-encoded');
        $this->assertSame('image/png', $out[0]['mime'], 'mime must be preserved when not re-encoding');
    }

    public function test_empty_set_is_handled(): void
    {
        $this->assertSame([], $this->fit([]));
    }
}

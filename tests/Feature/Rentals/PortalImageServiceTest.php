<?php

namespace Tests\Feature\Rentals;

use App\Models\RentalWorkOrderPhoto;
use App\Services\Images\PortalImageService;
use App\Services\Rentals\RentalJobCardClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * .ai/specs/rental-portal-access.md §23 - the portal never serves a stored original in a small box. A 12-megapixel photo shown at
 * 72 x 72 px cost 1.3 s of decoding in a real browser PER SIX PHOTOS (7.6 MB over the wire); the same six as 480 px copies cost 40 ms
 * (250 KB). Whatever path a picture came in by (tenant wizard, owner wizard, agent, crew, completion round) the portal gets a
 * thumbnail for lists and a capped copy for the full view. Security audit D14: both are signed, expiring addresses (see
 * Tests\Feature\Portal\PrivateImageTest), the copies live on the private disk.
 */
class PortalImageServiceTest extends TestCase
{
    use RefreshDatabase;   // the signed urls are fetched through the real route

    private PortalImageService $images;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->images = app(PortalImageService::class);
    }

    private function makeImage(string $rel, int $w, int $h, string $type = 'jpeg', int $quality = 95): string
    {
        $im = imagecreatetruecolor($w, $h);
        for ($y = 0; $y < $h; $y += 7) {
            for ($x = 0; $x < $w; $x += 7) {   // noise so the JPEG is realistically heavy, not a flat colour that compresses to nothing
                imagefilledrectangle($im, $x, $y, $x + 6, $y + 6, imagecolorallocate($im, mt_rand(0, 255), ($x * 255 / $w) & 255, ($y * 255 / $h) & 255));
            }
        }
        $abs = Storage::disk('public')->path($rel);
        @mkdir(dirname($abs), 0775, true);
        $type === 'png' ? imagepng($im, $abs) : imagejpeg($im, $abs, $quality);
        imagedestroy($im);

        return $abs;
    }

    /** The file a signed portal url streams (the copy, or the original when it already fits). Fetches through the real route. */
    private function served(string $url): string
    {
        $this->assertStringContainsString('/private-image/', $url, "not a signed portal url: $url");
        $res = $this->get($url);
        $res->assertOk();

        return $res->baseResponse->getFile()->getPathname();
    }

    private function dims(string $url): array
    {
        return array_slice(getimagesize($this->served($url)), 0, 2);
    }

    public function test_a_big_original_gets_a_thumbnail_and_a_capped_full_copy_and_stays_untouched(): void
    {
        $abs = $this->makeImage('properties/6069/phone.jpg', 3000, 2250);
        $hash = md5_file($abs);

        $u = $this->images->urls('/storage/properties/6069/phone.jpg');

        [$tw, $th] = $this->dims($u['thumb_url']);
        $this->assertLessThanOrEqual(PortalImageService::THUMB_EDGE, max($tw, $th));
        [$fw, $fh] = $this->dims($u['url']);
        $this->assertLessThanOrEqual(PortalImageService::FULL_EDGE, max($fw, $fh));
        $this->assertGreaterThan(max($tw, $th), max($fw, $fh), 'the full view is bigger than the thumbnail');
        $this->assertNotSame($u['url'], $u['thumb_url']);
        $this->assertStringNotContainsString('properties/6069/phone.jpg', $u['url'] . $u['thumb_url'], 'neither is the stored original');
        $this->assertStringNotContainsString('/storage/', $u['url'] . $u['thumb_url'], 'D14: never a permanent public address');
        $this->assertLessThan(filesize($abs) / 10, filesize($this->served($u['thumb_url'])), 'the thumbnail is a fraction of the original');
        $this->assertStringContainsString(Storage::disk('local')->path('private-images/portal-img'), $this->served($u['thumb_url']), 'the copies live on the private disk');
        $this->assertSame([], array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_contains($f, 'portal-img')), 'and none on the public one');
        $this->assertSame($hash, md5_file($abs), 'the original is never modified');
    }

    public function test_copies_are_made_once_and_a_replaced_original_gets_fresh_ones(): void
    {
        $abs = $this->makeImage('properties/1/a.jpg', 2400, 1800);
        $first = $this->served($this->images->urls('/storage/properties/1/a.jpg')['thumb_url']);
        $made = filemtime($first);
        clearstatcache();

        $again = $this->served($this->images->urls('/storage/properties/1/a.jpg')['thumb_url']);
        $this->assertSame($first, $again, 'same copy the second time');
        $this->assertSame($made, filemtime($again), 'and no second resize');

        $this->makeImage('properties/1/a.jpg', 2000, 1500);
        touch($abs, time() + 60);
        clearstatcache();
        $this->assertNotSame($first, $this->served($this->images->urls('/storage/properties/1/a.jpg')['thumb_url']), 'a replaced original is not served from the old copy');
    }

    public function test_a_picture_already_small_is_streamed_as_it_is_but_still_through_a_signed_url(): void
    {
        $abs = $this->makeImage('properties/1/small.jpg', 300, 200, 'jpeg', 60);

        $u = $this->images->urls('/storage/properties/1/small.jpg');

        $this->assertStringContainsString('/private-image/', $u['thumb_url']);
        $this->assertStringContainsString('/private-image/', $u['url']);
        $this->assertSame($abs, $this->served($u['thumb_url']), 'no pointless re-encode');
        $this->assertSame($abs, $this->served($u['url']));
    }

    public function test_a_png_becomes_a_jpeg_copy_and_absolute_urls_work(): void
    {
        $this->makeImage('rental-fault-types/guide.png', 1800, 1200, 'png');

        $u = $this->images->urls('https://qatesting1.example/storage/rental-fault-types/guide.png');

        $this->assertStringEndsWith('.jpg', $this->served($u['thumb_url']));
        $this->assertLessThanOrEqual(PortalImageService::THUMB_EDGE, max($this->dims($u['thumb_url'])));
    }

    public function test_anything_that_is_not_ours_comes_back_unchanged_and_a_path_walk_is_refused(): void
    {
        foreach (['', null, 'https://example.com/pic.jpg', '/storage/properties/9/missing.jpg', '/storage/../.env.jpg', '/storage/portal-img/480/x.jpg'] as $in) {
            $out = $this->images->urls($in);
            $this->assertSame($in, $out['url'], var_export($in, true));
            $this->assertSame($in, $out['thumb_url'], var_export($in, true));
        }
        $this->assertNull($this->images->relativePath('/storage/../.env.jpg'));
    }

    public function test_a_file_that_is_not_a_picture_is_never_streamed_and_never_given_a_permanent_address(): void
    {
        Storage::disk('public')->put('properties/2/broken.jpg', 'this is not an image');

        $u = $this->images->urls('/storage/properties/2/broken.jpg');

        $this->assertStringContainsString('/private-image/', $u['url']);
        $this->get($u['url'])->assertNotFound();
    }

    public function test_a_work_order_or_completion_photo_payload_carries_both_signed_urls_never_the_original(): void
    {
        $this->makeImage('properties/6069/crew.jpg', 2800, 2100);
        $photo = new RentalWorkOrderPhoto(['storage_path' => '/storage/properties/6069/crew.jpg', 'photo_type' => 'during']);
        $photo->id = 5;

        $row = app(RentalJobCardClientViewService::class)->photoPayload($photo);

        $this->assertSame(['id', 'url', 'thumb_url', 'photo_type', 'caption', 'uploaded_at'], array_keys($row));
        $this->assertNotSame($row['url'], $row['thumb_url']);
        foreach (['url', 'thumb_url'] as $k) {
            $this->assertStringContainsString('/private-image/', $row[$k]);
            $this->assertStringNotContainsString('/storage/', $row[$k]);
            $this->assertStringNotContainsString('crew.jpg', $row[$k]);
        }
    }

    public function test_no_portal_view_shows_a_stored_original_in_an_image_tag(): void
    {
        // The class guard: a future partial that writes <img :src="x.url"> brings the freeze-class cost straight back.
        $dir = base_path('resources/views/rentals/portal');
        foreach (glob($dir . '/*.blade.php') as $file) {
            preg_match_all('/<img\b[^>]*>/', (string) file_get_contents($file), $tags);
            foreach ($tags[0] as $tag) {
                if (str_contains($tag, 'data-portal-logo') || str_contains($tag, 'p.url')) {
                    continue;   // the header logo (its own small copy, App\Support\PortalLogo) and the picker's local preview of a file not yet sent
                }
                $this->assertStringContainsString('thumb_url', $tag, basename($file) . ': ' . $tag);
                $this->assertStringContainsString('loading="lazy"', $tag, basename($file) . ' must lazy-load: ' . $tag);
            }
        }
    }
}

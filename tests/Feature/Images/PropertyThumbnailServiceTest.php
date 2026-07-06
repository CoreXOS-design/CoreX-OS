<?php

declare(strict_types=1);

namespace Tests\Feature\Images;

use App\Models\Property;
use App\Services\Images\PropertyThumbnailService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Property list-view thumbnails: small JPEG derived from the full-res original,
 * stored alongside it, with a graceful fallback to the original until a thumb
 * exists. Originals must never be touched.
 */
final class PropertyThumbnailServiceTest extends TestCase
{
    private function putJpeg(string $rel, int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 120, 130, 140));
        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        Storage::disk('public')->put($rel, $bytes);

        return Storage::url($rel);
    }

    public function test_generates_a_downscaled_thumbnail(): void
    {
        Storage::fake('public');
        $url = $this->putJpeg('properties/42/photo.jpg', 2000, 1500);
        $svc = new PropertyThumbnailService(maxEdge: 600, quality: 80);

        $this->assertTrue($svc->generateForUrl($url));
        Storage::disk('public')->assertExists('properties/42/thumbs/photo.jpg');

        [$tw, $th] = getimagesize(Storage::disk('public')->path('properties/42/thumbs/photo.jpg'));
        // 2000x1500 scaled to a 600px longest edge → 600x450, aspect preserved.
        $this->assertSame(600, $tw);
        $this->assertSame(450, $th);

        // The thumbnail is materially smaller on disk than the original.
        $orig  = Storage::disk('public')->size('properties/42/photo.jpg');
        $thumb = Storage::disk('public')->size('properties/42/thumbs/photo.jpg');
        $this->assertLessThan($orig, $thumb);
    }

    public function test_display_url_falls_back_to_original_until_thumb_exists(): void
    {
        Storage::fake('public');
        $url = $this->putJpeg('properties/7/a.jpg', 800, 600);
        $svc = new PropertyThumbnailService();

        // No thumb yet → original serves (nothing breaks pre-backfill).
        $this->assertSame($url, $svc->displayUrl($url));

        $svc->generateForUrl($url);
        $this->assertStringContainsString('/properties/7/thumbs/a.jpg', (string) $svc->displayUrl($url));
    }

    public function test_idempotent_and_original_is_never_modified(): void
    {
        Storage::fake('public');
        $url = $this->putJpeg('properties/9/b.jpg', 1200, 900);
        $svc = new PropertyThumbnailService();
        $before = Storage::disk('public')->get('properties/9/b.jpg');

        $this->assertTrue($svc->generateForUrl($url));
        $this->assertTrue($svc->generateForUrl($url)); // second call is a no-op success
        $this->assertSame($before, Storage::disk('public')->get('properties/9/b.jpg'));
    }

    public function test_non_property_and_already_thumb_urls_pass_through(): void
    {
        Storage::fake('public');
        $svc = new PropertyThumbnailService();

        $this->assertSame('https://cdn.example.com/x.jpg', $svc->displayUrl('https://cdn.example.com/x.jpg'));
        $this->assertNull($svc->displayUrl(null));

        $thumbUrl = '/storage/properties/1/thumbs/x.jpg';
        $this->assertSame($thumbUrl, $svc->displayUrl($thumbUrl)); // never thumbnail a thumbnail
        $this->assertFalse($svc->generateForUrl($thumbUrl));
    }

    public function test_absolute_url_is_resolved(): void
    {
        Storage::fake('public');
        $this->putJpeg('properties/5/d.jpg', 900, 600);
        $svc = new PropertyThumbnailService();

        $absolute = 'https://corexos.co.za/storage/properties/5/d.jpg';
        $this->assertTrue($svc->generateForUrl($absolute));
        Storage::disk('public')->assertExists('properties/5/thumbs/d.jpg');
    }

    public function test_property_thumb_for_helper(): void
    {
        Storage::fake('public');
        $url = $this->putJpeg('properties/3/c.jpg', 1000, 1000);
        (new PropertyThumbnailService())->generateForUrl($url);

        $property = new Property();
        $this->assertStringContainsString('/properties/3/thumbs/c.jpg', (string) $property->thumbFor($url));
        $this->assertNull($property->thumbFor(null));
    }
}

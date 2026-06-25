<?php

namespace Tests\Feature\Syndication;

use App\Services\Syndication\Property24\Property24SyndicationService;
use Tests\TestCase;

/**
 * P24's profile-picture endpoint returns HTTP 500 for WebP uploads (confirmed
 * live). Our agent photos are stored as WebP, so the sync must transcode to JPEG
 * at the upload boundary. This locks that conversion so a WebP photo can never
 * silently fail to reach P24 again.
 */
class Property24PhotoTranscodeTest extends TestCase
{
    private function invokeTranscode(string $bytes, string $mime): array
    {
        $svc = app(Property24SyndicationService::class);
        $method = new \ReflectionMethod($svc, 'toP24SafeImage');
        $method->setAccessible(true);

        return $method->invoke($svc, $bytes, $mime);
    }

    private function makeWebp(): string
    {
        $img = imagecreatetruecolor(64, 64);
        imagefilledrectangle($img, 0, 0, 64, 64, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagewebp($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }

    public function test_webp_is_transcoded_to_jpeg(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support unavailable in this environment.');
        }

        [$out, $mime] = $this->invokeTranscode($this->makeWebp(), 'image/webp');

        $this->assertSame('image/jpeg', $mime);
        // JPEG SOI marker.
        $this->assertSame("\xFF\xD8", substr($out, 0, 2), 'output must be JPEG bytes');
    }

    public function test_jpeg_passes_through_untouched(): void
    {
        $img = imagecreatetruecolor(32, 32);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        [$out, $mime] = $this->invokeTranscode($jpeg, 'image/jpeg');

        $this->assertSame('image/jpeg', $mime);
        $this->assertSame($jpeg, $out, 'JPEG input must be returned unchanged');
    }
}

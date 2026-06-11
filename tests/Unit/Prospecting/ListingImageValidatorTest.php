<?php

declare(strict_types=1);

namespace Tests\Unit\Prospecting;

use App\Services\Prospecting\ListingImageValidator;
use PHPUnit\Framework\TestCase;

/**
 * AT-22 items 2 + 7 — the shared "is this a genuine property photo?" gate.
 *
 * Conservative bias: real photos must pass (false-negatives only). Logos,
 * icons, trackers, vectors, pixels, and known agency brands must be rejected
 * so a competitor mark never lands on a seller-facing surface.
 */
final class ListingImageValidatorTest extends TestCase
{
    private ListingImageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ListingImageValidator();
    }

    /** @dataProvider rejectedAssets */
    public function test_rejects_non_photo_and_branded_assets(string $value, string $why): void
    {
        $this->assertFalse(
            $this->validator->isGenuinePhoto($value),
            "Expected REJECT ({$why}): {$value}"
        );
    }

    public static function rejectedAssets(): array
    {
        return [
            'remax logo host'      => ['https://cdn.remax.co.za/assets/brand.png', 'agency brand: remax'],
            'pam golding logo'     => ['https://images.pamgolding.co.za/logo.png', 'agency brand: pamgolding'],
            'seeff brand'          => ['https://seeff.com/img/header.jpg', 'agency brand: seeff'],
            'tyson properties'     => ['https://assets.tyson.co.za/photo1.jpg', 'agency brand: tyson'],
            'explicit /logo path'  => ['https://images.prop24.com/247/logo.png', 'non-photo: /logo'],
            'icon_ prefix'         => ['https://cdn.prop24.com/icon_bed.svg', 'non-photo: icon_'],
            'svg vector'           => ['https://cdn.prop24.com/marker.svg', 'non-photo: .svg'],
            '1x1 tracking'         => ['https://t.prop24.com/1x1.gif', 'non-photo: 1x1'],
            'pixel tracker'        => ['https://t.prop24.com/pixel.gif', 'non-photo: pixel'],
            'tracking host'        => ['https://tracking.prop24.com/img.gif', 'non-photo: tracking'],
            'generic branding'     => ['https://cdn.example.com/branding/banner.png', 'branding token'],
            'watermark'            => ['https://cdn.example.com/watermark-overlay.png', 'watermark token'],
            'logo filename'        => ['/storage/app/private/prospecting/thumbnails/agency_logo_001.jpg', 'logo token in path'],
            'empty string'         => ['', 'empty'],
            'whitespace only'      => ['   ', 'whitespace only'],
        ];
    }

    /** @dataProvider acceptedPhotos */
    public function test_accepts_genuine_property_photos(string $url): void
    {
        $this->assertTrue(
            $this->validator->isGenuinePhoto($url),
            "Expected ACCEPT (genuine photo): {$url}"
        );
    }

    public static function acceptedPhotos(): array
    {
        return [
            'p24 listing photo'    => ['https://images.prop24.com/247/360x240/9876543.jpg'],
            'pp listing photo'     => ['https://cdn.privateproperty.co.za/images/T1234567_1.jpg'],
            'stored thumbnail'     => ['prospecting/thumbnails/p24_P24-12345.jpg'],
            'plain jpg'            => ['https://cdn.example.com/uploads/houses/main.jpg'],
            'webp photo'           => ['https://cdn.example.com/media/12345.webp'],
        ];
    }

    public function test_null_is_rejected(): void
    {
        $this->assertFalse($this->validator->isGenuinePhoto(null));
    }

    public function test_stored_photo_requires_existing_file(): void
    {
        // A path that does not exist on disk fails even if the name looks fine.
        $this->assertFalse(
            $this->validator->isGenuineStoredPhoto('/no/such/file/photo.jpg')
        );

        // A real file with a genuine-photo name passes.
        $tmp = tempnam(sys_get_temp_dir(), 'photo_') . '.jpg';
        file_put_contents($tmp, 'fake-bytes');
        try {
            $this->assertTrue($this->validator->isGenuineStoredPhoto($tmp));
        } finally {
            @unlink($tmp);
        }

        // A real file whose name is a logo still fails (content-name guard).
        $logo = tempnam(sys_get_temp_dir(), 'x_') . '_logo.png';
        file_put_contents($logo, 'fake-bytes');
        try {
            $this->assertFalse($this->validator->isGenuineStoredPhoto($logo));
        } finally {
            @unlink($logo);
        }
    }

    public function test_stored_photo_rejects_null_and_empty(): void
    {
        $this->assertFalse($this->validator->isGenuineStoredPhoto(null));
        $this->assertFalse($this->validator->isGenuineStoredPhoto(''));
        $this->assertFalse($this->validator->isGenuineStoredPhoto('   '));
    }
}

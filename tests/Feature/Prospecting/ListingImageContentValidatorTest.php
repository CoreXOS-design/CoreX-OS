<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Jobs\DownloadListingThumbnail;
use App\Models\Agency;
use App\Models\ProspectingListing;
use App\Services\Prospecting\ListingImageValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-22 item 2 — IMAGE-CONTENT branding detection.
 *
 * The PRES 87 / v175 leak: a RE/MAX "Coast and Country" card was captured as a
 * PrivateProperty listing's primary photo, served from a neutral CDN URL, and
 * stored under a system filename (pp_PP-T5391969.jpg) with a null source URL.
 * Every URL/path substring check passed because the brand lives ONLY in the
 * pixels. These tests prove the content layer (OCR brand-text + flat-graphic
 * colour signal) catches it, and that the download job persists the verdict so
 * the seller-surface render gate withholds it.
 */
final class ListingImageContentValidatorTest extends TestCase
{
    use RefreshDatabase;

    private ListingImageValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new ListingImageValidator();
        // A single agency makes BelongsToAgency auto-stamp agency_id on create.
        Agency::create(['name' => 'Coastal', 'slug' => 'coastal']);
    }

    private function makeListing(string $portalRef): ProspectingListing
    {
        return ProspectingListing::create([
            'portal_source'            => 'pp',
            'portal_ref'               => $portalRef,
            'address'                  => '12 Bairn Street',
            'suburb'                   => 'Uvongo',
            'price'                    => 2_450_000,
            'property_type'            => 'House',
            'thumbnail_path'           => null,
            'thumbnail_source_url'     => null,
            'thumbnail_blocked_reason' => null,
        ]);
    }

    /** A flat RE/MAX-style brand card: rendered brand text + few colours. */
    private function brandCardPng(): string
    {
        $im = imagecreatetruecolor(600, 450);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        imagefilledrectangle($im, 0, 0, 600, 150, imagecolorallocate($im, 0, 40, 104));   // RE/MAX blue
        imagefilledrectangle($im, 0, 150, 600, 300, imagecolorallocate($im, 221, 0, 49));  // RE/MAX red
        $white = imagecolorallocate($im, 255, 255, 255);
        // Large block text — GD's built-in font is clean enough for tesseract.
        imagestring($im, 5, 230, 60, 'RE/MAX', $white);
        imagestring($im, 5, 160, 200, 'Coast and Country', $white);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** A generic flat graphic with NO readable brand text (logo plate). */
    private function flatGraphicPng(): string
    {
        $im = imagecreatetruecolor(600, 450);
        imagefill($im, 0, 0, imagecolorallocate($im, 12, 88, 140));
        imagefilledellipse($im, 300, 225, 180, 180, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    /** A continuous-tone "photograph": dense random colour, high entropy. */
    private function noisePhotoPng(): string
    {
        $im = imagecreatetruecolor(200, 150);
        for ($y = 0; $y < 150; $y++) {
            for ($x = 0; $x < 200; $x++) {
                imagesetpixel($im, $x, $y, imagecolorallocate(
                    $im,
                    ($x * 7 + $y * 13) % 256,
                    ($x * 17 + $y * 5) % 256,
                    ($x * 3 + $y * 29) % 256,
                ));
            }
        }
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        return $bytes;
    }

    public function test_remax_brand_card_is_blocked_by_content(): void
    {
        $verdict = $this->validator->inspectImageBytes($this->brandCardPng());

        $this->assertFalse($verdict['genuine'], 'A RE/MAX brand card must not be genuine.');
        $this->assertNotNull($verdict['reason']);

        // When OCR is present it must be caught precisely as the brand; without
        // tesseract the flat-graphic signal still blocks it.
        if ($this->validator->ocrAvailable()) {
            $this->assertSame('brand:remax', $verdict['reason'], 'OCR should identify the RE/MAX wordmark.');
        } else {
            $this->assertSame('graphic', $verdict['reason']);
        }
    }

    public function test_flat_graphic_without_text_is_blocked_as_graphic(): void
    {
        $verdict = $this->validator->inspectImageBytes($this->flatGraphicPng());

        $this->assertFalse($verdict['genuine']);
        $this->assertSame('graphic', $verdict['reason']);
    }

    public function test_genuine_photo_passes(): void
    {
        $verdict = $this->validator->inspectImageBytes($this->noisePhotoPng());

        $this->assertTrue($verdict['genuine'], 'A continuous-tone photo must pass.');
        $this->assertNull($verdict['reason']);
        $this->assertGreaterThan(64, $verdict['signals']['unique_colors']);
    }

    public function test_undecodable_bytes_degrade_to_genuine(): void
    {
        // Conservative bias: never blank on a tooling failure.
        $verdict = $this->validator->inspectImageBytes('not-an-image');
        $this->assertTrue($verdict['genuine']);
        $this->assertNull($verdict['reason']);
    }

    public function test_download_job_persists_block_reason_for_brand_card(): void
    {
        Storage::fake('local');

        $listing = $this->makeListing('PP-TEST-REMAX');

        // data:// URI lets the job's file_get_contents() read our bytes without
        // a network round-trip — the brand card stands in for a portal image.
        $dataUri = 'data://image/png;base64,' . base64_encode($this->brandCardPng());

        (new DownloadListingThumbnail($listing, $dataUri))->handle();

        $listing->refresh();
        $this->assertNotNull($listing->thumbnail_path, 'File is still stored for audit.');
        $this->assertNotNull($listing->thumbnail_blocked_reason, 'Brand card must be flagged.');
        Storage::disk('local')->assertExists($listing->thumbnail_path);
    }

    public function test_download_job_leaves_genuine_photo_unblocked(): void
    {
        Storage::fake('local');

        $listing = $this->makeListing('PP-TEST-PHOTO');

        $dataUri = 'data://image/png;base64,' . base64_encode($this->noisePhotoPng());

        (new DownloadListingThumbnail($listing, $dataUri))->handle();

        $listing->refresh();
        $this->assertNotNull($listing->thumbnail_path);
        $this->assertNull($listing->thumbnail_blocked_reason, 'A genuine photo must not be blocked.');
    }
}

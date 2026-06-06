<?php

namespace Tests\Feature\Agents;

use App\Services\Images\AgentPhotoNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Proves the agent-photo guarantee: whatever shape/size is uploaded, the stored
 * file is a uniform 1200×1200 square WebP — so the photo renders identically in
 * the admin grid, agent portal, presentation footers, property sidebar, and the
 * public website agent cards.
 *
 * Spec: .ai/specs/agent-photo.md §5
 */
class AgentPhotoNormalizerTest extends TestCase
{
    private function jpegUpload(int $w, int $h): UploadedFile
    {
        $img = imagecreatetruecolor($w, $h);
        // Two-tone fill so center-crop has something to land on (not validated, just realistic).
        imagefilledrectangle($img, 0, 0, (int) ($w / 2), $h, imagecolorallocate($img, 200, 60, 60));
        imagefilledrectangle($img, (int) ($w / 2), 0, $w, $h, imagecolorallocate($img, 60, 120, 200));

        $tmp = tempnam(sys_get_temp_dir(), 'apn') . '.jpg';
        imagejpeg($img, $tmp, 90);
        imagedestroy($img);

        return new UploadedFile($tmp, 'original.jpg', 'image/jpeg', null, true);
    }

    public function test_non_square_upload_is_stored_as_1200_square_webp(): void
    {
        Storage::fake('public');

        $path = app(AgentPhotoNormalizer::class)->store($this->jpegUpload(2000, 1000), 42);

        $this->assertSame('agents/42/photo.webp', $path);
        Storage::disk('public')->assertExists($path);

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(1200, $info[0], 'width');
        $this->assertSame(1200, $info[1], 'height');
        $this->assertSame(IMAGETYPE_WEBP, $info[2], 'encoded as WebP');
    }

    public function test_portrait_upload_also_becomes_square(): void
    {
        Storage::fake('public');

        $path = app(AgentPhotoNormalizer::class)->store($this->jpegUpload(900, 1600), 7);

        $info = getimagesizefromstring(Storage::disk('public')->get($path));
        $this->assertSame(1200, $info[0]);
        $this->assertSame(1200, $info[1]);
    }

    public function test_replacing_a_photo_deletes_the_previous_file(): void
    {
        Storage::fake('public');

        Storage::disk('public')->put('agents/9/old.jpg', 'stale');

        app(AgentPhotoNormalizer::class)->store($this->jpegUpload(1200, 1200), 9, 'agents/9/old.jpg');

        Storage::disk('public')->assertMissing('agents/9/old.jpg');
        Storage::disk('public')->assertExists('agents/9/photo.webp');
    }

    public function test_source_smaller_than_minimum_is_rejected(): void
    {
        Storage::fake('public');

        $this->expectException(ValidationException::class);
        app(AgentPhotoNormalizer::class)->store($this->jpegUpload(640, 640), 5);
    }

    public function test_encoded_photo_is_within_size_budget(): void
    {
        Storage::fake('public');

        $path = app(AgentPhotoNormalizer::class)->store($this->jpegUpload(2400, 2400), 3);

        $this->assertLessThanOrEqual(
            AgentPhotoNormalizer::MAX_BYTES,
            Storage::disk('public')->size($path),
            'normalized photo should stay within the ~500KB budget'
        );
    }
}

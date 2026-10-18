<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Rules\AgencyLogoFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * 8 Oct 2026 - an agency logo is shown at about 200 x 38 pixels, but QA1's was 10629 x 3543 (37.6 megapixels) and froze a real browser.
 * One platform-wide guard (App\Rules\AgencyLogoFile, not a per-agency setting) now refuses an oversized logo with a plain message on every
 * agency-logo upload; the portal still serves a small copy (App\Support\PortalLogo) of whatever was uploaded earlier.
 */
final class AgencyLogoGuardTest extends TestCase
{
    private function png(int $w, int $h, string $name = 'logo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        $img = imagecreatetruecolor($w, $h);
        imagepng($img, $path, 9);
        imagedestroy($img);

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function errorsFor(?UploadedFile $file): array
    {
        return Validator::make(['logo' => $file], ['logo' => ['nullable', new AgencyLogoFile()]])->errors()->get('logo');
    }

    public function test_a_sensible_logo_passes_and_nothing_uploaded_is_not_an_error(): void
    {
        $this->assertSame([], $this->errorsFor($this->png(800, 250)));
        $this->assertSame([], $this->errorsFor($this->png(AgencyLogoFile::MAX_SIDE_PX, 100)), 'exactly at the limit is fine');
        $this->assertSame([], $this->errorsFor(null));
    }

    public function test_a_huge_picture_is_refused_with_a_plain_sentence_that_says_the_size_and_what_to_do(): void
    {
        $errors = $this->errorsFor($this->png(AgencyLogoFile::MAX_SIDE_PX + 1, 200));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString((AgencyLogoFile::MAX_SIDE_PX + 1) . ' × 200 pixels', $errors[0]);
        $this->assertStringContainsString('no larger than ' . AgencyLogoFile::MAX_SIDE_PX . ' pixels', $errors[0]);
        $this->assertCount(1, $this->errorsFor($this->png(300, AgencyLogoFile::MAX_SIDE_PX + 500)), 'tall as well as wide');
    }

    public function test_a_file_over_two_megabytes_a_non_picture_and_a_broken_upload_are_each_refused_plainly(): void
    {
        $big = UploadedFile::fake()->create('logo.png', AgencyLogoFile::MAX_KB + 10, 'image/png');
        $this->assertStringContainsString('under 2 MB', $this->errorsFor($big)[0]);

        $notAPicture = UploadedFile::fake()->createWithContent('logo.png', 'this is not an image');
        $this->assertSame('The logo must be a JPG, PNG or WebP picture.', $this->errorsFor($notAPicture)[0]);

        $gif = tempnam(sys_get_temp_dir(), 'logo') . '.gif';
        $img = imagecreatetruecolor(50, 50);
        imagegif($img, $gif);
        $this->assertSame('The logo must be a JPG, PNG or WebP picture.', $this->errorsFor(new UploadedFile($gif, 'logo.gif', 'image/gif', null, true))[0], 'a GIF is not allowed');
    }

    public function test_every_agency_logo_upload_goes_through_the_guard(): void
    {
        foreach (['Admin/CompanySettingsController', 'Admin/AgencyController', 'CoreX/SettingsController'] as $controller) {
            $src = (string) file_get_contents(base_path("app/Http/Controllers/{$controller}.php"));
            $this->assertStringContainsString('AgencyLogoFile', $src, "{$controller} must validate the logo with the guard");
            $this->assertStringNotContainsString("'mimes:jpg,jpeg,png,webp', 'max:2048'", $src, "{$controller} still has the old unchecked logo rule");
        }
    }
}

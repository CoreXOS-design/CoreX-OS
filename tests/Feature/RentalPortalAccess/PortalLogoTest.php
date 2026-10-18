<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Support\PortalLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * 8 Oct 2026 - the owner's portal froze a real Chrome twice. The page's script has no render loop (tests/js/portal-shell.test.mjs); the
 * header logo is the agency's ORIGINAL upload - on QA1 a 10629 x 3543 pixel (37.6 megapixel) JPEG drawn at 200 x 38 CSS pixels, decoded
 * in full on every page and every repaint. The portal is now handed a small copy; the original stays for PDFs, mail and the office.
 */
final class PortalLogoTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
    }

    private function p(string $file): string
    {
        return 'agencies/' . $this->agency->id . '/' . $file;
    }

    private function logoOf(int $w, int $h, ?string $path = null): void
    {
        $path ??= 'agencies/' . $this->agency->id . '/logo.jpg';
        $img = imagecreatetruecolor($w, $h);
        // noisy enough that a big picture really is a big file
        for ($i = 0; $i < 400; $i++) {
            imagefilledrectangle($img, random_int(0, $w - 1), random_int(0, $h - 1), random_int(0, $w - 1), random_int(0, $h - 1), imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        Storage::disk('public')->makeDirectory(dirname($path));
        imagejpeg($img, Storage::disk('public')->path($path), 92);
        imagedestroy($img);
        $this->agency->forceFill(['logo_path' => $path])->save();
    }

    public function test_a_huge_logo_is_replaced_in_the_portal_by_a_small_copy_and_the_original_is_untouched(): void
    {
        $this->logoOf(5200, 1700);
        $original = Storage::disk('public')->path($this->p('logo.jpg'));
        $before = md5_file($original);

        $url = PortalLogo::urlFor($this->agency->id);

        $this->assertStringContainsString('/storage/' . $this->p('portal-logo-'), $url);
        $copy = Storage::disk('public')->path(str_replace(asset('storage') . '/', '', $url));
        $this->assertFileExists($copy);
        [$w, $h] = getimagesize($copy);
        $this->assertLessThanOrEqual(PortalLogo::MAX_WIDTH, $w);
        $this->assertLessThanOrEqual(PortalLogo::MAX_HEIGHT, $h);
        $this->assertEqualsWithDelta(5200 / 1700, $w / $h, 0.05, 'same shape');
        $this->assertLessThan(150000, filesize($copy), 'a small file');
        $this->assertSame($before, md5_file($original), 'the original (PDFs, mail, office) is never touched');
        $this->assertSame($url, PortalLogo::urlFor($this->agency->id), 'made once, then reused');
    }

    public function test_an_already_small_logo_is_served_as_it_is_and_odd_cases_never_leave_the_header_without_a_logo(): void
    {
        $this->logoOf(320, 90);
        $this->assertStringEndsWith('/storage/' . $this->p('logo.jpg'), PortalLogo::urlFor($this->agency->id));

        $this->agency->forceFill(['logo_path' => $this->p('not-there.jpg')])->save();
        $this->assertStringEndsWith('/storage/' . $this->p('not-there.jpg'), PortalLogo::urlFor($this->agency->id), 'a missing file falls back to the stored URL');

        file_put_contents(Storage::disk('public')->path($this->p('broken.jpg')), 'not an image');
        $this->agency->forceFill(['logo_path' => $this->p('broken.jpg')])->save();
        $this->assertStringEndsWith('/storage/' . $this->p('broken.jpg'), PortalLogo::urlFor($this->agency->id), 'an unreadable file falls back too');

        $this->agency->forceFill(['logo_path' => null])->save();
        $this->assertNull(PortalLogo::urlFor($this->agency->id));
    }

    public function test_the_portal_page_and_the_branding_endpoint_hand_out_the_small_copy(): void
    {
        $this->logoOf(5200, 1700);
        $this->clientUserFor($this->tenant);   // the link's address must belong to a portal login for the page to know the agency

        $page = $this->get('/portal?email=' . rawurlencode($this->tenant->email))->assertOk();
        $this->assertStringContainsString('portal-logo-', $page->viewData('branding')['logo_url']);

        \Laravel\Sanctum\Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
        $this->getJson('/api/v1/client/rentals/branding')->assertOk()->assertJsonPath('branding.logo_url', fn ($u) => str_contains((string) $u, 'portal-logo-'));
    }
}

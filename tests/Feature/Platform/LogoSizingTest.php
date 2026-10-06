<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyLogo;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementCompany;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Letterhead logo sizing — ONE rule for every place the letterhead is used (spec platform-company-profile §4a):
 * fixed display height (60px / 45pt), width follows the shape, never wider than 45% of the header, never upscaled past the image's own size.
 */
class LogoSizingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-company-test');
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function useLogo(int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 11, 42, 74));
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        Storage::disk('local')->put('platform-company-test/logo-' . $w . 'x' . $h . '.png', $png);
        $logo = PlatformCompanyLogo::create(['path' => 'platform-company-test/logo-' . $w . 'x' . $h . '.png', 'original_name' => 'logo.png', 'mime' => 'image/png', 'size' => strlen($png), 'uploaded_by' => null]);
        PlatformCompany::current()->update(['logo_id' => $logo->id]);
    }

    public static function shapes(): array
    {
        // [natural w, natural h, expected px w, px h, expected pt w, pt h]
        return [
            'the uploaded letterhead banner 1983x793 (2.5:1)' => [1983, 793, 150, 60, 112.53, 45.0],
            'a normal 3:1 logo 900x300' => [900, 300, 180, 60, 135.0, 45.0],
            'a square logo 1000x1000' => [1000, 1000, 60, 60, 45.0, 45.0],
            'a very wide strip 5000x100 is capped at 45% of the header' => [5000, 100, 342, 7, 226.35, 4.53],
            'a small logo 40x20 is never upscaled' => [40, 20, 40, 20, 30.0, 15.0],
        ];
    }

    /** @dataProvider shapes */
    public function test_the_size_rule(int $w, int $h, int $pxW, int $pxH, float $ptW, float $ptH): void
    {
        $this->useLogo($w, $h);
        $c = PlatformCompany::current();
        $this->assertSame(['w' => $pxW, 'h' => $pxH], $c->logoSizePx(760));
        $box = $c->logoBoxPt();
        $this->assertEqualsWithDelta($ptW, $box['w'], 0.05);
        $this->assertEqualsWithDelta($ptH, $box['h'], 0.05);
        $this->assertEquals($box, (new AgreementCompany())->logoBoxPt(), 'the agreement adapter uses the same rule');
        $this->assertLessThanOrEqual(760 * 0.45 + 0.5, $c->logoSizePx(760)['w'], 'never wider than 45% of the header');
        $this->assertLessThanOrEqual(60, $c->logoSizePx(760)['h']);
    }

    public function test_the_built_in_vector_logo_follows_the_same_rule(): void
    {
        $c = PlatformCompany::current(); // built-in 360x96 wordmark
        $this->assertSame(['w' => 225, 'h' => 60], $c->logoSizePx(760));
        $this->assertEqualsWithDelta(168.75, $c->logoBoxPt()['w'], 0.01);
        $this->assertEquals(45.0, $c->logoBoxPt()['h']);
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    public function test_every_place_the_letterhead_is_used_applies_the_rule(): void
    {
        $this->useLogo(1983, 793);
        $owner = $this->owner();
        $c = PlatformCompany::current();

        // agreement sheets (recipient web page; the owner preview and RR screens use the same partial)
        Mail::fake();
        $doc = app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'take_on_month' => now()->format('Y-m')], $owner->id);
        $token = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
        $html = $this->get(route('platform-esign.agreement.show', $token))->assertOk()->getContent();
        $this->assertStringContainsString('class="sheet-logo" width="150" height="60" style="width:150px; height:60px;"', $html);
        $this->assertSame(substr_count($html, 'class="sheet"'), substr_count($html, 'class="sheet-logo"'));
        $this->assertStringContainsString('.sheet-head .sheet-logo { max-width: 45%;', $html);

        // owner preview + RR countersign screen
        $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()->assertSee('class="sheet-logo" width="150" height="60"', false);

        // /legal
        $this->get(route('public.agreement-terms'))->assertOk()->assertSee('width="150" height="60" style="width:150px; height:60px;"', false);

        // email signature block
        $this->assertStringContainsString('width="150" height="60" style="display:block; width:150px; height:60px;"', $c->emailSignatureHtml());

        // company letterhead partial: web (px) and pdf (pt)
        $this->assertStringContainsString('width="150" height="60" style="width:150px; height:60px; max-width:100%; display:block;"', $c->letterheadHtml('web'));
        $box = $c->logoBoxPt();
        $this->assertEqualsWithDelta(112.53, $box['w'], 0.05);
        $this->assertStringContainsString('width="' . $box['w'] . '" height="' . $box['h'] . '" style="width:' . $box['w'] . 'pt; height:' . $box['h'] . 'pt; display:block;"', $c->letterheadHtml('pdf'));

        // both PDFs: the header box handed to the PDF views is the same rule, and the views no longer carry the old fixed 34pt
        $ctx = app(AgreementService::class)->context($doc->fresh());
        $this->assertEquals($box, $ctx['company']->logoBoxPt());
        foreach (['agreement', 'agreement-attestation'] as $view) {
            $src = file_get_contents(resource_path('views/platform-esign/pdf/' . $view . '.blade.php'));
            $this->assertStringNotContainsString('height: 34pt', $src, $view);
            $this->assertStringContainsString("\$logoBox['w']", $src, $view);
        }
    }

    public function test_the_company_page_shows_the_recommended_logo_hint_and_the_image_size(): void
    {
        $this->useLogo(1983, 793);
        $html = $this->actingAs($this->owner())->get(route('admin.platform-company.index'))->assertOk()->getContent();
        $this->assertStringContainsString('id="pc-logo-hint"', $html);
        $this->assertStringContainsString('Recommended logo:', $html);
        $this->assertStringContainsString('900 × 300 px', $html);
        $this->assertStringContainsString('no empty space', $html);
        $this->assertStringContainsString('300 px tall', $html);
        $this->assertStringContainsString('Image size: 1983 × 793 px (shape 2.5 : 1)', $html);
    }
}

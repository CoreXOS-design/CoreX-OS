<?php

namespace Tests\Feature\Platform;

use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyAudit;
use App\Models\Platform\PlatformCompanyLogo;
use App\Models\PlatformEsign\Document;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit fixes for the Platform Company profile (audit A, 2026-10-06): public logo exposure, sender-address hardening,
 * logo pixel cap + bounded PDF/preview payloads, array-input 422s, blank-row validation, no false "Logo restored".
 */
class PlatformCompanyAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-company');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function form(array $over = []): array
    {
        $c = PlatformCompany::current();

        return array_replace([
            'version' => $c->version,
            'legal_name' => $c->legal_name, 'trading_name' => $c->trading_name, 'registration_number' => $c->registration_number,
            'vat_registered' => '0', 'vat_number' => '',
            'directors' => $c->directors,
            'physical_address' => $c->physical_address, 'postal_address' => '',
            'email_general' => $c->email_general, 'email_support' => $c->email_support, 'email_accounts' => '',
            'send_from_address' => $c->send_from_address, 'send_from_name' => $c->send_from_name,
            'phones' => $c->phones, 'websites' => $c->websites,
            'strap_line' => '', 'letterhead_footer' => '', 'email_signature_html' => '',
            'bank_details' => ['bank_name' => '', 'account_holder' => '', 'account_number' => '', 'branch_code' => '', 'account_type' => '', 'reference_note' => ''],
        ], $over);
    }

    private function pngBytes(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 11, 42, 74));
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function png(int $w = 200, int $h = 80): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($path, $this->pngBytes($w, $h));

        return new UploadedFile($path, 'test-logo.png', 'image/png', null, true);
    }

    private function storedLogo(int $w = 200, int $h = 80): PlatformCompanyLogo
    {
        $bytes = $this->pngBytes($w, $h);
        $path = 'platform-company/logos/' . uniqid() . '.png';
        Storage::disk('local')->put($path, $bytes);

        return PlatformCompanyLogo::create(['path' => $path, 'original_name' => 'l.png', 'mime' => 'image/png', 'size' => strlen($bytes), 'uploaded_by' => null]);
    }

    // ── A-F3 ───────────────────────────────────────────────────────────────

    public function test_the_public_logo_url_serves_only_current_builtin_or_document_pinned_versions(): void
    {
        $old = $this->storedLogo(200, 80);
        $pinned = $this->storedLogo(210, 80);
        $current = $this->storedLogo(220, 80);
        PlatformCompany::current()->update(['logo_id' => $current->id]);
        Document::create(['agency_id' => null, 'title' => 'Sent agreement', 'status' => 'sent', 'source' => 'webdoc', 'body_html_snapshot' => '<p>x</p>',
            'company_snapshot' => ['logo_id' => $pinned->id]]);

        $this->get(route('platform-company.logo'))->assertOk()->assertHeader('Content-Type', 'image/png');                    // no l = current
        $this->get(route('platform-company.logo', ['l' => $current->id]))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
        $this->get(route('platform-company.logo', ['l' => $pinned->id]))->assertOk();                                          // pinned by a sent document
        $this->get(route('platform-company.logo', ['l' => 0]))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');     // built-in
        $this->get(route('platform-company.logo', ['l' => $old->id]))->assertNotFound();                                       // superseded + unpinned: not enumerable
        $this->get(route('platform-company.logo', ['l' => 99999]))->assertNotFound();
        $this->get(route('platform-company.logo', ['l' => 'abc']))->assertOk()->assertHeader('Content-Type', 'image/svg+xml'); // junk reads as built-in, never another version
    }

    // ── A-F4 ───────────────────────────────────────────────────────────────

    public function test_the_sending_address_must_be_a_bare_lowercase_mailbox(): void
    {
        $this->actingAs($this->owner());

        foreach (['Evil <evil@x.test>', 'a@b.test, c@d.test', "a@b.test\r\nBcc: x@y.test", 'no-at-sign', '"quoted"@x.test', 'a b@x.test'] as $bad) {
            $this->put(route('admin.platform-company.update'), $this->form(['send_from_address' => $bad]))->assertSessionHasErrors('send_from_address');
        }
        $this->assertSame('admin@corexos.co.za', PlatformCompany::current()->send_from_address);

        $this->put(route('admin.platform-company.update'), $this->form(['send_from_address' => '  Sales@CoreXOS.co.za ']))->assertSessionHasNoErrors();
        $this->assertSame('sales@corexos.co.za', PlatformCompany::current()->send_from_address);
    }

    public function test_the_sender_name_cannot_carry_line_breaks_or_angle_brackets(): void
    {
        $this->actingAs($this->owner());

        foreach (["Evil\r\nBcc: x@y.test", 'Bob <bob@x.test>', 'Say "hi"'] as $bad) {
            $this->put(route('admin.platform-company.update'), $this->form(['send_from_name' => $bad]))->assertSessionHasErrors('send_from_name');
        }
        $this->put(route('admin.platform-company.update'), $this->form(['send_from_name' => 'CoreX OS — Accounts']))->assertSessionHasNoErrors();
    }

    public function test_a_sending_domain_that_is_not_the_mail_domain_shows_a_warning_but_still_saves(): void
    {
        config(['mail.from.address' => 'noreply@corexos.co.za', 'app.url' => 'https://corexos.co.za']);
        $this->actingAs($this->owner());

        $this->get(route('admin.platform-company.index'))->assertOk()->assertDontSee('is not the domain this system sends mail from');

        $this->put(route('admin.platform-company.update'), $this->form(['send_from_address' => 'someone@gmail.com']))->assertSessionHasNoErrors();
        $this->assertSame('someone@gmail.com', PlatformCompany::current()->send_from_address);
        $this->get(route('admin.platform-company.index'))->assertOk()->assertSee('gmail.com, which is not the domain this system sends mail from');
    }

    // ── A-F5 ───────────────────────────────────────────────────────────────

    public function test_a_logo_over_the_pixel_cap_is_refused_and_one_at_the_cap_is_accepted(): void
    {
        $this->actingAs($this->owner());

        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png(2001, 100)])->assertSessionHasErrors('logo');
        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png(100, 2001)])->assertSessionHasErrors('logo');
        $this->assertSame([], Storage::disk('local')->files('platform-company/logos'), 'a refused logo leaves no file behind');

        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png(2000, 400)])->assertSessionHasNoErrors();
        $this->assertNotNull(PlatformCompany::current()->logo_id);
    }

    public function test_a_large_logo_is_downscaled_for_the_pdf_but_not_altered_on_disk(): void
    {
        $big = $this->storedLogo(1800, 600);
        $company = PlatformCompany::current();
        $company->update(['logo_id' => $big->id]);

        $uri = $company->fresh()->logoDataUri();
        $bytes = base64_decode(substr($uri, strpos($uri, ',') + 1));
        $info = getimagesizefromstring($bytes);
        $this->assertSame(PlatformCompany::PDF_LOGO_MAX_SIDE_PX, $info[0], 'longest side bounded for DomPDF');
        $this->assertSame(333, $info[1], 'shape preserved');
        $this->assertSame([1800, 600], array_slice(getimagesizefromstring(Storage::disk('local')->get($big->path)), 0, 2), 'the stored original is untouched');

        $small = $this->storedLogo(300, 100);
        $company->update(['logo_id' => $small->id]);
        $uri = $company->fresh()->logoDataUri();
        $this->assertSame([300, 100], array_slice(getimagesizefromstring(base64_decode(substr($uri, strpos($uri, ',') + 1))), 0, 2), 'a small logo is embedded as-is');
    }

    public function test_the_preview_json_carries_the_logo_by_url_not_as_base64(): void
    {
        $this->actingAs($this->owner());
        $logo = $this->storedLogo(1500, 500);
        PlatformCompany::current()->update(['logo_id' => $logo->id]);

        $json = $this->postJson(route('admin.platform-company.preview'), ['trading_name' => 'Preview Co'])->assertOk()->json();

        $this->assertStringNotContainsString('data:image', $json['pdf']);
        $this->assertStringContainsString('platform-company/logo?l=' . $logo->id, $json['pdf']);
        $this->assertStringContainsString('Preview Co', $json['pdf']);
        $this->assertLessThan(20000, strlen(json_encode($json)), 'a preview refresh is a few KB, not megabytes');

        // The real PDF context still embeds the bytes (DomPDF fetches nothing).
        $this->assertStringContainsString('data:image/png;base64', PlatformCompany::current()->letterheadHtml('pdf'));
    }

    // ── A-INFO ─────────────────────────────────────────────────────────────

    public function test_restoring_the_already_current_logo_says_so_and_writes_no_audit_row(): void
    {
        $this->actingAs($this->owner());
        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png()])->assertSessionHasNoErrors();
        $current = PlatformCompany::current()->logo_id;
        $version = PlatformCompany::current()->version;
        $audits = PlatformCompanyAudit::count();

        $this->post(route('admin.platform-company.logo.restore', $current))
            ->assertSessionMissing('success')->assertSessionHas('warning');

        $this->assertSame($version, PlatformCompany::current()->version);
        $this->assertSame($audits, PlatformCompanyAudit::count());
    }

    public function test_array_input_in_a_text_field_is_a_422_not_a_500(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form(['legal_name' => ['x']]))->assertSessionHasErrors('legal_name');
        $this->put(route('admin.platform-company.update'), $this->form(['email_general' => ['a@b.test'], 'send_from_address' => ['x']]))->assertSessionHasErrors(['email_general', 'send_from_address']);
        $this->put(route('admin.platform-company.update'), $this->form(['directors' => [['name' => ['x'], 'title' => 'T']], 'bank_details' => ['account_number' => ['1']]]))->assertSessionHasErrors('directors.0.name');
        $this->postJson(route('admin.platform-company.preview'), ['legal_name' => ['x'], 'phones' => 'oops', 'directors' => [['name' => ['y']]]])->assertOk();
        $this->assertSame('RR Technologies (Pty) Ltd', PlatformCompany::current()->legal_name);
    }

    public function test_a_director_or_phone_row_with_a_title_or_label_but_no_name_or_number_is_reported(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form(['directors' => [['name' => '', 'title' => 'Director']]]))
            ->assertSessionHasErrors('directors.0.name');
        $this->put(route('admin.platform-company.update'), $this->form(['phones' => [['label' => 'Cell', 'number' => '']]]))
            ->assertSessionHasErrors('phones.0.number');

        // A fully blank row is still silently dropped.
        $this->put(route('admin.platform-company.update'), $this->form(['directors' => [['name' => '', 'title' => ''], ['name' => 'Jane Doe', 'title' => 'Director']], 'phones' => [['label' => '', 'number' => '']]]))
            ->assertSessionHasNoErrors();
        $this->assertSame([['name' => 'Jane Doe', 'title' => 'Director']], PlatformCompany::current()->directors);
        $this->assertSame([], PlatformCompany::current()->phones);
    }
}

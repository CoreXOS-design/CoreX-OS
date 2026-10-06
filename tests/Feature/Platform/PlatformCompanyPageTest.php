<?php

namespace Tests\Feature\Platform;

use App\Models\Agency;
use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyAudit;
use App\Models\Platform\PlatformCompanyLogo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Platform Company Profile — the owner page. Spec: .ai/specs/platform-company-profile.md §5, §9.
 */
class PlatformCompanyPageTest extends TestCase
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

    /** The full form as the browser posts it — seeded values, with $over applied on top. */
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

    private function png(int $w = 200, int $h = 80): UploadedFile
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bytes = ob_get_clean();
        $path = tempnam(sys_get_temp_dir(), 'logo') . '.png';
        file_put_contents($path, $bytes);

        return new UploadedFile($path, 'test-logo.png', 'image/png', null, true);
    }

    public function test_only_the_owner_reaches_the_page_and_its_actions(): void
    {
        $agency = Agency::create(['name' => 'Caprivi', 'slug' => 'caprivi-' . uniqid()]);
        $agent = User::factory()->create(['role' => 'agent', 'agency_id' => $agency->id]);

        $this->actingAs($agent);
        $this->get(route('admin.platform-company.index'))->assertForbidden();
        $this->put(route('admin.platform-company.update'), $this->form())->assertForbidden();
        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png()])->assertForbidden();
        $this->post(route('admin.platform-company.logo.restore', 'built-in'))->assertForbidden();
        $this->post(route('admin.platform-company.preview'), [])->assertForbidden();

        auth()->logout();
        $this->get(route('admin.platform-company.index'))->assertRedirect();
        $this->assertSame(1, PlatformCompany::current()->version, 'nothing changed');
    }

    public function test_owner_sees_the_page_with_seeded_values_and_empty_history(): void
    {
        $this->actingAs($this->owner());

        $res = $this->get(route('admin.platform-company.index'));
        $res->assertOk()->assertSee('RR Technologies (Pty) Ltd')->assertSee('076 423 2426')->assertSee('No changes recorded yet');
        $res->assertSee('Using the built-in CoreX OS logo');
    }

    public function test_save_updates_the_record_writes_an_audit_row_and_bumps_the_version(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $phones = PlatformCompany::current()->phones;
        $phones[1]['number'] = '  076 111 2222 ';

        $this->put(route('admin.platform-company.update'), $this->form(['phones' => $phones, 'email_accounts' => '  ACCOUNTS@CoreXOS.co.za ']))
            ->assertRedirect(route('admin.platform-company.index'))->assertSessionHas('success');

        $c = PlatformCompany::current();
        $this->assertSame('076 111 2222', $c->phones[1]['number']);
        $this->assertSame('accounts@corexos.co.za', $c->email_accounts);
        $this->assertSame(2, $c->version);
        $a = PlatformCompanyAudit::query()->latest('id')->first();
        $this->assertSame($owner->id, $a->user_id);
        $this->assertSame('updated', $a->action);
        $this->assertArrayHasKey('phones', $a->changes);
        $this->assertArrayHasKey('email_accounts', $a->changes);
        $this->assertSame('076 618 5578', $a->changes['phones']['from'][1]['number']);

        $this->get(route('admin.platform-company.index'))->assertSee('Changed:')->assertSee('076 111 2222');
    }

    public function test_the_sending_address_and_name_are_saved_and_audited(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);
        $this->assertSame('admin@corexos.co.za', PlatformCompany::current()->send_from_address);
        $this->assertSame('CoreX OS — RR Technologies', PlatformCompany::current()->send_from_name);

        $this->put(route('admin.platform-company.update'), $this->form(['send_from_address' => '  Contracts@CoreXOS.co.za ', 'send_from_name' => ' CoreX OS Contracts ']))
            ->assertRedirect(route('admin.platform-company.index'))->assertSessionHas('success');

        $c = PlatformCompany::current();
        $this->assertSame('contracts@corexos.co.za', $c->send_from_address);
        $this->assertSame('CoreX OS Contracts', $c->send_from_name);
        $a = PlatformCompanyAudit::query()->latest('id')->first();
        $this->assertArrayHasKey('send_from_address', $a->changes);
        $this->assertArrayHasKey('send_from_name', $a->changes);
        $this->assertSame('contracts@corexos.co.za', $c->mailFrom()->address);
        $this->get(route('admin.platform-company.index'))->assertSee('Sending address');
    }

    public function test_saving_without_changes_writes_nothing(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form())->assertSessionHas('warning', 'Nothing was changed.');

        $this->assertSame(0, PlatformCompanyAudit::query()->count());
        $this->assertSame(1, PlatformCompany::current()->version);
    }

    public function test_invalid_input_is_rejected_with_plain_messages_and_nothing_is_saved(): void
    {
        $this->actingAs($this->owner());
        $cases = [
            'legal_name'      => [['legal_name' => '   '], 'legal_name'],
            'email'           => [['email_general' => 'not-an-email'], 'email_general'],
            'support email'   => [['email_support' => 'nope'], 'email_support'],
            'sending address' => [['send_from_address' => 'nope'], 'send_from_address'],
            'sending address blank' => [['send_from_address' => ''], 'send_from_address'],
            'sender name blank' => [['send_from_name' => '  '], 'send_from_name'],
            'vat missing'     => [['vat_registered' => '1', 'vat_number' => ''], 'vat_number'],
            'vat malformed'   => [['vat_registered' => '1', 'vat_number' => '12345'], 'vat_number'],
            'phone malformed' => [['phones' => [['label' => 'x', 'number' => 'call me!']]], 'phones.0.number'],
            'website'         => [['websites' => ['not a site']], 'websites.0'],
            'bank account'    => [['bank_details' => ['account_number' => 'abc']], 'bank_details.account_number'],
            'no version'      => [['version' => ''], 'version'],
        ];
        foreach ($cases as $name => [$over, $key]) {
            $this->put(route('admin.platform-company.update'), $this->form($over))->assertSessionHasErrors($key);
        }
        $this->assertSame(0, PlatformCompanyAudit::query()->count());
        $this->assertSame(1, PlatformCompany::current()->version);
    }

    public function test_vat_registered_with_a_number_saves_and_appears_on_the_letterhead(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form(['vat_registered' => '1', 'vat_number' => '4123 456 789']))->assertSessionHasNoErrors();

        $c = PlatformCompany::current();
        $this->assertTrue($c->vat_registered);
        $this->assertSame('4123456789', $c->vat_number);
        $this->assertStringContainsString('VAT no 4123456789', $c->letterheadHtml('pdf'));
    }

    public function test_a_stale_form_is_refused_not_overwritten(): void
    {
        $this->actingAs($this->owner());
        $stale = $this->form();

        $this->put(route('admin.platform-company.update'), $this->form(['strap_line' => 'First save']))->assertSessionHasNoErrors();
        $this->put(route('admin.platform-company.update'), array_replace($stale, ['strap_line' => 'Second (stale) save']))->assertSessionHasErrors('version');

        $this->assertSame('First save', PlatformCompany::current()->strap_line);
    }

    public function test_bank_details_are_encrypted_at_rest_and_never_logged_in_clear(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form(['bank_details' => [
            'bank_name' => 'FNB', 'account_holder' => 'RR Technologies', 'account_number' => '6200 1234 567', 'branch_code' => '250655', 'account_type' => 'cheque', 'reference_note' => '',
        ]]))->assertSessionHasNoErrors();

        $raw = \DB::table('platform_company')->value('bank_details');
        $this->assertStringNotContainsString('62001234567', (string) $raw);
        $this->assertSame('62001234567', PlatformCompany::current()->bank_details['account_number']);
        $audit = json_encode(PlatformCompanyAudit::query()->latest('id')->first()->changes);
        $this->assertStringNotContainsString('62001234567', $audit);
        $this->assertStringNotContainsString('FNB', $audit);
        $this->assertStringNotContainsString('62001234567', PlatformCompany::current()->letterheadHtml('pdf'));
    }

    public function test_email_signature_is_sanitised_on_save(): void
    {
        $this->actingAs($this->owner());

        $this->put(route('admin.platform-company.update'), $this->form(['email_signature_html' => '<p onclick="x()">Hi<script>alert(1)</script></p>']))->assertSessionHasNoErrors();

        $stored = PlatformCompany::current()->email_signature_html;
        $this->assertStringNotContainsString('script', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringContainsString('Hi', $stored);
    }

    public function test_logo_upload_replaces_and_keeps_the_previous_version_restorable(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png(200, 80)])->assertSessionHasNoErrors()->assertSessionHas('success');
        $first = PlatformCompany::current()->logo_id;
        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png(300, 100)])->assertSessionHasNoErrors();
        $second = PlatformCompany::current()->logo_id;

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second);
        $this->assertSame(2, PlatformCompanyLogo::query()->count());
        $this->assertTrue(Storage::disk('local')->exists(PlatformCompanyLogo::find($first)->path), 'earlier logo file kept');
        $this->assertSame('image/png', PlatformCompany::current()->logoFile()['mime']);
        $this->assertStringContainsString('l=' . $second, PlatformCompany::current()->letterheadHtml('web'));
        $this->assertStringContainsString('src="data:image/png;base64,', PlatformCompany::current()->letterheadHtml('pdf'));

        $this->post(route('admin.platform-company.logo.restore', $first))->assertSessionHas('success');
        $this->assertSame($first, PlatformCompany::current()->logo_id);
        $this->post(route('admin.platform-company.logo.restore', 'built-in'));
        $this->assertNull(PlatformCompany::current()->logo_id);
        $this->assertSame('image/svg+xml', PlatformCompany::current()->logoFile()['mime']);

        $this->assertSame(['logo_uploaded', 'logo_uploaded', 'logo_restored', 'logo_restored'], PlatformCompanyAudit::query()->orderBy('id')->pluck('action')->all());
        $this->get(route('admin.platform-company.logo.version', $first))->assertOk()->assertHeader('Content-Type', 'image/png');
    }

    public function test_logo_rejects_wrong_type_oversize_unsafe_svg_and_bad_images(): void
    {
        $this->actingAs($this->owner());
        $bad = [
            'gif/pdf'    => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'too big'    => UploadedFile::fake()->create('logo.png', 3000, 'image/png'),
            'not image'  => UploadedFile::fake()->createWithContent('logo.png', 'this is not a png'),
            'tiny'       => $this->png(10, 10),
            'svg script' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><script>alert(1)</script></svg>'),
            'svg no size' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><rect width="5" height="5"/></svg>'),
        ];
        foreach ($bad as $name => $file) {
            $this->post(route('admin.platform-company.logo.store'), ['logo' => $file])->assertSessionHasErrors('logo');
        }
        $this->post(route('admin.platform-company.logo.store'), [])->assertSessionHasErrors('logo');
        $this->assertSame(0, PlatformCompanyLogo::query()->count());
        $this->assertNull(PlatformCompany::current()->logo_id);
        $this->assertSame([], Storage::disk('local')->files('platform-company/logos'), 'no orphan files after refused uploads');
    }

    public function test_a_good_svg_logo_is_accepted(): void
    {
        $this->actingAs($this->owner());
        $svg = UploadedFile::fake()->createWithContent('brand.svg', '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 40"><rect width="100" height="40" fill="#0b2a4a"/></svg>');

        $this->post(route('admin.platform-company.logo.store'), ['logo' => $svg])->assertSessionHasNoErrors();

        $this->assertSame('image/svg+xml', PlatformCompany::current()->logoFile()['mime']);
    }

    public function test_preview_reflects_unsaved_values_and_stores_nothing(): void
    {
        $this->actingAs($this->owner());

        $res = $this->postJson(route('admin.platform-company.preview'), $this->form([
            'phones' => [['label' => 'Cell', 'number' => '082 000 0000']], 'strap_line' => 'Preview strap', 'email_signature_html' => '<b>Mine</b><script>x</script>',
        ]));

        $res->assertOk();
        $this->assertStringContainsString('082 000 0000', $res->json('web'));
        $this->assertStringContainsString('082 000 0000', $res->json('pdf'));
        $this->assertStringContainsString('Preview strap', $res->json('web'));
        $this->assertStringContainsString('<b>Mine</b>', $res->json('signature'));
        $this->assertStringNotContainsString('script', $res->json('signature'));
        $this->assertNotEmpty($res->json('standard'));
        $this->assertSame('076 618 5578', PlatformCompany::current()->phones[1]['number'], 'preview never saves');
        $this->assertSame(0, PlatformCompanyAudit::query()->count());
    }

    public function test_history_filter_and_sidebar_link(): void
    {
        $this->actingAs($this->owner());
        $this->put(route('admin.platform-company.update'), $this->form(['strap_line' => 'Hello']));
        $this->post(route('admin.platform-company.logo.store'), ['logo' => $this->png()]);

        $this->get(route('admin.platform-company.index', ['action' => 'logo_uploaded']))
            ->assertOk()->assertSee('Uploaded a new logo')->assertDontSee('Changed: Letterhead strap line');
        // The sidebar link, checked on a page that is NOT this one (so the page's own banner cannot satisfy it).
        $this->get(route('platform-esign.hub'))->assertSee('Company &mdash; RR Technologies', false)->assertSee(route('admin.platform-company.index'), false);
    }
    public function test_platform_esign_emails_carry_the_company_signature_and_footer(): void
    {
        $c = PlatformCompany::current();
        $c->update(['strap_line' => null, 'email_signature_html' => '<p>Kind regards, <b>The CoreX team</b></p>']);

        $invite = view('platform-esign.email.invite', [
            'doc' => (object) ['title' => 'Subscription Agreement', 'expires_at' => null],
            'signer' => (object) ['name' => 'Pat', 'role_label' => 'Agency Principal'], 'signUrl' => 'https://example.test/sign/x',
        ])->render();
        $signed = view('platform-esign.email.signed', [
            'doc' => (object) ['title' => 'Subscription Agreement', 'signers' => collect([(object) ['name' => 'Pat']]), 'completed_at' => now(), 'sealed_pdf_path' => null],
        ])->render();

        foreach ([$invite, $signed] as $html) {
            $this->assertStringContainsString('Kind regards, <b>The CoreX team</b>', $html);
            $this->assertStringContainsString('CoreX OS &middot; www.corexweb.co.za', $html);
            $this->assertStringNotContainsString('corexos.co.za</td>', $html, 'old hard-coded footer is gone');
        }

        // Cleared signature → the standard one, built from the record (never blank).
        $c->update(['email_signature_html' => null]);
        $this->assertStringContainsString('support@corexos.co.za', view('platform-esign.email._signature')->render());
    }
}

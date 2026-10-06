<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementCountersignReminderMail;
use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Mail\PlatformEsign\InviteMail;
use App\Mail\PlatformEsign\SendsFromPlatformCompany;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Platform\PlatformCompany;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every Platform E-Sign / Subscription Agreement email is sent FROM the platform company record
 * (Platform Company Profile → Sending address / Sender name), Reply-To the owner who sent it, and carries no agency's address.
 */
class AgreementMailSenderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The box-wide From is an agency's address on QA1 — the platform mail must never fall back to it.
        config(['mail.from.address' => 'system@hfcoastal.co.za', 'mail.from.name' => 'Home Finders Coastal']);
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel', 'email' => 'johan@corexos.co.za']);
    }

    private function sent(): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $this->owner()->id);
    }

    /** @return array<string,\Illuminate\Mail\Mailable> */
    private function allMails(Document $doc): array
    {
        $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();

        return [
            'agreement invite' => new AgreementInviteMail($doc, $signer),
            'agreement reminder' => new AgreementInviteMail($doc, $signer, true),
            'agreement received' => new AgreementReceivedMail($doc),
            'countersign reminder' => new AgreementCountersignReminderMail($doc, 2),
            'platform invite' => new InviteMail($doc, $signer),
            'signed copy' => new SignedMail($doc),
            'agreement completion (agency)' => new \App\Mail\PlatformEsign\AgreementSignedMail($doc, 'agency', 'https://example.test/a'),
            'agreement completion (rr)' => new \App\Mail\PlatformEsign\AgreementSignedMail($doc, 'rr', 'https://example.test/r'),
        ];
    }

    public function test_the_company_record_defaults_to_the_corex_address(): void
    {
        $from = PlatformCompany::current()->mailFrom();
        $this->assertSame('admin@corexos.co.za', $from->address);
        $this->assertSame('CoreX OS — RR Technologies', $from->name);
    }

    public function test_every_platform_email_is_sent_from_the_company_and_replies_to_the_sending_owner(): void
    {
        $doc = $this->sent();
        foreach ($this->allMails($doc) as $label => $mail) {
            $env = $mail->envelope();
            $this->assertSame('admin@corexos.co.za', $env->from->address, $label);
            $this->assertSame('CoreX OS — RR Technologies', $env->from->name, $label);
            $this->assertCount(1, $env->replyTo, $label);
            $this->assertSame('johan@corexos.co.za', $env->replyTo[0]->address, $label);
            $this->assertNotSame('', $env->subject, $label);
        }
    }

    public function test_changing_the_sending_address_on_the_company_page_changes_every_email(): void
    {
        $doc = $this->sent();
        PlatformCompany::current()->update(['send_from_address' => 'contracts@corexos.co.za', 'send_from_name' => 'CoreX OS Contracts']);

        foreach ($this->allMails($doc) as $label => $mail) {
            $this->assertSame('contracts@corexos.co.za', $mail->envelope()->from->address, $label);
            $this->assertSame('CoreX OS Contracts', $mail->envelope()->from->name, $label);
        }
    }

    public function test_a_blank_or_broken_company_address_falls_back_to_the_corex_default_never_to_the_agency_address(): void
    {
        $doc = $this->sent();
        PlatformCompany::current()->forceFill(['send_from_address' => 'not an address', 'send_from_name' => ''])->save();

        $env = (new AgreementInviteMail($doc, Signer::where('document_id', $doc->id)->first()))->envelope();
        $this->assertSame('admin@corexos.co.za', $env->from->address);
        $this->assertSame('CoreX OS — RR Technologies', $env->from->name);
    }

    public function test_no_agency_address_appears_in_any_platform_email_header_or_body(): void
    {
        $doc = $this->sent();
        foreach ($this->allMails($doc) as $label => $mail) {
            $html = $mail->render();
            $this->assertStringNotContainsStringIgnoringCase('hfcoastal', $html, $label);
            $this->assertStringNotContainsStringIgnoringCase('home finders', $html, $label);
            $this->assertStringNotContainsStringIgnoringCase('hfcoastal', json_encode($mail->envelope()->from), $label);
        }
    }

    public function test_the_agreement_invite_carries_the_company_signature(): void
    {
        $doc = $this->sent();
        $html = (new AgreementInviteMail($doc, Signer::where('document_id', $doc->id)->first()))->render();
        $this->assertStringContainsString(PlatformCompany::current()->legal_name, $html);
        $this->assertStringContainsString('admin@corexos.co.za', $html);
    }

    public function test_every_platform_esign_mail_class_uses_the_company_sender(): void
    {
        foreach (glob(app_path('Mail/PlatformEsign/*Mail.php')) as $file) {
            $class = 'App\\Mail\\PlatformEsign\\' . basename($file, '.php');
            $this->assertContains(SendsFromPlatformCompany::class, class_uses($class), $class . ' must send from the platform company record');
        }
    }
}

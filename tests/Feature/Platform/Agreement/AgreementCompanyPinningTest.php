<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Models\Platform\PlatformCompany;
use App\Models\Platform\PlatformCompanyLogo;
use App\Models\PlatformEsign\Document;
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
 * Company details on the Subscription Agreement come from the platform company record, and a SENT agreement keeps the
 * letterhead / party details it was sent with (spec .ai/specs/platform-company-profile.md §7a).
 */
class AgreementCompanyPinningTest extends TestCase
{
    use RefreshDatabase;

    private const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        Storage::disk('local')->deleteDirectory('platform-company-test');
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

    private function send(User $owner): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $owner->id);
    }

    private function token(Document $doc): string
    {
        return Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('token');
    }

    /** Edit the company record the way the company page would. */
    private function changeCompany(array $to): void
    {
        PlatformCompany::current()->update($to);
    }

    private function uploadLogo(): PlatformCompanyLogo
    {
        Storage::disk('local')->put('platform-company-test/logo.png', base64_decode(self::PNG_BYTES));
        $logo = PlatformCompanyLogo::create(['path' => 'platform-company-test/logo.png', 'original_name' => 'logo.png', 'mime' => 'image/png', 'size' => 70, 'uploaded_by' => null]);
        PlatformCompany::current()->update(['logo_id' => $logo->id]);

        return $logo;
    }

    // ── Pinning ────────────────────────────────────────────────────────────

    public function test_send_pins_the_company_details_without_the_bank_details(): void
    {
        $this->changeCompany(['bank_details' => ['bank_name' => 'FNB', 'account_number' => '62001234567']]);
        $doc = $this->send($this->owner());

        $snap = $doc->fresh()->company_snapshot;
        $this->assertSame('RR Technologies (Pty) Ltd', $snap['legal_name']);
        $this->assertSame("3123 San Lameer\nR61 Lower South Coast Road\nSouthbroom, KZN\n4277", $snap['physical_address']);
        $this->assertArrayNotHasKey('bank_details', $snap);
        $this->assertStringNotContainsString('62001234567', json_encode($snap));
    }

    public function test_a_sent_agreement_keeps_its_letterhead_and_party_details_when_the_company_page_changes(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);

        $this->changeCompany([
            'legal_name' => 'Newname Holdings (Pty) Ltd', 'trading_name' => 'Newname',
            'physical_address' => "99 Other Street\nCape Town\n8001", 'email_general' => 'hello@newname.example',
            'phones' => [['label' => 'Telephone', 'number' => '021 555 0000']], 'websites' => ['www.newname.example'],
        ]);

        // The sent agreement still renders what it was sent with …
        $page = $this->get(route('platform-esign.agreement.show', $token))->assertOk();
        $page->assertSee('RR Technologies (Pty) Ltd')->assertSee('3123 San Lameer, R61 Lower South Coast Road, Southbroom, KZN, 4277', false)->assertSee('(039) 004 0125');
        $page->assertDontSee('Newname Holdings')->assertDontSee('99 Other Street')->assertDontSee('021 555 0000');
        $this->assertSame('RR Technologies (Pty) Ltd', AgreementCompany::for($doc->fresh())->legalName());

        // … while a NEW agreement uses the current record.
        $new = $this->send($this->owner());
        $this->assertSame('Newname Holdings (Pty) Ltd', $new->fresh()->company_snapshot['legal_name']);
        $this->get(route('platform-esign.agreement.show', $this->token($new)))->assertOk()
            ->assertSee('Newname Holdings (Pty) Ltd')->assertSee('99 Other Street, Cape Town, 8001', false)->assertDontSee('R61 Lower South Coast Road, Southbroom, KZN', false);
        $this->assertSame('Newname Holdings (Pty) Ltd', $new->signers()->where('role_key', 'r2')->value('role_label'));
    }

    public function test_the_pdf_header_uses_the_pinned_company_not_the_live_one(): void
    {
        $doc = $this->send($this->owner());
        $this->changeCompany(['legal_name' => 'Newname Holdings (Pty) Ltd', 'physical_address' => '99 Other Street']);

        $co = AgreementCompany::for($doc->fresh());
        $html = view('platform-esign.pdf.agreement', [
            'title' => 't', 'logo' => $co->logoDataUri(), 'logoBox' => $co->logoBoxPt(), 'letterhead' => $co->letterhead(), 'brand' => $co->brand(),
            'versionLabel' => 'Version 1.0', 'total' => 1, 'pages' => [], 'mode' => 'pdf', 'initials' => [], 'cert' => null,
        ])->render();

        $this->assertStringContainsString('RR Technologies (Pty) Ltd', $html);
        $this->assertStringContainsString('Southbroom', $html);
        $this->assertStringNotContainsString('Newname', $html);
        $this->assertStringContainsString('src="data:image/svg+xml;base64,', $html);
    }

    public function test_an_agreement_sent_before_pinning_existed_falls_back_to_the_live_record(): void
    {
        $doc = $this->send($this->owner());
        $doc->forceFill(['company_snapshot' => null])->save();
        $this->changeCompany(['legal_name' => 'Newname Holdings (Pty) Ltd']);

        $this->assertSame('Newname Holdings (Pty) Ltd', AgreementCompany::for($doc->fresh())->legalName());
    }

    public function test_the_sealed_pdf_and_the_attestation_pdf_render_with_the_pinned_company_and_an_uploaded_logo(): void
    {
        $this->uploadLogo();
        $doc = $this->send($this->owner());
        $doc->refresh();
        $this->changeCompany(['legal_name' => 'Newname Holdings (Pty) Ltd']);

        $svc = app(AgreementService::class);
        $this->assertSame('RR Technologies (Pty) Ltd', $svc->context($doc)['company']->legalName(), 'the render context carries the pinned company');

        $sealed = $svc->sealedPdf($doc);
        $this->assertStringStartsWith('%PDF', $sealed);
        $this->assertGreaterThan(2, \App\Services\PlatformEsign\Agreement\AgreementPdf::countPdfPages($sealed));

        $m = new \ReflectionMethod($svc, 'wetInkAttestation');
        $m->setAccessible(true);
        $this->assertStringStartsWith('%PDF', $m->invoke($svc, $doc));
    }

    // ── Emails ─────────────────────────────────────────────────────────────

    public function test_invite_and_received_emails_read_the_company_record_and_stay_pinned(): void
    {
        $doc = $this->send($this->owner());
        Mail::assertSent(AgreementInviteMail::class);
        $this->changeCompany([
            'legal_name' => 'Newname Holdings (Pty) Ltd', 'trading_name' => 'Newname', 'email_general' => 'hello@newname.example',
            'email_support' => 'help@newname.example', 'phones' => [['label' => 'Telephone', 'number' => '011 000 0000']], 'websites' => ['www.newname.example'],
        ]);

        $signer = $doc->signers->first();
        $invite = (new AgreementInviteMail($doc->fresh('agency'), $signer))->render();
        $this->assertStringContainsString('RR Technologies (Pty) Ltd has sent you', $invite);
        $this->assertStringContainsString('admin@corexos.co.za', $invite);
        $this->assertStringContainsString('CoreX OS &middot; www.corexweb.co.za', $invite);
        $this->assertStringNotContainsString('johan@corexos.co.za', $invite);
        $this->assertStringNotContainsString('Newname', $invite);
        // The company signature block is the PINNED one (spec platform-company-profile §7a), never today's record.
        $this->assertStringContainsString('Support: support@corexos.co.za', $invite);
        $this->assertStringContainsString('Telephone (039) 004 0125', $invite);
        $this->assertStringNotContainsString('newname.example', $invite);
        $this->assertStringNotContainsString('011 000 0000', $invite);

        $received = (new AgreementReceivedMail($doc->fresh('signers')))->render();
        $this->assertStringContainsString('needs RR Technologies (Pty) Ltd', $received);
        $this->assertStringContainsString('CoreX OS &middot; www.corexweb.co.za', $received);

        $reminder = (new \App\Mail\PlatformEsign\AgreementCountersignReminderMail($doc->fresh('signers'), 2))->render();
        $this->assertStringContainsString('once RR Technologies (Pty) Ltd countersigns', $reminder);
        $this->assertStringContainsString('CoreX OS &middot; www.corexweb.co.za', $reminder);

        // A brand-new agreement's emails carry the NEW details.
        $new = $this->send($this->owner());
        $this->assertStringContainsString('Newname Holdings (Pty) Ltd has sent you', (new AgreementInviteMail($new->fresh('agency'), $new->signers->first()))->render());
        $this->assertStringContainsString('hello@newname.example', (new AgreementInviteMail($new->fresh('agency'), $new->signers->first()))->render());
    }

    // ── Logo ───────────────────────────────────────────────────────────────

    public function test_letterhead_uses_the_uploaded_logo_and_falls_back_to_the_wordmark_when_none(): void
    {
        $before = $this->send($this->owner());
        $this->assertStringContainsString(route('platform-company.logo', ['l' => 0]), $this->get(route('platform-esign.agreement.show', $this->token($before)))->getContent());

        $logo = $this->uploadLogo();
        $after = $this->send($this->owner());

        // New agreement: screen sheets point at the uploaded version, PDF embeds it.
        $this->assertStringContainsString('l=' . $logo->id, $this->get(route('platform-esign.agreement.show', $this->token($after)))->getContent());
        $this->assertStringStartsWith('data:image/png;base64,', AgreementCompany::for($after->fresh())->logoDataUri());

        // The earlier-sent agreement keeps the logo it was sent with (the built-in wordmark).
        $page = $this->get(route('platform-esign.agreement.show', $this->token($before)))->getContent();
        $this->assertStringContainsString(route('platform-company.logo', ['l' => 0]), $page);
        $this->assertStringNotContainsString('l=' . $logo->id, $page);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', AgreementCompany::for($before->fresh())->logoDataUri());

        // The public logo route serves each exact version.
        $this->get(route('platform-company.logo', ['l' => $logo->id]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('platform-company.logo', ['l' => 0]))->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
        $this->get(route('platform-company.logo'))->assertOk()->assertHeader('Content-Type', 'image/png'); // no `l` = current
    }

    public function test_the_pdf_logo_box_has_a_fixed_height_and_a_capped_width(): void
    {
        $this->uploadLogo(); // 1x1 → ratio 1
        $box = (new AgreementCompany())->logoBoxPt();
        $this->assertEquals(34.0, $box['h']);
        $this->assertEquals(34.0, $box['w']);

        $this->changeCompany(['logo_id' => null]);
        $svg = (new AgreementCompany())->logoBoxPt(); // built-in wordmark, 360x96
        $this->assertEquals(34.0, $svg['h']);
        $this->assertEqualsWithDelta(127.5, $svg['w'], 0.01);

        $wide = (new AgreementCompany())->logoBoxPt(34.0, 100.0);
        $this->assertEquals(100.0, $wide['w'], 'a very wide logo is scaled down, never allowed to squeeze the company block');
        $this->assertLessThan(34.0, $wide['h']);
    }

    // ── No hard-coded company details ──────────────────────────────────────

    public function test_no_company_detail_is_hardcoded_outside_the_wording_and_the_seed(): void
    {
        $needles = ['RR Technologies', 'Southbroom', 'San Lameer', '444132', '004 0125', '618 5578', '423 2426', 'corexweb', 'corexos.co.za', 'johan@'];
        // Allowed: docblocks and the two places that quote the pinned legal wording on purpose.
        $allowed = [
            'Services/PlatformEsign/Agreement/AgreementContent.php' => ['RR Technologies initials'],   // matches the legal source text
            'views/platform-esign/agreement/review.blade.php'       => ['For RR Technologies'],        // quotes the wording's signature block label
            'Services/PlatformEsign/Agreement/AgreementCompany.php' => ['who RR Technologies is'],
            'Services/PlatformEsign/Agreement/AgreementRenderer.php' => ['Fixed RR Technologies party details', "'For RR Technologies'"], // 2nd: the contract's own signature-block heading (wording), matched literally
        ];
        $roots = [app_path('Services/PlatformEsign'), app_path('Http/Controllers/PlatformEsign'), app_path('Mail/PlatformEsign'), resource_path('views/platform-esign')];
        $hits = [];
        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $rel = str_replace([app_path() . '/', resource_path() . '/'], '', $file->getPathname());
                foreach (file($file->getPathname()) as $n => $line) {
                    foreach ($needles as $needle) {
                        if (stripos($line, $needle) === false) {
                            continue;
                        }
                        $ok = false;
                        foreach ($allowed as $suffix => $phrases) {
                            if (str_ends_with($rel, $suffix) && array_filter($phrases, fn ($p) => stripos($line, $p) !== false)) {
                                $ok = true;
                            }
                        }
                        if (!$ok) {
                            $hits[] = $rel . ':' . ($n + 1) . ' [' . $needle . ']';
                        }
                    }
                }
            }
        }
        $this->assertSame([], $hits, "Hard-coded company details found:\n" . implode("\n", $hits));
    }
}

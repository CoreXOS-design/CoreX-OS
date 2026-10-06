<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementCountersignReminderMail;
use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Mail\PlatformEsign\AgreementSignedMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\DevSetting;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WetinkFile;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subscription Agreement — completion stays inside CoreX (spec §11.15): the completion mails carry a secure link and NO attachment,
 * the agency's own link opens the completed agreement read-only (view, signed PDF, its own wet-ink upload), every view/download is
 * audited and streamed, and the access window (platform setting, default 12 months) can be re-issued by the owner.
 */
class AgreementCompletionAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    private const ACCOUNT = '62123456789';

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

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null, 'name' => 'Johan Reichel']);
    }

    private function svc(): AgreementService
    {
        return app(AgreementService::class);
    }

    private function token(Document $d, string $role = 'r1'): string
    {
        return Signer::where('document_id', $d->id)->where('role_key', $role)->value('token');
    }

    private function send(User $owner): Document
    {
        Mail::fake();

        return $this->svc()->send(['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'cell' => '+27 82 555 0123'], $owner->id);
    }

    private function rrInput(): array
    {
        return ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG];
    }

    /** A document the agency signed electronically and RR countersigned (mails faked and cleared after). */
    private function completedElectronic(User $owner): Document
    {
        $doc = $this->send($owner);
        $token = $this->token($doc);
        $total = $this->svc()->totalPages($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $values = AgreementSample::ctx($doc->wording)['values'];
        $values = ['da_account' => self::ACCOUNT, 'm_account' => self::ACCOUNT] + $values + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => '8001015009087', 'consent' => 1])->assertOk();
        Mail::fake();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => $this->rrInput(), 'initials' => 'JR', 'pages' => range(1, $total),
        ])->assertOk();

        return $doc->fresh();
    }

    private function completedWetInk(User $owner): Document
    {
        $doc = $this->send($owner);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [UploadedFile::fake()->createWithContent('scan.pdf', self::PDF)]])->assertRedirect();
        Mail::fake();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => $this->rrInput()])->assertOk();

        return $doc->fresh();
    }

    /** Render a mail exactly as it would be sent and return [html, attachment count]. */
    private function rendered($mail): array
    {
        $html = $mail->render();

        return [$html, count($mail->attachments) + count($mail->rawAttachments) + count($mail->diskAttachments)];
    }

    // ── The mails ──────────────────────────────────────────────────────────

    public function test_completion_mails_carry_no_attachment_and_a_secure_link_to_each_side(): void
    {
        $owner = $this->owner();
        $doc = $this->completedElectronic($owner);

        Mail::assertNotSent(SignedMail::class);
        Mail::assertSent(AgreementSignedMail::class, 2);
        Mail::assertSent(AgreementSignedMail::class, function ($m) use ($doc) {
            if (!$m->hasTo('pat@caprivi.test')) {
                return false;
            }
            [$html, $attached] = $this->rendered($m);
            $this->assertSame(0, $attached, 'the agency completion mail has no attachment');
            $this->assertStringContainsString(route('platform-esign.agreement.show', $this->token($doc)), $html);
            $this->assertStringContainsString('Open your signed agreement', $html);
            $this->assertStringNotContainsString(self::ACCOUNT, $html);
            $this->assertStringNotContainsString('attached.', $html);

            return true;
        });
        Mail::assertSent(AgreementSignedMail::class, function ($m) use ($doc, $owner) {
            if (!$m->hasTo($owner->email)) {
                return false;
            }
            [$html, $attached] = $this->rendered($m);
            $this->assertSame(0, $attached, 'the RR completion mail has no attachment');
            $this->assertStringContainsString(route('platform-esign.documents.show', $doc->id), $html);
            $this->assertStringNotContainsString(self::ACCOUNT, $html);

            return true;
        });
    }

    public function test_the_hand_signed_path_emails_no_scan_and_no_attachment_either(): void
    {
        $owner = $this->owner();
        $doc = $this->completedWetInk($owner);

        Mail::assertNotSent(SignedMail::class);
        Mail::assertSent(AgreementSignedMail::class, function ($m) {
            [$html, $attached] = $this->rendered($m);
            $this->assertSame(0, $attached);

            return true;
        });
        $this->assertSame('completed', $doc->status);
    }

    public function test_invite_reminder_received_and_countersign_mails_have_no_attachment_and_no_bank_details(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $a = $doc->signers->firstWhere('role_key', 'r1');
        $doc->update(['form_data' => ['da_account' => self::ACCOUNT, 'm_account' => self::ACCOUNT, 'da_bank' => 'FNB'] + (array) $doc->form_data]);
        $doc = $doc->fresh(['signers', 'agency']);

        foreach ([new AgreementInviteMail($doc, $a), new AgreementInviteMail($doc, $a, true), new AgreementReceivedMail($doc), new AgreementReceivedMail($doc, true), new AgreementCountersignReminderMail($doc, 2)] as $mail) {
            [$html, $attached] = $this->rendered($mail);
            $this->assertSame(0, $attached, get_class($mail) . ' has no attachment');
            $this->assertStringNotContainsString(self::ACCOUNT, $html, get_class($mail) . ' carries no bank detail');
            $this->assertStringNotContainsString('FNB', $html, get_class($mail) . ' carries no bank detail');
        }
    }

    // ── The agency's link after completion ─────────────────────────────────

    public function test_the_agency_link_opens_a_read_only_completed_view_and_the_view_is_audited(): void
    {
        $doc = $this->completedElectronic($this->owner());
        $token = $this->token($doc);

        $res = $this->get(route('platform-esign.agreement.show', $token))->assertOk();
        $res->assertSee('Completed')->assertSee('Download the signed PDF')->assertSee('••••6789', false);
        $this->assertStringNotContainsString(self::ACCOUNT, $res->getContent(), 'bank numbers stay masked on screen');
        $this->assertStringNotContainsString('<input', $res->getContent(), 'read-only: no form controls');
        $this->assertStringNotContainsString('Initial this page', $res->getContent());
        $this->assertTrue($doc->fresh()->events->contains('event', 'completed_viewed'));

        // RR's own token never opens anything on the public side
        $this->get(route('platform-esign.agreement.show', $this->token($doc, 'r2')))->assertNotFound();
        // and the completed agreement cannot be edited through the link any more
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => (int) $doc->form_rev, 'values' => ['registered_name' => 'Changed']])->assertStatus(422);
    }

    public function test_the_signed_pdf_downloads_through_the_link_is_streamed_and_audited(): void
    {
        $owner = $this->owner();
        $doc = $this->completedElectronic($owner);

        $res = $this->get(route('platform-esign.agreement.download', $this->token($doc)))->assertOk();
        $this->assertStringContainsString('-signed.pdf', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertSame(hash('sha256', $res->streamedContent() ?: $res->getContent()), $doc->fresh()->document_hash);
        $this->assertTrue($doc->fresh()->events->contains('event', 'signed_copy_downloaded'));
        $this->assertStringNotContainsString('storage/', route('platform-esign.agreement.download', 'x'), 'a controller route, never a public file URL');

        // the owner's own download is audited with who did it
        $this->actingAs($owner)->get(route('platform-esign.documents.download', $doc->id))->assertOk();
        $this->assertTrue($doc->fresh()->events->contains(fn ($e) => $e->event === 'signed_copy_downloaded' && $e->actor_user_id === $owner->id));
    }

    public function test_the_agency_can_download_its_own_uploaded_copy_and_only_its_own(): void
    {
        $owner = $this->owner();
        $doc = $this->completedWetInk($owner);
        $file = WetinkFile::where('document_id', $doc->id)->firstOrFail();
        $token = $this->token($doc);

        $this->get(route('platform-esign.agreement.show', $token))->assertOk()->assertSee('Your uploaded hand-signed copy')->assertSee('scan.pdf');
        $this->get(route('platform-esign.agreement.wet-file', [$token, $file->id]))->assertOk();
        $this->assertTrue($doc->fresh()->events->contains('event', 'wetink_downloaded'));

        $other = $this->completedWetInk($owner);
        $this->get(route('platform-esign.agreement.wet-file', [$token, WetinkFile::where('document_id', $other->id)->value('id')]))->assertNotFound();
        $this->get(route('platform-esign.agreement.wet-file', [$this->token($doc, 'r2'), $file->id]))->assertNotFound();
    }

    public function test_nothing_downloads_before_the_agreement_is_completed(): void
    {
        $doc = $this->send($this->owner());
        $this->get(route('platform-esign.agreement.download', $this->token($doc)))->assertNotFound();
    }

    // ── The access window ──────────────────────────────────────────────────

    public function test_the_access_window_is_a_platform_setting_defaulting_to_twelve_months(): void
    {
        $this->assertSame(12, AgreementService::accessMonths());
        $doc = $this->completedElectronic($this->owner());
        $this->assertTrue($doc->expires_at->isSameDay(now()->addMonths(12)), 'window set at completion');

        DevSetting::set(AgreementService::ACCESS_KEY, '6');
        $this->assertSame(6, AgreementService::accessMonths());
        $this->assertTrue($this->completedWetInk($this->owner())->expires_at->isSameDay(now()->addMonths(6)));
    }

    public function test_an_expired_link_shows_a_clear_message_and_opens_nothing_until_the_owner_reissues_it(): void
    {
        $owner = $this->owner();
        $doc = $this->completedElectronic($owner);
        $old = $this->token($doc);
        $doc->update(['expires_at' => now()->subDay()]);

        $this->get(route('platform-esign.agreement.show', $old))->assertOk()->assertSee('This link has expired')->assertDontSee('Download the signed PDF');
        $this->get(route('platform-esign.agreement.download', $old))->assertNotFound();
        $this->assertSame('completed', $doc->fresh()->status, 'an expired access link never reopens or voids the agreement');

        // the owner re-issues: new token, fresh window, agency told with a link only; the old link is dead
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->post(route('platform-esign.documents.resend', $doc->id))->assertForbidden();
        Mail::fake();
        $this->actingAs($owner)->post(route('platform-esign.documents.resend', $doc->id))->assertSessionHasNoErrors();

        $fresh = $doc->fresh();
        $new = $this->token($fresh);
        $this->assertNotSame($old, $new);
        $this->assertTrue($fresh->expires_at->isSameDay(now()->addMonths(12)));
        $this->assertTrue($fresh->events->contains('event', 'access_reissued'));
        Mail::assertSent(AgreementSignedMail::class, 1);
        Mail::assertSent(AgreementSignedMail::class, function ($m) use ($new) {
            [$html, $attached] = $this->rendered($m);
            $this->assertSame(0, $attached);

            return $m->hasTo('pat@caprivi.test') && str_contains($html, $new);
        });
        $this->get(route('platform-esign.agreement.show', $old))->assertNotFound();
        $this->get(route('platform-esign.agreement.show', $new))->assertOk()->assertSee('Completed');
        $this->get(route('platform-esign.agreement.download', $new))->assertOk();
    }

    public function test_only_a_completed_agreement_can_have_its_access_link_reissued_and_unsigned_expiry_is_untouched(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->expectException(\DomainException::class);
        try {
            $this->svc()->reissueAccess($doc, $owner->id);
        } finally {
            $this->assertTrue($doc->fresh()->expires_at->isSameDay(now()->addDays(30)), 'the pre-signing 30-day expiry is a different setting');
        }
    }

    public function test_the_reminder_sweep_never_expires_a_completed_agreement(): void
    {
        $doc = $this->completedElectronic($this->owner());
        $doc->update(['expires_at' => now()->subDay()]);
        $this->artisan('platform-esign:remind-agreements')->assertSuccessful();
        $this->assertSame('completed', $doc->fresh()->status);
    }

    public function test_owner_panel_offers_the_reissue_on_a_completed_agreement(): void
    {
        $owner = $this->owner();
        $doc = $this->completedElectronic($owner);
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Re-issue the agency’s link', false)->assertSee('Nothing is attached to any email');
    }

    public function test_the_wording_screen_exposes_the_access_months_setting(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $this->actingAs($owner)->get(route('platform-esign.wording.index'))->assertOk()->assertSee('Signed agreement link valid for (months)');
    }
}

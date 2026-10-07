<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementSignedMail;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WetinkFile;
use App\Models\Role;
use App\Models\User;
use App\Services\PlatformEsign\Agreement\AgreementFields;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use App\Services\PlatformEsign\Agreement\AgreementTakeOn;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Audit D fixes (2026-10-06) for the Subscription Agreement signing flow: link rotation at completion, signature PNG limits,
 * ID number encrypted at rest, take-on month lapse, the wet-ink race, and the smaller hardening items.
 */
class AgreementAuditFixesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";
    private const ACCOUNT = '62123456789';
    private const ID = '8001015009087';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Role::clearCache();
        Storage::disk('local')->deleteDirectory('platform-esign');
        parent::tearDown();
    }

    // ── helpers ────────────────────────────────────────────────────────────

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

    private function send(User $owner, array $extra = []): Document
    {
        Mail::fake();

        return $this->svc()->send($extra + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'cell' => '+27 82 555 0123'], $owner->id);
    }

    private function token(Document $d, string $role = 'r1'): string
    {
        return Signer::where('document_id', $d->id)->where('role_key', $role)->value('token');
    }

    private function rrInput(): array
    {
        return ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG];
    }

    private function agencySigns(Document $doc): void
    {
        $token = $this->token($doc);
        $total = $this->svc()->totalPages($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
        $values = ['da_account' => self::ACCOUNT, 'm_account' => self::ACCOUNT] + AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => self::ID, 'consent' => 1])->assertOk();
    }

    private function countersign(User $owner, Document $doc)
    {
        return $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => $this->rrInput(), 'initials' => 'JR', 'pages' => range(1, $this->svc()->totalPages($doc)),
        ]);
    }

    private function completed(User $owner, array $extra = []): Document
    {
        $doc = $this->send($owner, $extra);
        $this->agencySigns($doc);
        $this->countersign($owner, $doc)->assertOk();

        return $doc->fresh();
    }

    private function pdf(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    /** A PNG whose header claims $w x $h (a real, tiny file: the pixel data is never needed to read the header). */
    private function pngClaiming(int $w, int $h): string
    {
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        $bin = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 1, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress("\x00\x00")) . $chunk('IEND', '');

        return 'data:image/png;base64,' . base64_encode($bin);
    }

    // ── D-M1: the invite link is retired at completion ─────────────────────

    public function test_completion_replaces_the_agency_link_and_the_completion_email_carries_the_new_one(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $invite = $this->token($doc);
        $this->agencySigns($doc);
        $this->assertSame($invite, $this->token($doc), 'the link keeps working while the agency is still signing / awaiting countersign');

        Mail::fake();
        $this->countersign($owner, $doc)->assertOk();

        $new = $this->token($doc);
        $this->assertNotSame($invite, $new);
        Mail::assertSent(AgreementSignedMail::class, function ($m) use ($new, $invite) {
            if (!$m->hasTo('pat@caprivi.test')) {
                return false;
            }
            $html = $m->render();
            $this->assertStringContainsString(route('platform-esign.agreement.show', $new), $html);
            $this->assertStringNotContainsString($invite, $html);

            return true;
        });

        // the original invite link opens nothing: no signed agreement, no download, no uploaded files
        $res = $this->get(route('platform-esign.agreement.show', $invite))->assertOk();
        $res->assertSee('This agreement is complete')->assertSee('link in the email')->assertDontSee('Download the signed PDF');
        $this->get(route('platform-esign.agreement.download', $invite))->assertNotFound();
        $this->get(route('platform-esign.agreement.wet-file', [$invite, 1]))->assertNotFound();
        $this->postJson(route('platform-esign.agreement.save', $invite), ['rev' => 0, 'values' => ['registered_name' => 'X']])->assertNotFound();

        // the new link (kept for the access window) works, and downloads the signed copy
        $this->assertTrue($doc->fresh()->expires_at->isSameDay(now()->addMonths(AgreementService::accessMonths())));
        $this->get(route('platform-esign.agreement.show', $new))->assertOk()->assertSee('Completed');
        $this->get(route('platform-esign.agreement.download', $new))->assertOk();
        $this->assertTrue($doc->fresh()->events->contains('event', 'access_link_replaced'));
        // an unknown token is still a plain 404, and the stored hash is not a usable token
        $this->get(route('platform-esign.agreement.show', str_repeat('b', 48)))->assertNotFound();
        $hash = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('previous_token_hash');
        $this->assertSame(hash('sha256', $invite), $hash);
        $this->get(route('platform-esign.agreement.show', $hash))->assertNotFound();
    }

    public function test_the_hand_signed_completion_also_replaces_the_link(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $invite = $this->token($doc);
        $this->post(route('platform-esign.agreement.upload', $invite), ['files' => [$this->pdf()]])->assertRedirect();
        $this->assertSame($invite, $this->token($doc));
        Mail::fake();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => $this->rrInput()])->assertOk();

        $this->assertNotSame($invite, $this->token($doc));
        $this->get(route('platform-esign.agreement.show', $invite))->assertOk()->assertSee('This agreement is complete');
        $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->assertSee('Your uploaded hand-signed copy');
    }

    public function test_reissuing_the_link_clears_the_retired_link_marker(): void
    {
        $owner = $this->owner();
        $doc = $this->completed($owner);
        $this->assertNotNull(Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('previous_token_hash'));
        $this->svc()->reissueAccess($doc, $owner->id);
        $this->assertNull(Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('previous_token_hash'));
    }

    // ── D-M2: signature PNG type and size ──────────────────────────────────

    public function test_a_signature_must_be_a_real_png_of_sane_dimensions(): void
    {
        $this->assertSame(self::PNG, AgreementFields::cleanSignature(self::PNG));
        $this->assertSame($this->pngClaiming(360, 96), AgreementFields::cleanSignature($this->pngClaiming(360, 96)));
        $this->assertSame($this->pngClaiming(2000, 1000), AgreementFields::cleanSignature($this->pngClaiming(2000, 1000)), 'the limit itself is allowed');
        $this->assertSame('', AgreementFields::cleanSignature($this->pngClaiming(20000, 20000)), 'a few bytes of PNG describing a 20000x20000 canvas');
        $this->assertSame('', AgreementFields::cleanSignature($this->pngClaiming(2001, 10)));
        $this->assertSame('', AgreementFields::cleanSignature($this->pngClaiming(10, 1001)));
        $this->assertSame('', AgreementFields::cleanSignature($this->pngClaiming(0, 10)));
        $this->assertSame('', AgreementFields::cleanSignature('data:image/png;base64,' . base64_encode('not an image')));

        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($im);
        $jpeg = ob_get_clean();
        $this->assertSame('', AgreementFields::cleanSignature('data:image/png;base64,' . base64_encode($jpeg)), 'a JPEG behind a PNG prefix is not a PNG');
    }

    public function test_an_oversized_signature_is_dropped_by_autosave_and_never_stored(): void
    {
        $doc = $this->send($this->owner());
        $this->postJson(route('platform-esign.agreement.save', $this->token($doc)), ['rev' => 0, 'values' => ['sigA' => $this->pngClaiming(30000, 30000), 'registered_name' => 'Caprivi']])->assertOk();
        $data = $doc->fresh()->form_data;
        $this->assertSame('Caprivi', $data['registered_name']);
        $this->assertSame('', (string) ($data['sigA'] ?? ''));
    }

    // ── D-M3: ID number encrypted at rest ──────────────────────────────────

    public function test_the_signers_id_number_is_encrypted_at_rest_and_still_prints_on_the_signed_record(): void
    {
        $owner = $this->owner();
        $doc = $this->completed($owner);

        $raw = DB::table('platform_esign_signers')->where('document_id', $doc->id)->where('role_key', 'r1')->value('id_number');
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::ID, $raw, 'the database holds ciphertext, not the ID number');
        $this->assertSame(self::ID, \Illuminate\Support\Facades\Crypt::decryptString($raw));
        $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
        $this->assertSame(self::ID, $signer->id_number, 'the model decrypts it for the sealed record and the owner screen');
        $this->assertArrayNotHasKey('id_number', $signer->toArray(), 'never serialised');

        // the owner's document screen still prints it (the sealed record prints $s->id_number through the same decrypting cast)
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('ID ' . self::ID);
    }

    public function test_the_migration_encrypts_only_what_is_still_plaintext(): void
    {
        $m = require base_path('database/migrations/2026_10_06_170000_encrypt_platform_esign_signer_id_number.php');
        $isEncrypted = new \ReflectionMethod($m, 'isEncrypted');
        $isEncrypted->setAccessible(true);
        $this->assertFalse($isEncrypted->invoke($m, self::ID));
        $this->assertFalse($isEncrypted->invoke($m, 'A1234567'));
        $this->assertTrue($isEncrypted->invoke($m, \Illuminate\Support\Facades\Crypt::encryptString(self::ID)), 'an already-encrypted value is skipped — a re-run never double-encrypts');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_esign_signers', 'previous_token_hash'));
    }

    // ── D-M4: the take-on month is re-checked after send ───────────────────

    public static function lapseBoundaries(): array
    {
        return [
            'first day of the take-on month' => ['2026-10', '2026-10-01 08:00', false],
            'mid month' => ['2026-10', '2026-10-17 10:00', false],
            'last day of the month, last minute' => ['2026-10', '2026-10-31 23:59:59', false],
            'the 1st of the next month (first debit date is today)' => ['2026-10', '2026-11-01 00:00:00', true],
            'later' => ['2026-10', '2026-11-20 09:00', true],
            'February month end' => ['2027-02', '2027-02-28 23:00', false],
            'February -> 1 March' => ['2027-02', '2027-03-01 00:00', true],
            'December month end' => ['2026-12', '2026-12-31 12:00', false],
            'December -> 1 January' => ['2026-12', '2027-01-01 00:01', true],
            'a future month' => ['2027-03', '2026-10-17 10:00', false],
        ];
    }

    /** @dataProvider lapseBoundaries */
    public function test_lapsed_boundaries(string $month, string $now, bool $lapsed): void
    {
        $this->assertSame($lapsed, AgreementTakeOn::lapsed($month, Carbon::parse($now)));
    }

    public function test_no_take_on_month_never_lapses(): void
    {
        $this->assertFalse(AgreementTakeOn::lapsed(null));
        $this->assertFalse(AgreementTakeOn::lapsed(''));
        $this->assertFalse(AgreementTakeOn::lapsed('garbage'));
    }

    public function test_the_agency_cannot_submit_once_the_start_month_has_passed_and_is_told_in_plain_words(): void
    {
        $owner = $this->owner();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $doc = $this->send($owner, ['take_on_month' => '2026-10']);
        $token = $this->token($doc);
        $total = $this->svc()->totalPages($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }

        Carbon::setTestNow('2026-11-01 09:00:00');
        $values = AgreementSample::ctx($doc->wording)['values'] + ['sigA' => self::PNG, 'sigM' => self::PNG];
        $res = $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $values, 'id_number' => self::ID, 'consent' => 1])->assertStatus(422);
        $co = \App\Services\PlatformEsign\Agreement\AgreementCompany::for($doc->fresh())->legalName();
        $this->assertSame('The start month on this agreement has passed. Please contact ' . $co . ' so a corrected agreement can be sent to you.', $res->json('message'));
        $this->assertStringContainsString('RR Technologies', $co, 'the default pinned company');
        $this->assertNotSame('awaiting_countersign', $doc->fresh()->status);
        $this->assertNull(Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('signed_at'));

        // the page itself says so up front, and autosave / initials are refused too
        $this->get(route('platform-esign.agreement.show', $token))->assertOk()->assertSee('The start month on this agreement has passed');
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => (int) $doc->fresh()->form_rev, 'values' => ['registered_name' => 'X']])->assertStatus(422);
    }

    public function test_the_last_day_of_the_take_on_month_still_submits(): void
    {
        $owner = $this->owner();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $doc = $this->send($owner, ['take_on_month' => '2026-10']);
        Carbon::setTestNow('2026-10-31 23:30:00');
        $this->agencySigns($doc);
        $this->assertSame('awaiting_countersign', $doc->fresh()->status);
    }

    public function test_the_owner_cannot_countersign_after_the_month_has_passed_and_sees_a_clear_warning(): void
    {
        $owner = $this->owner();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $doc = $this->send($owner, ['take_on_month' => '2026-10']);
        Carbon::setTestNow('2026-10-31 20:00:00');
        $this->agencySigns($doc);

        Carbon::setTestNow('2026-11-02 08:00:00');
        $res = $this->countersign($owner, $doc)->assertStatus(422);
        $this->assertStringContainsString('has passed', $res->json('message'));
        $this->assertStringContainsString('cancel this agreement', $res->json('message'), 'the agency already signed these dates: they are never changed under it');
        $this->assertSame('awaiting_countersign', $doc->fresh()->status);

        $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()
            ->assertSee('The start month on this agreement has passed')->assertSee('send a corrected one');
        // changing the month under a signed agency is refused
        $this->actingAs($owner)->post(route('platform-esign.agreements.take-on', $doc->id), ['take_on_month' => '2026-11'])->assertSessionHasErrors('take_on');
        $this->assertSame('2026-10', $doc->fresh()->rr_data['take_on_month']);

        // with today inside the month it countersigns normally
        Carbon::setTestNow('2026-10-31 21:00:00');
        $this->countersign($owner, $doc)->assertOk();
        $this->assertSame('completed', $doc->fresh()->status);
    }

    public function test_a_hand_signed_copy_cannot_be_countersigned_after_the_month_has_passed(): void
    {
        $owner = $this->owner();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $doc = $this->send($owner, ['take_on_month' => '2026-10']);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdf()]])->assertRedirect();

        Carbon::setTestNow('2026-11-03 08:00:00');
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => $this->rrInput()])->assertStatus(422);
        $this->assertSame('wetink_received', $doc->fresh()->status);
        $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()->assertSee('The start month on this agreement has passed');
    }

    public function test_the_owner_can_re_set_the_month_while_the_agency_has_not_signed_and_the_agency_can_then_carry_on(): void
    {
        $owner = $this->owner();
        Carbon::setTestNow('2026-10-17 10:00:00');
        $doc = $this->send($owner, ['take_on_month' => '2026-10']);
        $token = $this->token($doc);
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 0, 'values' => ['registered_name' => 'Caprivi Realty']])->assertOk();

        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Set a new take-on month');
        $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk()->assertSee('The start month on this agreement has passed');
        // a past month is refused, the same rule as at send
        $this->actingAs($owner)->post(route('platform-esign.agreements.take-on', $doc->id), ['take_on_month' => '2026-10'])->assertSessionHasErrors('take_on');
        $this->actingAs($owner)->post(route('platform-esign.agreements.take-on', $doc->id), ['take_on_month' => '2026-12'])->assertSessionHasNoErrors();

        $fresh = $doc->fresh();
        $this->assertSame('2026-12', $fresh->rr_data['take_on_month']);
        $this->assertSame('2026-12-01', $fresh->form_data['start_date']);
        $this->assertSame('2027-01-01', $fresh->form_data['m_first_payment']);
        $this->assertSame('Caprivi Realty', $fresh->form_data['registered_name'], 'the agency’s entries are kept');
        $this->assertSame(2, (int) $fresh->form_rev, 'the revision moves on so an open agency page picks the new dates up');
        $this->assertTrue($fresh->events->contains('event', 'take_on_set'));
        $this->assertSame($token, $this->token($doc), 'the agency link is unchanged');
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 2, 'values' => ['trading_name' => 'Caprivi Coastal']])->assertOk();
        $this->get(route('platform-esign.agreement.show', $token))->assertOk()->assertDontSee('The start month on this agreement has passed');

        // only the owner can
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->post(route('platform-esign.agreements.take-on', $doc->id), ['take_on_month' => '2027-01'])->assertForbidden();
    }

    // ── D-M5: wet-ink upload cannot undo a completion ──────────────────────

    public function test_an_upload_racing_the_countersign_cannot_move_a_completed_agreement_back(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdf()]])->assertRedirect();

        $stale = Document::find($doc->id);
        $this->assertSame('wetink_received', $stale->status);
        $signer = Signer::where('document_id', $doc->id)->where('role_key', 'r1')->first();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => $this->rrInput()])->assertOk();
        $before = WetinkFile::where('document_id', $doc->id)->count();

        try {
            $this->svc()->uploadWetInk($stale, $signer, [$this->pdf('late.pdf')], '10.0.0.1');
            $this->fail('the late upload must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('fully signed', $e->getMessage());
        }
        $this->assertSame('completed', $doc->fresh()->status);
        $this->assertSame($before, WetinkFile::where('document_id', $doc->id)->count());
        $this->assertSame(1, WetinkFile::where('document_id', $doc->id)->whereNull('superseded_at')->count());
    }

    // ── L1: initials need a live state ─────────────────────────────────────

    public function test_initials_cannot_be_changed_after_the_agency_has_signed_or_the_agreement_is_voided(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdf()]])->assertRedirect(); // hand-signed: no Initial rows
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'zz'])->assertStatus(422);
        $this->assertNull(Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('initials'));

        $doc2 = $this->completed($owner);
        $new = $this->token($doc2);
        $before = Signer::where('document_id', $doc2->id)->where('role_key', 'r1')->value('initials');
        $this->postJson(route('platform-esign.agreement.initials', $new), ['initials' => 'zz'])->assertStatus(422);
        $this->assertSame($before, Signer::where('document_id', $doc2->id)->where('role_key', 'r1')->value('initials'));

        $doc3 = $this->send($owner);
        $this->postJson(route('platform-esign.agreement.initials', $this->token($doc3)), ['initials' => 'ab'])->assertOk()->assertJson(['initials' => 'AB']);
        $doc3->update(['status' => 'voided']);
        $this->postJson(route('platform-esign.agreement.initials', $this->token($doc3)), ['initials' => 'cd'])->assertStatus(422);
    }

    // ── L2: uploaded file names ────────────────────────────────────────────

    public function test_file_names_are_made_safe_and_a_backslash_name_still_downloads(): void
    {
        $this->assertSame('scan_1.pdf', AgreementService::safeFileName('scan\\1.pdf'));
        $this->assertSame('a_b.pdf', AgreementService::safeFileName("a\x01b.pdf"));
        $this->assertSame('100_done.pdf', AgreementService::safeFileName('100%done.pdf'));
        $this->assertSame('passwd', AgreementService::safeFileName('../../etc/passwd'));
        $this->assertSame('signed-copy.pdf', AgreementService::safeFileName('', 'pdf'));
        $this->assertSame('signed-copy.jpg', AgreementService::safeFileName('///', 'jpg'));
        $this->assertSame('Scan (2).pdf', AgreementService::safeFileName('Scan (2).pdf'));
        $this->assertLessThanOrEqual(200, mb_strlen(AgreementService::safeFileName(str_repeat('é', 400) . '.pdf')));

        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdf('scan\\1.pdf')]])->assertRedirect();
        $file = WetinkFile::where('document_id', $doc->id)->first();
        $this->assertStringNotContainsString('\\', $file->original_name);
        // a row stored before the fix (raw backslash) still downloads, for the owner and for the agency
        $file->update(['original_name' => "bad\\name\x07.pdf"]);
        $this->actingAs($owner)->get(route('platform-esign.agreements.wetink', [$doc->id, $file->id]))->assertOk();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => $this->rrInput()])->assertOk();
        $this->get(route('platform-esign.agreement.wet-file', [$this->token($doc), $file->id]))->assertOk();
    }

    // ── L3: only audience-appropriate events print into the signed record ──

    public function test_the_signed_record_prints_only_audience_appropriate_events(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $esign = app(\App\Services\PlatformEsign\EsignService::class);
        foreach (['bank_revealed', 'email_failed', 'wetink_downloaded', 'plan_forced', 'take_on_set', 'access_reissued', 'seal_failed', 'completed_viewed', 'saved'] as $e) {
            $esign->log($doc, $e, 'internal ' . $e);
        }
        $signer = $this->svc()->agencySigner($doc);
        $esign->log($doc, 'viewed', null, $signer);
        $esign->log($doc, 'viewed', null, $signer);
        $esign->log($doc, 'page_initialled', 'Page 1 of 9 initialled', $signer);
        $esign->log($doc, 'signed', 'Agency signed', $signer);
        $esign->log($doc, 'countersigned', 'RR countersigned', $signer);

        $printed = AgreementService::sealedEvents($doc->fresh('events'))->pluck('event')->all();
        $sorted = $printed;
        sort($sorted);
        $this->assertSame(['countersigned', 'created', 'invited', 'page_initialled', 'signed', 'viewed'], $sorted, 'exactly the audience-appropriate events, "viewed" once');
        foreach (['bank_revealed', 'email_failed', 'wetink_downloaded', 'plan_forced', 'take_on_set', 'access_reissued', 'seal_failed', 'completed_viewed', 'saved'] as $internal) {
            $this->assertNotContains($internal, $printed);
        }
    }

    // ── L4: autosave front-end / submit revision ───────────────────────────

    public function test_a_refused_submit_moves_the_revision_on_and_tells_the_page(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 0, 'values' => ['registered_name' => 'Caprivi']])->assertOk();
        $this->assertSame(1, (int) $doc->fresh()->form_rev);

        $res = $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => ['registered_name' => 'Caprivi Realty'], 'id_number' => self::ID, 'consent' => 1])->assertStatus(422);
        $this->assertSame(2, (int) $doc->fresh()->form_rev);
        $this->assertSame(2, $res->json('rev'));
        // a second tab still holding revision 1 now conflicts instead of silently overwriting
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 1, 'values' => ['registered_name' => 'Old tab']])->assertStatus(409);
    }

    public function test_the_signing_page_carries_the_server_date_and_the_expiry_handling(): void
    {
        Carbon::setTestNow('2026-10-17 23:30:00');
        $doc = $this->send($this->owner());
        $html = $this->get(route('platform-esign.agreement.show', $this->token($doc)))->assertOk()->getContent();
        $this->assertStringContainsString('"today":"2026-10-17"', $html);
        $this->assertStringContainsString('goDead', $html);
        $this->assertStringContainsString('status === 419', $html);
        $this->assertStringContainsString('Your latest entries were kept', $html);
    }

    // ── L5: money fields never hold free text ──────────────────────────────

    public function test_the_mandate_amount_only_ever_holds_an_amount(): void
    {
        $clean = fn ($v) => AgreementFields::clean(['m_amount' => $v], 'r')['m_amount'] ?? null;
        $this->assertSame('', $clean('abc'));
        $this->assertSame('', $clean('about R500'));
        $this->assertSame('', $clean('1.2.3'));
        $this->assertSame('', $clean(''));
        $this->assertSame('1495', $clean('1495'));
        $this->assertSame('1495.5', $clean('1 495,5'));
        $this->assertSame('1495.50', $clean('R1,495.50'));
        $this->assertSame('495.50', $clean('495,50'));

        $doc = $this->send($this->owner()); // no take-on month: the recipient types the amount
        $this->postJson(route('platform-esign.agreement.save', $this->token($doc)), ['rev' => 0, 'values' => ['m_amount' => 'free text']])->assertOk();
        $this->assertSame('', (string) ($doc->fresh()->form_data['m_amount'] ?? ''));
    }

    // ── L6: reveal ─────────────────────────────────────────────────────────

    public function test_the_reveal_audit_names_which_account_and_a_trashed_document_cannot_be_revealed(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->postJson(route('platform-esign.agreement.save', $this->token($doc)), ['rev' => 0, 'values' => ['m_account' => self::ACCOUNT]])->assertOk(); // single entry: the agreement's own account line mirrors the mandate one

        $this->actingAs($owner)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'da_account'])->assertOk()->assertJson(['value' => self::ACCOUNT]);
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'm_account'])->assertOk()->assertJson(['value' => self::ACCOUNT]);
        $details = $doc->fresh()->events->where('event', 'bank_revealed')->pluck('detail')->all();
        $this->assertCount(2, $details);
        $this->assertStringContainsString('da_account', $details[0]);
        $this->assertStringContainsString('m_account', $details[1]);
        foreach ($details as $d) {
            $this->assertStringNotContainsString(self::ACCOUNT, $d);
        }

        $doc->delete();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'da_account'])->assertStatus(422);
        $this->assertCount(2, Document::withTrashed()->find($doc->id)->events->where('event', 'bank_revealed'));
    }

    // ── L7: the file cap counts current files only ─────────────────────────

    public function test_superseded_files_do_not_count_towards_the_cap(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);
        for ($batch = 1; $batch <= 6; $batch++) { // 6 x 12 = 72 files ever uploaded: the old rule refused from the 5th/6th batch on
            $files = [];
            foreach (range(1, 12) as $i) {
                $files[] = $this->pdf("page-{$batch}-{$i}.pdf");
            }
            $this->post(route('platform-esign.agreement.upload', $token), ['files' => $files])->assertRedirect();
            $this->assertSame($batch, (int) WetinkFile::where('document_id', $doc->id)->max('batch'), 'batch ' . $batch . ' was accepted');
        }
        $this->assertSame(12, WetinkFile::where('document_id', $doc->id)->whereNull('superseded_at')->count());
        $this->assertSame(72, WetinkFile::where('document_id', $doc->id)->count());
    }

    // ── Reminders: one run at a time ───────────────────────────────────────

    public function test_the_reminder_schedule_runs_on_one_server_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'platform-esign:remind-agreements'));
        $this->assertNotNull($event, 'the reminder sweep is scheduled');
        $this->assertTrue($event->onOneServer);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertSame('0 * * * *', $event->expression);
    }

    public function test_a_second_reminder_run_stands_aside_while_one_is_in_progress(): void
    {
        $lock = Cache::lock('platform-esign:remind-agreements', 60);
        $this->assertTrue($lock->get());
        $this->artisan('platform-esign:remind-agreements', ['--dry-run' => true])->expectsOutputToContain('already in progress')->assertSuccessful();
        $lock->release();

        $this->artisan('platform-esign:remind-agreements', ['--dry-run' => true])->expectsOutputToContain('dry run')->assertSuccessful();
        $again = Cache::lock('platform-esign:remind-agreements', 60);
        $this->assertTrue($again->get(), 'the lock is released after a run');
        $again->release();
    }
}

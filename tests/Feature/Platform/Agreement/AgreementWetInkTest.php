<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WetinkFile;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Subscription Agreement — wet-ink option (spec §11.8): download, sign by hand, upload, RR countersigns on the attestation page. */
class AgreementWetInkTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    private const PDF = "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

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

    private function send(User $owner, array $extra = []): Document
    {
        Mail::fake();

        return app(AgreementService::class)->send($extra + ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test'], $owner->id);
    }

    private function token(Document $d, string $role = 'r1'): string
    {
        return Signer::where('document_id', $d->id)->where('role_key', $role)->value('token');
    }

    private function pdfFile(string $name = 'signed.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    public function test_the_agency_can_download_a_printable_copy_with_its_entries_and_blank_signature_lines(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);
        $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => 0, 'values' => ['registered_name' => 'Caprivi Realty']])->assertOk();

        $res = $this->get(route('platform-esign.agreement.wet-copy', $token))->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $res->getContent());
        $this->assertStringContainsString('to-sign.pdf', $res->headers->get('Content-Disposition'));
        $this->assertTrue($doc->fresh()->events->contains('event', 'wetcopy_downloaded'));
        $this->get(route('platform-esign.agreement.wet-copy', $this->token($doc, 'r2')))->assertNotFound();
        $this->get(route('platform-esign.agreement.wet-copy', str_repeat('z', 48)))->assertNotFound();
    }

    public function test_uploading_the_signed_copy_stores_it_privately_moves_the_status_and_notifies_rr(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);
        Mail::fake();

        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile(), UploadedFile::fake()->image('page2.jpg', 600, 800)]])->assertRedirect(route('platform-esign.agreement.show', $token));

        $fresh = $doc->fresh(['signers']);
        $this->assertSame('wetink_received', $fresh->status);
        $this->assertSame('Signed copy received (wet ink) — awaiting RR countersign', $fresh->statusLabel());
        $this->assertSame('signed', $fresh->signers[0]->status);
        $files = $fresh->wetinkFiles;
        $this->assertCount(2, $files);
        foreach ($files as $f) {
            $this->assertTrue(Storage::disk('local')->exists($f->stored_path), 'stored on the private disk');
            $this->assertStringStartsWith('platform-esign/documents/' . $doc->id . '/wetink/', $f->stored_path);
            $this->assertSame(64, strlen($f->sha256));
            $this->assertNull($f->superseded_at);
        }
        $this->assertSame(['application/pdf', 'image/jpeg'], $files->pluck('mime')->all());
        $this->assertTrue($fresh->events->contains('event', 'wetink_uploaded'));
        Mail::assertSent(AgreementReceivedMail::class, fn ($m) => $m->hasTo($owner->email) && $m->wetInk);

        // the recipient link now explains where things stand and offers a replace upload — and no longer the fill-in form
        $this->get(route('platform-esign.agreement.show', $token))->assertOk()->assertSee('received your hand-signed copy')->assertSee('signed.pdf')->assertDontSee('Initial this page');
    }

    public function test_only_pdf_jpg_png_under_the_size_limit_are_accepted_and_nothing_is_stored_on_refusal(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);

        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [UploadedFile::fake()->createWithContent('evil.pdf', "MZ\x90\x00 not a pdf at all")]])->assertSessionHasErrors('upload');
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')]])->assertSessionHasErrors();
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => []])->assertSessionHasErrors('files');
        $this->post(route('platform-esign.agreement.upload', $token), [])->assertSessionHasErrors('files');
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile(), UploadedFile::fake()->createWithContent('note.txt', 'hello')]])->assertSessionHasErrors('upload');

        $this->assertSame(0, WetinkFile::count());
        $this->assertSame('sent', $doc->fresh()->status);
    }

    public function test_a_new_upload_supersedes_the_earlier_one_and_nothing_is_deleted(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->token($doc);
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile('first.pdf')]])->assertRedirect();
        $first = WetinkFile::firstOrFail();
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile('second.pdf')]])->assertRedirect();

        $all = WetinkFile::orderBy('id')->get();
        $this->assertCount(2, $all);
        $this->assertNotNull($all[0]->fresh()->superseded_at);
        $this->assertNull($all[1]->superseded_at);
        $this->assertSame(2, $all[1]->batch);
        $this->assertTrue(Storage::disk('local')->exists($first->stored_path), 'the superseded file is kept');
        $this->assertTrue($doc->fresh()->events->contains('event', 'wetink_superseded'));
    }

    public function test_uploads_are_refused_once_the_agency_has_signed_electronically_or_the_link_is_dead(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $token = $this->token($doc);
        $doc->update(['status' => 'awaiting_countersign']);
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile()]])->assertSessionHasErrors('upload');
        $this->get(route('platform-esign.agreement.wet-copy', $token))->assertRedirect(route('platform-esign.agreement.show', $token));

        $doc->update(['status' => 'voided']);
        $this->post(route('platform-esign.agreement.upload', $token), ['files' => [$this->pdfFile()]])->assertSessionHasErrors('upload');
        $this->assertSame(0, WetinkFile::count());
    }

    public function test_owner_only_can_open_the_uploaded_files_and_the_countersign_page(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdfFile()]])->assertRedirect();
        $f = WetinkFile::firstOrFail();

        $this->get(route('platform-esign.agreements.wetink', [$doc->id, $f->id]))->assertRedirect(); // guests go to login
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->get(route('platform-esign.agreements.wetink', [$doc->id, $f->id]))->assertForbidden();
        $this->actingAs($owner)->get(route('platform-esign.agreements.wetink', [$doc->id, $f->id]))->assertOk()->assertHeader('Cache-Control');
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Hand-signed copy from the agency')->assertSee('signed.pdf');
        $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()->assertSee('Countersign a hand-signed copy')->assertSee($f->sha256);
    }

    public function test_rr_countersigns_the_hand_signed_copy_on_the_attestation_page_and_it_completes_and_ticks_the_timeline(): void
    {
        $owner = $this->owner();
        $agency = Agency::create(['name' => 'Caprivi Realty', 'slug' => 'caprivi-' . uniqid(), 'reg_no' => '2020/123456/07', 'vat_no' => '4123', 'address' => '1 Beach Rd']);
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $step = AgencyTimelineItem::where('timeline_id', $tl->id)->where('auto_complete_trigger', 'contract_signed')->firstOrFail();
        $doc = $this->send($owner, ['agency_id' => $agency->id]);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdfFile()]])->assertRedirect();

        // missing fields are refused with a message per field
        $res = $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => ['rr_name' => 'Johan Reichel']])->assertStatus(422);
        foreach (['rr_capacity', 'rr_place', 'rr_date', 'sigR'] as $k) {
            $this->assertArrayHasKey($k, $res->json('errors'));
        }
        $this->assertSame('wetink_received', $doc->fresh()->status);

        Mail::fake();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => [
            'rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG,
            'variation_amount' => '999', 'da_account' => '1',
        ]])->assertOk()->assertJsonPath('ok', true);

        $fresh = $doc->fresh(['signers', 'events']);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->sealed_pdf_path);
        $pdf = Storage::disk('local')->get($fresh->sealed_pdf_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(hash('sha256', $pdf), $fresh->document_hash);
        $this->assertGreaterThanOrEqual(2, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf), 'attestation page + signing record');
        $this->assertArrayNotHasKey('variation_amount', (array) $fresh->rr_data);
        $this->assertSame('done', $step->fresh()->status);
        Mail::assertSent(SignedMail::class, fn ($m) => $m->hasTo('pat@caprivi.test'));
        $this->assertTrue($fresh->events->contains('event', 'countersigned'));
        // the agency can no longer replace the copy
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdfFile()]])->assertSessionHasErrors('upload');
        $this->assertSame(1, WetinkFile::count());
    }

    public function test_the_electronic_countersign_is_not_used_for_a_hand_signed_copy(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->post(route('platform-esign.agreement.upload', $this->token($doc)), ['files' => [$this->pdfFile()]])->assertRedirect();
        $this->assertTrue(app(AgreementService::class)->isWetInk($doc->fresh()));
        $this->expectException(\DomainException::class);
        app(AgreementService::class)->countersign($doc->fresh(), $owner, [], 'JR', [1], '10.0.0.1', 'UA');
    }
}

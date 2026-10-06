<?php

namespace Tests\Feature\Platform;

use App\Mail\PlatformEsign\InviteMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\Template;
use App\Models\PlatformEsign\TemplateField;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\EsignService;
use App\Services\PlatformEsign\MergeFields;
use App\Services\PlatformEsign\SealService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Platform E-Sign generic engine — audit fixes (audit-C-esigncore): stable signer roles, non-destructive template saves,
 * evidence hashes and refused re-seals, signature-image limits, expiry/asset rules, locking, timeline guards, merge-field strictness.
 * Spec: .ai/specs/agency-timeline-and-platform-esign.md §3A.
 */
class PlatformEsignHardeningTest extends TestCase
{
    use RefreshDatabase;

    /** A private storage root per test, so other lanes' Platform E-Sign tests (which wipe the shared `platform-esign` folder) cannot delete files mid-test. */
    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->diskRoot = storage_path('framework/testing/pe-' . uniqid('', true));
        config(['filesystems.disks.local.root' => $this->diskRoot]);
        app('filesystem')->forgetDisk('local');
    }

    protected function tearDown(): void
    {
        Role::clearCache();
        \Illuminate\Support\Facades\File::deleteDirectory($this->diskRoot);
        \Mockery::close();
        parent::tearDown();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function owner(): User
    {
        $role = Role::firstOrCreate(['name' => 'super_admin'], ['label' => 'System Owner', 'sort_order' => 1]);
        $role->is_owner = true;
        $role->save();
        Role::clearCache();

        return User::factory()->create(['role' => 'super_admin', 'agency_id' => null]);
    }

    private function agency(array $extra = []): Agency
    {
        return Agency::create(array_merge(['name' => 'Caprivi Realty', 'slug' => 'caprivi-' . uniqid(), 'reg_no' => '2020/123456/07', 'vat_no' => '4123456789', 'address' => '1 Beach Rd, Margate'], $extra));
    }

    private function svc(): EsignService
    {
        return app(EsignService::class);
    }

    private function webTemplate(string $body = "# Agreement\n\nBetween CoreX and {{ agency_name }}.", string $kind = 'subscription_agreement'): Template
    {
        return $this->svc()->createTemplate([
            'name' => 'Subscription', 'kind' => $kind, 'source' => 'web', 'body' => $body,
            'roles' => [['label' => 'Agency Principal'], ['label' => 'CoreX']],
        ], null, null);
    }

    private function pdfUpload(string $html = '<h1>Contract</h1>'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('c.pdf', Pdf::loadHTML($html)->output());
    }

    /** A PDF template with a signature field for each of its two roles. */
    private function pdfTemplate(): Template
    {
        $t = $this->svc()->createTemplate(['name' => 'P', 'kind' => 'other', 'source' => 'pdf', 'roles' => [['label' => 'Principal'], ['label' => 'CoreX']]], $this->pdfUpload(), null);
        $this->svc()->saveFields($t, [
            ['page_index' => 0, 'x' => 10, 'y' => 70, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r1'],
            ['page_index' => 0, 'x' => 10, 'y' => 85, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r2'],
        ]);

        return $t->fresh();
    }

    private function signers(): array
    {
        return [
            ['role_key' => 'r1', 'name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'id_number' => '8001015009087'],
            ['role_key' => 'r2', 'name' => 'Cory CoreX', 'email' => 'cory@corex.test'],
        ];
    }

    private function sendDoc(Template $tpl, ?Agency $agency = null, array $extra = []): Document
    {
        $doc = $this->svc()->send($tpl, array_merge(['agency_id' => $agency?->id, 'signers' => $this->signers()], $extra), [], null);
        $this->svc()->inviteDue($doc);

        return $doc->fresh('signers');
    }

    private function signAs(Signer $s, array $extra = []): Document
    {
        return $this->svc()->sign($s->token, array_merge(['typed_name' => $s->name, 'id_number' => '8001015009087'], $extra), '10.0.0.1', 'UA');
    }

    /** Both signers sign; returns the completed document. */
    private function completeDoc(Document $doc): Document
    {
        $this->signAs($doc->fresh('signers')->signers[0]);
        $this->signAs($doc->fresh('signers')->signers[1]);

        return $doc->fresh('signers');
    }

    private function png(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        $bin = (string) ob_get_clean();

        return 'data:image/png;base64,' . base64_encode($bin);
    }

    private function roleRows(Template $t, array $extra = []): array
    {
        return array_merge(collect($t->roles())->map(fn ($r) => ['key' => $r['key'], 'label' => $r['label']])->all(), $extra);
    }

    // ── C-M1: stable signer roles ──────────────────────────────────────────

    public function test_role_keys_survive_reordering_renaming_and_adding_and_are_never_reused(): void
    {
        $t = $this->pdfTemplate();
        $update = fn (array $roles) => $this->svc()->updateTemplate($t->fresh(), ['name' => 'P', 'kind' => 'other', 'is_active' => true, 'roles' => $roles], null);

        // Reorder + rename: the keys stay with their roles, only the order changes.
        $t2 = $update([['key' => 'r2', 'label' => 'CoreX Ltd'], ['key' => 'r1', 'label' => 'Agency Principal']]);
        $roles = collect($t2->roles())->keyBy('key');
        $this->assertSame('CoreX Ltd', $roles['r2']['label']);
        $this->assertSame(1, $roles['r2']['order']);
        $this->assertSame('Agency Principal', $roles['r1']['label']);
        $this->assertSame(2, $roles['r1']['order']);
        // The placed fields still belong to the same parties.
        $this->assertSame('r1', $t2->fields()->where('y', 70)->first()->role_key);
        $this->assertSame('r2', $t2->fields()->where('y', 85)->first()->role_key);

        // A new row gets a NEW key; a blank-label row is dropped without renumbering anyone.
        $t3 = $update([['key' => 'r1', 'label' => 'Agency Principal'], ['key' => '', 'label' => ''], ['key' => 'r2', 'label' => 'CoreX Ltd'], ['key' => '', 'label' => 'Witness']]);
        $this->assertSame(['r1', 'r2', 'r3'], collect($t3->roles())->pluck('key')->all());

        // A role with placed fields cannot be removed (it would orphan or hand the spot to someone else) — and nothing changed.
        try {
            $update([['key' => 'r2', 'label' => 'CoreX Ltd'], ['key' => 'r3', 'label' => 'Witness']]);
            $this->fail('removing a role that still has fields must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Agency Principal', $e->getMessage());
            $this->assertStringContainsString('still has 1 field', $e->getMessage());
        }
        $this->assertSame(['r1', 'r2', 'r3'], collect($t->fresh()->roles())->pluck('key')->all());

        // Once its fields are gone it can be removed; its key is NOT handed to the next new role.
        $this->svc()->saveFields($t->fresh(), [['page_index' => 0, 'x' => 10, 'y' => 85, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r2']]);
        $t4 = $update([['key' => 'r2', 'label' => 'CoreX Ltd'], ['key' => 'r3', 'label' => 'Witness'], ['key' => '', 'label' => 'Observer']]);
        $this->assertSame(['r2', 'r3', 'r4'], collect($t4->roles())->pluck('key')->all());
    }

    public function test_a_form_without_keys_does_not_renumber_existing_roles(): void
    {
        $t = $this->webTemplate();
        $t2 = $this->svc()->updateTemplate($t, ['name' => 'S', 'kind' => 'other', 'is_active' => true, 'body' => 'x', 'roles' => [['label' => 'CoreX'], ['label' => 'Agency Principal']]], null);
        $this->assertSame('r2', collect($t2->roles())->firstWhere('label', 'CoreX')['key']);
        $this->assertSame('r1', collect($t2->roles())->firstWhere('label', 'Agency Principal')['key']);
    }

    public function test_send_refuses_a_signer_for_an_unknown_role_and_orphaned_template_fields(): void
    {
        $t = $this->pdfTemplate();
        $signers = $this->signers();
        $signers[] = ['role_key' => 'r9', 'name' => 'Stranger', 'email' => 's@x.test'];
        try {
            $this->svc()->send($t, ['signers' => $signers], [], null);
            $this->fail('a signer for a role the template does not have must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not on this template', $e->getMessage());
        }

        // A field that points at a role the template no longer has (legacy data from the old renumbering) blocks the send.
        TemplateField::create(['template_id' => $t->id, 'page_index' => 0, 'x' => 1, 'y' => 1, 'w' => 5, 'h' => 5, 'type' => 'text', 'role_key' => 'r7', 'required' => false, 'sort_order' => 9]);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('no longer on it');
        $this->svc()->send($t->fresh(), ['signers' => $this->signers()], [], null);
    }

    public function test_the_template_form_carries_each_role_key(): void
    {
        $this->actingAs($this->owner());
        $t = $this->webTemplate();
        $this->get(route('platform-esign.templates.edit', $t->id))->assertOk()->assertSee("[key]'", false);
        // And the controller keeps the posted keys.
        $this->put(route('platform-esign.templates.update', $t->id), [
            'name' => 'S', 'kind' => 'other', 'is_active' => 1, 'body' => 'x',
            'roles' => [['key' => 'r2', 'label' => 'CoreX'], ['key' => 'r1', 'label' => 'Principal']],
        ])->assertRedirect();
        $this->assertSame('r2', collect($t->fresh()->roles())->firstWhere('label', 'CoreX')['key']);
    }

    // ── C-M2: no hard deletes, no destroyed files ──────────────────────────

    public function test_saving_fields_is_a_diff_that_soft_deletes_what_was_removed(): void
    {
        $t = $this->pdfTemplate();
        [$a, $b] = $t->fields()->get()->all();

        $this->svc()->saveFields($t, [
            ['id' => $a->id, 'page_index' => 0, 'x' => 12, 'y' => 71, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r1'], // moved
            ['page_index' => 0, 'x' => 50, 'y' => 10, 'w' => 20, 'h' => 4, 'type' => 'text', 'role_key' => 'r1'],                      // new
        ]);

        $this->assertSame(12.0, (float) $a->fresh()->x, 'the kept field is updated in place');
        $this->assertSame($a->id, $t->fields()->where('type', 'signature')->first()->id, 'its id (and any reference to it) is stable');
        $this->assertSoftDeleted('platform_esign_template_fields', ['id' => $b->id]);
        $this->assertNotNull(TemplateField::withTrashed()->find($b->id), 'the removed field is archived, not destroyed');
        $this->assertSame(2, $t->fields()->count());
    }

    public function test_a_bad_replacement_pdf_leaves_the_template_intact_and_sendable(): void
    {
        Mail::fake();
        $t = $this->pdfTemplate();
        $oldPdf = $t->pdf_path;
        $this->assertTrue(Storage::disk('local')->exists($oldPdf));

        try {
            $this->svc()->updateTemplate($t, ['name' => 'P', 'kind' => 'other', 'is_active' => true, 'roles' => $this->roleRows($t)],
                UploadedFile::fake()->createWithContent('bad.pdf', 'this is not a pdf at all'));
            $this->fail('an unreadable PDF must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('could not be read', $e->getMessage());
        }

        $t = $t->fresh();
        $this->assertSame($oldPdf, $t->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($oldPdf), 'the previous source file is still there');
        $this->assertTrue(Storage::disk('local')->exists(dirname($oldPdf) . '/pages/p0.png'), 'and so are its pages');
        $this->assertSame(2, $t->fields()->count(), 'placed fields are untouched');
        $this->assertCount(1, Storage::disk('local')->directories('platform-esign/templates/' . $t->id), 'no half-written replacement folder is left behind');
        $this->assertSame(1, $this->sendDoc($t)->signers->where('status', 'sent')->count(), 'and it can still be sent');
    }

    public function test_a_good_replacement_pdf_archives_the_old_files_after_commit(): void
    {
        $t = $this->pdfTemplate();
        $oldPdf = $t->pdf_path;
        $t2 = $this->svc()->updateTemplate($t, ['name' => 'P', 'kind' => 'other', 'is_active' => true, 'roles' => $this->roleRows($t)], $this->pdfUpload('<h1>Version two</h1>'));

        $this->assertNotSame($oldPdf, $t2->pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($t2->pdf_path));
        $this->assertFalse(Storage::disk('local')->exists($oldPdf), 'the old file moved out of the live folder…');
        $this->assertNotEmpty(Storage::disk('local')->allFiles('platform-esign/templates/' . $t->id . '/superseded'), '…into a superseded folder, not deleted');
        $this->assertSame(0, $t2->fields()->count(), 'old placements no longer line up and are archived');
        $this->assertSame(1, TemplateField::onlyTrashed()->where('template_id', $t->id)->where('role_key', 'r1')->count());

        $this->actingAs($this->owner());
        $this->get(route('platform-esign.templates.page', [$t->id, 0]))->assertOk(); // pages are served from the new folder
    }

    // ── C-M3: hashes and re-seal ───────────────────────────────────────────

    public function test_what_was_sent_is_fingerprinted_and_checked_before_sealing(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->pdfTemplate());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $doc->content_hash);
        $this->assertTrue($this->svc()->contentIntact($doc));
        $this->assertTrue($doc->events()->where('event', 'created')->where('detail', 'like', '%' . $doc->content_hash . '%')->exists());

        // Someone alters the frozen layout after sending: the document still completes, but it is NOT sealed.
        $fields = $doc->fields_json;
        $fields[0]['role_key'] = 'r2';
        $doc->update(['fields_json' => $fields]);
        $done = $this->completeDoc($doc);

        $this->assertSame('completed', $done->status);
        $this->assertNull($done->sealed_pdf_path);
        $this->assertTrue($done->events()->where('event', 'content_hash_mismatch')->exists());
        $this->assertTrue($done->events()->where('event', 'seal_failed')->exists());
    }

    public function test_web_document_content_is_fingerprinted_too(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $this->assertNotNull($doc->content_hash);
        $doc->update(['body_html_snapshot' => $doc->body_html_snapshot . '<p>sneaky</p>']);
        $this->assertFalse($this->svc()->contentIntact($doc->fresh()));
    }

    public function test_an_already_sealed_document_cannot_be_resealed_and_nobody_is_re_emailed(): void
    {
        Mail::fake();
        $doc = $this->completeDoc($this->sendDoc($this->webTemplate(), $this->agency()));
        $bytes = Storage::disk('local')->get($doc->sealed_pdf_path);
        $hash = $doc->document_hash;
        Mail::assertSent(SignedMail::class, 2);

        try {
            $this->svc()->reseal($doc, false, null);
            $this->fail('re-sealing a sealed document must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already sealed', $e->getMessage());
        }
        $this->assertSame($bytes, Storage::disk('local')->get($doc->sealed_pdf_path), 'the emailed file is not overwritten');
        $this->assertSame($hash, $doc->fresh()->document_hash);
        Mail::assertSent(SignedMail::class, 2); // still only the original two
        $this->assertTrue($doc->events()->where('event', 'reseal_refused')->exists());

        // Same through the owner's button.
        $this->actingAs($this->owner())->post(route('platform-esign.documents.reseal', $doc->id))->assertSessionHasErrors('action');
        $this->assertSame($bytes, Storage::disk('local')->get($doc->sealed_pdf_path));
        Mail::assertSent(SignedMail::class, 2);
    }

    public function test_a_forced_reseal_is_owner_only_keeps_the_previous_copy_and_emails_nobody(): void
    {
        Mail::fake();
        $doc = $this->completeDoc($this->sendDoc($this->webTemplate(), $this->agency()));
        $old = Storage::disk('local')->get($doc->sealed_pdf_path);
        $oldHash = $doc->document_hash;

        $agent = User::factory()->create(['role' => 'agent', 'agency_id' => $this->agency(['slug' => 'z-' . uniqid(), 'name' => 'Other'])->id]);
        try {
            $this->svc()->reseal($doc, true, $agent->id);
            $this->fail('only the owner may force');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Only the platform owner', $e->getMessage());
        }
        $this->assertSame($old, Storage::disk('local')->get($doc->sealed_pdf_path));

        $owner = $this->owner();
        $this->actingAs($owner)->post(route('platform-esign.documents.reseal', $doc->id), ['force' => 1])->assertSessionHasNoErrors();
        $doc = $doc->fresh();
        $this->assertNotSame($oldHash, $doc->document_hash);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->sealed_pdf_path)), $doc->document_hash);
        $kept = collect(Storage::disk('local')->files('platform-esign/documents/' . $doc->id))->first(fn ($f) => str_contains($f, 'superseded'));
        $this->assertNotNull($kept, 'the previous sealed file is kept');
        $this->assertSame($old, Storage::disk('local')->get($kept));
        $forced = $doc->events()->where('event', 'reseal_forced')->first();
        $this->assertNotNull($forced);
        $this->assertStringContainsString($oldHash, $forced->detail);
        $this->assertSame($owner->id, $forced->actor_user_id);
        Mail::assertSent(SignedMail::class, 2); // nobody re-emailed
    }

    public function test_resealing_a_document_whose_seal_failed_is_the_recovery_path_and_does_email(): void
    {
        Mail::fake();
        $doc = $this->completeDoc($this->sendDoc($this->webTemplate(), $this->agency()));
        Storage::disk('local')->delete($doc->sealed_pdf_path);
        $doc->update(['sealed_pdf_path' => null, 'document_hash' => null]);

        $this->svc()->reseal($doc->fresh(), false, null);
        $doc = $doc->fresh();
        $this->assertNotNull($doc->sealed_pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($doc->sealed_pdf_path));
        Mail::assertSent(SignedMail::class, 4); // 2 at completion + 2 now that the copy exists
    }

    public function test_downloads_refuse_a_sealed_file_that_no_longer_matches_its_hash(): void
    {
        Mail::fake();
        $doc = $this->completeDoc($this->sendDoc($this->webTemplate(), $this->agency()));
        $token = $doc->signers[0]->token;
        $this->actingAs($this->owner());

        $this->get(route('platform-esign.documents.download', $doc->id))->assertOk();
        $this->get(route('platform-esign.sign.download', $token))->assertOk();

        Storage::disk('local')->put($doc->sealed_pdf_path, '%PDF-1.4 tampered');
        $this->get(route('platform-esign.documents.download', $doc->id))->assertSessionHasErrors('action');
        $this->get(route('platform-esign.sign.download', $token))->assertStatus(409);
        $this->assertTrue($doc->events()->where('event', 'seal_hash_mismatch')->exists());
    }

    // ── C-M4: drawn signature + sealing failure ────────────────────────────

    public function test_a_drawn_signature_is_validated_before_anything_is_stored(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $pat = $doc->signers[0];
        $rejects = [
            'oversized canvas' => $this->png(3000, 10),
            'tall canvas' => $this->png(10, 1500),
            'not a png' => 'data:image/jpeg;base64,' . base64_encode('x'),
            'png prefix, garbage body' => 'data:image/png;base64,' . base64_encode('not really an image'),
            'bad base64' => 'data:image/png;base64,@@@@',
        ];
        foreach ($rejects as $why => $uri) {
            try {
                $this->signAs($pat->fresh(), ['signature' => $uri]);
                $this->fail("$why must be refused");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('drawn signature', $e->getMessage(), $why);
            }
            $this->assertSame('sent', $pat->fresh()->status, "$why must leave the signer unsigned");
        }

        $ok = $this->png(600, 120);
        $this->signAs($pat->fresh(), ['signature' => $ok]);
        $this->assertSame($ok, $pat->fresh()->signature_image);
    }

    public function test_a_sealing_failure_never_errors_the_signer_and_can_be_recovered(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $this->signAs($doc->signers[0]);

        $broken = \Mockery::mock(SealService::class);
        $broken->shouldReceive('build')->andThrow(new \RuntimeException('GD ran out of memory'));
        $svc = new EsignService(app(MergeFields::class), $broken, app(AgencyTimelineService::class));
        $done = $svc->sign($doc->fresh('signers')->signers[1]->token, ['typed_name' => 'Cory', 'id_number' => '1'], '10.0.0.1', 'UA');

        $doc = $doc->fresh();
        $this->assertSame('completed', $doc->status);
        $this->assertNull($doc->sealed_pdf_path);
        $this->assertTrue($doc->events()->where('event', 'seal_failed')->exists());
        $this->assertSame('completed', $done->status);

        // Recoverable: the owner's Re-seal works once the fault is gone.
        $this->svc()->reseal($doc, false, null);
        $this->assertNotNull($doc->fresh()->sealed_pdf_path);
    }

    // ── C-L1: throttle buckets ─────────────────────────────────────────────

    public function test_page_images_have_their_own_higher_limiter_and_tokens_do_not_share_a_bucket(): void
    {
        $routes = Route::getRoutes();
        $page = $routes->getByName('platform-esign.sign.page')->gatherMiddleware();
        $show = $routes->getByName('platform-esign.sign.show')->gatherMiddleware();
        $this->assertContains('throttle:platform-esign-asset', $page);
        $this->assertNotContains('throttle:platform-esign-sign', $page);
        $this->assertContains('throttle:platform-esign-sign', $show);
        $this->assertNotContains('throttle:60,1', $show);

        Mail::fake();
        $doc = $this->sendDoc($this->pdfTemplate());
        [$a, $b] = [$doc->signers[0]->token, $doc->signers[1]->token];
        for ($i = 0; $i < 90; $i++) { // a 60-page PDF scrolled through, plus a reload, is well over the old shared 60/min
            $this->get(route('platform-esign.sign.page', [$a, 0]))->assertOk();
        }
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('platform-esign.sign.show', $a))->assertOk();
        }
        $this->get(route('platform-esign.sign.show', $a))->assertStatus(429);
        $this->get(route('platform-esign.sign.show', $b))->assertOk(); // the other signer on the same connection is not starved
    }

    // ── C-L2 / L3: expiry + asset routes ───────────────────────────────────

    public function test_an_expired_link_cannot_sign_even_if_nobody_opened_the_page_first(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $doc->update(['expires_at' => now()->subHour()]);

        try {
            $this->signAs($doc->signers[0]);
            $this->fail('signing past the window must be refused by the service itself');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('expired', $e->getMessage());
        }
        $this->assertSame('sent', $doc->signers[0]->fresh()->status);
        $this->assertSame('expired', $doc->fresh()->status, 'and the document is marked expired');

        // Over HTTP too.
        $doc2 = $this->sendDoc($this->webTemplate(), $this->agency(['slug' => 'b-' . uniqid(), 'name' => 'B']));
        $doc2->update(['expires_at' => now()->subHour()]);
        $this->post(route('platform-esign.sign.submit', $doc2->signers[0]->token), ['typed_name' => 'Pat', 'id_number' => '1', 'consent' => '1'])->assertSessionHasErrors('sign');
        $this->assertSame('sent', $doc2->signers[0]->fresh()->status);
    }

    public function test_pages_and_attachments_are_refused_for_expired_and_declined_documents_and_the_body_is_hidden(): void
    {
        Mail::fake();
        $doc = $this->svc()->send($this->pdfTemplate(), ['signers' => $this->signers()], [UploadedFile::fake()->createWithContent('annex.pdf', Pdf::loadHTML('<p>annex</p>')->output())], null);
        $this->svc()->inviteDue($doc);
        $doc = $doc->fresh(['signers', 'attachments']);
        $tok = $doc->signers[0]->token;
        $att = $doc->attachments[0]->id;

        $this->get(route('platform-esign.sign.page', [$tok, 0]))->assertOk();
        $this->get(route('platform-esign.sign.attachment', [$tok, $att]))->assertOk();

        $doc->update(['expires_at' => now()->subDay()]);
        $this->get(route('platform-esign.sign.page', [$tok, 0]))->assertNotFound();
        $this->get(route('platform-esign.sign.attachment', [$tok, $att]))->assertNotFound();
        $this->get(route('platform-esign.sign.show', $tok))->assertOk()->assertSee('expired')->assertDontSee('Attached documents')->assertDontSee('/page/0', false);

        $doc2 = $this->sendDoc($this->pdfTemplate());
        $this->post(route('platform-esign.sign.decline', $doc2->signers[0]->token), ['reason' => 'No'])->assertRedirect();
        $this->get(route('platform-esign.sign.page', [$doc2->signers[0]->token, 0]))->assertNotFound();
        $this->get(route('platform-esign.sign.page', [$doc2->signers[1]->token, 0]))->assertNotFound();
    }

    public function test_a_web_documents_token_is_not_touched_by_the_generic_routes(): void
    {
        $tpl = $this->webTemplate();
        $doc = Document::create(['template_id' => $tpl->id, 'title' => 'Web doc', 'status' => 'awaiting_countersign', 'source' => 'webdoc',
            'sequential' => true, 'expires_at' => now()->subDay(), 'sent_at' => now()]);
        $signer = Signer::create(['document_id' => $doc->id, 'role_key' => 'r2', 'role_label' => 'RR', 'sign_order' => 2, 'name' => 'RR', 'email' => 'rr@x.test',
            'token' => str_repeat('w', 48), 'status' => 'pending']);

        $this->get(route('platform-esign.sign.show', $signer->token))->assertNotFound();
        $this->assertSame('awaiting_countersign', $doc->fresh()->status, 'a 404 must not have flipped the web document to expired');
    }

    // ── C-L5 / L7: locking, re-read status, expiry validation ──────────────

    public function test_void_and_resend_decide_on_the_locked_row_not_a_stale_model(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $stale = Document::find($doc->id); // loaded while still 'sent'
        $this->completeDoc($doc);

        try {
            $this->svc()->void($stale, 'too late', null);
            $this->fail('a completed contract must never be voided from a stale model');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('cannot be voided', $e->getMessage());
        }
        $this->assertSame('completed', $doc->fresh()->status);

        try {
            $this->svc()->resend($stale, null, 14);
            $this->fail('a completed contract must never be re-sent');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('re-sent', $e->getMessage());
        }
    }

    public function test_resend_validates_the_expiry_and_rotates_the_links(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $old = $doc->signers[0]->token;

        foreach ([0, -3, 500] as $bad) {
            try {
                $this->svc()->resend($doc, null, $bad);
                $this->fail("$bad days must be refused");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('between 1 and 90', $e->getMessage());
            }
        }
        $this->assertSame($old, $doc->signers[0]->fresh()->token, 'a refused resend changes nothing');

        $owner = $this->owner();
        $this->actingAs($owner)->post(route('platform-esign.documents.resend', $doc->id), ['expiry_days' => 0])->assertSessionHasErrors('expiry_days');
        $this->post(route('platform-esign.documents.resend', $doc->id), ['expiry_days' => 30])->assertSessionHasNoErrors();
        $this->assertNotSame($old, $doc->signers[0]->fresh()->token);
        $this->assertTrue($doc->fresh()->expires_at->isSameDay(now()->addDays(30)));
        $this->get(route('platform-esign.sign.show', $old))->assertNotFound();
    }

    // ── C-L6: timeline errors never reach the signer ───────────────────────

    public function test_a_timeline_sync_error_after_completion_is_logged_not_thrown(): void
    {
        Mail::fake();
        $agency = $this->agency();
        app(AgencyTimelineService::class)->start($agency, now(), null);
        $doc = $this->sendDoc($this->webTemplate(), $agency);
        $this->assertNotNull(AgencyTimeline::where('agreement_document_id', $doc->id)->first());

        $timelines = \Mockery::mock(AgencyTimelineService::class);
        $timelines->shouldReceive('syncAgreement')->andThrow(new \RuntimeException('timeline exploded'));
        $svc = new EsignService(app(MergeFields::class), app(SealService::class), $timelines);
        $svc->sign($doc->signers[0]->token, ['typed_name' => 'Pat', 'id_number' => '1'], null, null);
        $svc->sign($doc->fresh('signers')->signers[1]->token, ['typed_name' => 'Cory', 'id_number' => '1'], null, null);

        $doc = $doc->fresh();
        $this->assertSame('completed', $doc->status);
        $this->assertNotNull($doc->sealed_pdf_path);
        $this->assertTrue($doc->events()->where('event', 'timeline_sync_failed')->exists());
    }

    // ── C-L8: merge-field strictness ───────────────────────────────────────

    public function test_unknown_and_mis_cased_merge_fields_are_refused_at_save_and_at_send(): void
    {
        $m = app(MergeFields::class);
        foreach (['{{ Agency_Name }}', '{{agency-name}}', '{{ agency2 }}', '{{ }}', '{{ property_address }}'] as $bad) {
            try {
                $m->render('Hello ' . $bad, $m->values(null));
                $this->fail("$bad must be refused");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('unknown field', $e->getMessage());
            }
            try {
                $this->webTemplate('Hello ' . $bad);
                $this->fail("$bad must be refused at template save");
            } catch (\DomainException $e) {
                $this->assertStringContainsString('Unknown merge field', $e->getMessage());
            }
        }
        $this->assertSame(['agency_name', 'Agency_Name'], $m->used('{{ agency_name }} and {{Agency_Name}} and {{ agency_name }}'));

        $this->actingAs($this->owner())->post(route('platform-esign.templates.store'), [
            'name' => 'Typo', 'kind' => 'other', 'source' => 'web', 'body' => 'Hi {{ Agency_Name }}', 'roles' => [['label' => 'A']],
        ])->assertSessionHasErrors('body');
        $this->assertSame(0, Template::where('name', 'Typo')->count());
        $this->webTemplate('All good {{ agency_name }} on {{today}}'); // valid ones still pass
    }

    // ── C-L9: voiding / declining frees the timeline link ──────────────────

    public function test_voiding_unlinks_the_agreement_so_the_replacement_is_auto_linked(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $first = $this->sendDoc($this->webTemplate(), $agency);
        $this->assertSame($first->id, $tl->fresh()->agreement_document_id);

        $this->svc()->void($first, 'Wrong wording', null);
        $this->assertNull($tl->fresh()->agreement_document_id);
        $this->assertTrue($first->events()->where('event', 'timeline_unlinked')->exists());

        $second = $this->sendDoc($this->webTemplate(), $agency);
        $this->assertSame($second->id, $tl->fresh()->agreement_document_id);

        $this->post(route('platform-esign.sign.decline', $second->signers[0]->token), ['reason' => 'No'])->assertRedirect();
        $this->assertNull($tl->fresh()->agreement_document_id);
    }

    // ── C-L10: viewed is logged once an hour ───────────────────────────────

    public function test_reloading_the_signing_page_does_not_flood_the_audit_trail(): void
    {
        Mail::fake();
        $doc = $this->sendDoc($this->webTemplate(), $this->agency());
        $tok = $doc->signers[0]->token;
        for ($i = 0; $i < 5; $i++) {
            $this->get(route('platform-esign.sign.show', $tok))->assertOk();
        }
        $this->assertSame(1, $doc->events()->where('event', 'viewed')->count());

        $doc->events()->where('event', 'viewed')->update(['created_at' => now()->subHours(2)]);
        $this->get(route('platform-esign.sign.show', $tok))->assertOk();
        $this->assertSame(2, $doc->events()->where('event', 'viewed')->count());
    }

    // ── C-L11: double submit ───────────────────────────────────────────────

    public function test_a_double_submitted_send_creates_one_contract_and_one_set_of_emails(): void
    {
        Mail::fake();
        $this->actingAs($this->owner());
        $tpl = $this->webTemplate();
        $agency = $this->agency();
        $payload = ['template_id' => $tpl->id, 'agency_id' => $agency->id, 'expiry_days' => 14, 'sequential' => 1, 'submission_token' => 'tok-' . uniqid(),
            'signers' => $this->signers()];

        $this->post(route('platform-esign.documents.store'), $payload)->assertRedirect();
        $second = $this->post(route('platform-esign.documents.store'), $payload);
        $second->assertRedirect();

        $this->assertSame(1, Document::count());
        $this->assertStringContainsString('/documents/' . Document::first()->id, $second->headers->get('Location'));
        Mail::assertSent(InviteMail::class, 1);

        // Without a token, an identical send straight after is treated the same way.
        $bare = $payload;
        unset($bare['submission_token']);
        $bare['title'] = 'Second one';
        $this->post(route('platform-esign.documents.store'), $bare)->assertRedirect();
        $this->post(route('platform-esign.documents.store'), $bare)->assertRedirect();
        $this->assertSame(2, Document::count());

        // A refused send does not burn the token.
        $bad = array_merge($payload, ['submission_token' => 'tok-' . uniqid(), 'agency_id' => null, 'template_id' => $this->webTemplate('{{ agency_vat_no }}')->id]);
        $this->post(route('platform-esign.documents.store'), $bad)->assertSessionHasErrors('send');
        $this->post(route('platform-esign.documents.store'), $bad)->assertSessionHasErrors('send');
    }

    // ── INFO: junk query strings ───────────────────────────────────────────

    public function test_array_query_parameters_never_500_the_owner_lists(): void
    {
        $this->actingAs($this->owner());
        $this->webTemplate();
        $this->get(route('platform-esign.documents.index') . '?q[]=x&status[]=sent&sort[]=title&dir[]=asc&from[]=2026-01-01&to=not-a-date')->assertOk();
        $this->get(route('platform-esign.templates.index') . '?q[]=x&kind[]=other&state[]=archived&source[]=pdf&sort[]=name&dir[]=desc')->assertOk();
        $this->get(route('platform-esign.documents.index', ['status' => 'completed', 'from' => '2026-01-01', 'to' => '2026-12-31']))->assertOk();
    }

    // ── F-MED: migrations ──────────────────────────────────────────────────

    public function test_the_two_misdated_migrations_were_renamed_and_are_idempotent_and_model_free(): void
    {
        $dir = database_path('migrations');
        $this->assertFileDoesNotExist($dir . '/2026_10_10_130000_add_company_snapshot_to_platform_esign_documents.php');
        $this->assertFileDoesNotExist($dir . '/2026_10_10_140000_add_sender_to_platform_company.php');

        foreach (['2026_10_06_150000_add_company_snapshot_to_platform_esign_documents.php', '2026_10_06_150100_add_sender_to_platform_company.php',
                     '2026_10_06_150200_add_content_hash_to_platform_esign_documents.php'] as $name) {
            $this->assertFileExists($dir . '/' . $name);
            $src = file_get_contents($dir . '/' . $name);
            $this->assertDoesNotMatchRegularExpression('/^use App\\\\|PlatformCompany::|new \\\\?App\\\\/m', $src, "$name must not depend on an application model");
            $this->assertStringContainsString('hasColumn', $src);
            // The schema is already migrated, so running up() again is a no-op (what a DB that ran the old name sees).
            $m = require $dir . '/' . $name;
            $m->up();
            $m->up();
        }
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_esign_documents', 'company_snapshot'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_esign_documents', 'content_hash'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('platform_company', 'send_from_address'));
    }
}

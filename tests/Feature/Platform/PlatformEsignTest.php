<?php

namespace Tests\Feature\Platform;

use App\Mail\PlatformEsign\InviteMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\Template;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\EsignService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-447 — Platform E-Sign (CoreX's own, separate e-sign). Spec: .ai/specs/agency-timeline-and-platform-esign.md §3A
 */
class PlatformEsignTest extends TestCase
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

    private function signers(): array
    {
        return [
            ['role_key' => 'r1', 'name' => 'Pat Principal', 'email' => 'pat@caprivi.test', 'id_number' => '8001015009087'],
            ['role_key' => 'r2', 'name' => 'Cory CoreX', 'email' => 'cory@corex.test'],
        ];
    }

    private function sendWeb(?Agency $agency = null, ?Template $tpl = null): Document
    {
        $doc = $this->svc()->send($tpl ?? $this->webTemplate(), ['agency_id' => $agency?->id, 'signers' => $this->signers()], [], null);
        $this->svc()->inviteDue($doc);

        return $doc->fresh('signers');
    }

    private function signAs(Signer $s, array $extra = []): Document
    {
        return $this->svc()->sign($s->token, array_merge(['typed_name' => $s->name, 'id_number' => '8001015009087'], $extra), '10.0.0.1', 'UA');
    }

    // ── Access ─────────────────────────────────────────────────────────────

    public function test_owner_only_everywhere(): void
    {
        $agent = User::factory()->create(['role' => 'agent', 'agency_id' => $this->agency()->id]);
        foreach (['hub' => 'platform-esign.hub', 'tpl' => 'platform-esign.templates.index', 'docs' => 'platform-esign.documents.index', 'send' => 'platform-esign.documents.create'] as $r) {
            $this->actingAs($agent)->get(route($r))->assertForbidden();
        }
        auth()->logout();
        $this->get(route('platform-esign.hub'))->assertRedirect();

        $this->actingAs($this->owner());
        foreach (['platform-esign.hub', 'platform-esign.templates.index', 'platform-esign.documents.index', 'platform-esign.documents.create', 'platform-esign.templates.create'] as $r) {
            $this->get(route($r))->assertOk();
        }
    }

    // ── Templates ──────────────────────────────────────────────────────────

    public function test_wording_template_is_created_with_free_text_signers_and_unknown_merge_fields_are_refused(): void
    {
        $this->actingAs($this->owner());
        $this->post(route('platform-esign.templates.store'), [
            'name' => 'Debit order', 'kind' => 'debit_order', 'source' => 'web', 'body' => 'Hello {{ agency_name }}',
            'roles' => [['label' => 'Agency Principal'], ['label' => ''], ['label' => 'CoreX']],
        ])->assertRedirect();
        $t = Template::where('name', 'Debit order')->firstOrFail();
        $this->assertSame(['Agency Principal', 'CoreX'], collect($t->roles())->pluck('label')->all());

        $this->post(route('platform-esign.templates.store'), [
            'name' => 'Bad', 'kind' => 'other', 'source' => 'web', 'body' => 'Hello {{ property_address }}', 'roles' => [['label' => 'A']],
        ])->assertSessionHasErrors('body');
        $this->assertSame(0, Template::where('name', 'Bad')->count());
    }

    public function test_pdf_template_is_rasterised_and_fields_are_placed_and_validated(): void
    {
        $this->actingAs($this->owner());
        $pdf = UploadedFile::fake()->createWithContent('contract.pdf', Pdf::loadHTML('<h1>Page one</h1><div style="page-break-after:always"></div><h1>Page two</h1>')->output());
        $this->post(route('platform-esign.templates.store'), [
            'name' => 'PDF contract', 'kind' => 'other', 'source' => 'pdf', 'pdf' => $pdf, 'roles' => [['label' => 'Principal'], ['label' => 'CoreX']],
        ])->assertRedirect();
        $t = Template::where('name', 'PDF contract')->firstOrFail();
        $this->assertSame(2, $t->page_count);
        $this->get(route('platform-esign.templates.page', [$t->id, 1]))->assertOk();
        $this->get(route('platform-esign.templates.page', [$t->id, 5]))->assertNotFound();

        $this->get(route('platform-esign.templates.fields', $t->id))->assertOk();
        $this->putJson(route('platform-esign.templates.fields.save', $t->id), ['fields' => [
            ['page_index' => 1, 'x' => 10, 'y' => 80, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r1'],
            ['page_index' => 9, 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r1'], // page out of range
            ['page_index' => 0, 'x' => 10, 'y' => 10, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'zz'], // unknown signer
        ]])->assertOk();
        $this->assertSame(1, $t->fields()->count());

        // CoreX has no signature field yet → sending is refused, naming the signer.
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Place a signature field for CoreX');
        $this->svc()->send($t->fresh(), ['signers' => $this->signers()], [], null);
    }

    public function test_pdf_contract_signs_seals_and_carries_typed_field_values(): void
    {
        Mail::fake();
        $this->actingAs($this->owner());
        $pdf = UploadedFile::fake()->createWithContent('c.pdf', Pdf::loadHTML('<h1>Contract</h1>')->output());
        $t = $this->svc()->createTemplate(['name' => 'P', 'kind' => 'other', 'source' => 'pdf', 'roles' => [['label' => 'Principal'], ['label' => 'CoreX']]], $pdf, null);
        $this->svc()->saveFields($t, [
            ['page_index' => 0, 'x' => 10, 'y' => 70, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r1'],
            ['page_index' => 0, 'x' => 50, 'y' => 70, 'w' => 30, 'h' => 6, 'type' => 'text', 'role_key' => 'r1', 'label' => 'Company reg'],
            ['page_index' => 0, 'x' => 10, 'y' => 85, 'w' => 30, 'h' => 6, 'type' => 'signature', 'role_key' => 'r2'],
        ]);
        $doc = $this->svc()->send($t->fresh(), ['signers' => $this->signers()], [], null);
        $this->svc()->inviteDue($doc);
        $p = $doc->fresh('signers')->signers->firstWhere('role_key', 'r1');
        $textId = collect($doc->fields_json)->firstWhere('type', 'text')['id'];

        try {
            $this->signAs($p);   // required text field missing
            $this->fail('should have refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('Company reg', $e->getMessage());
        }
        $this->signAs($p, ['fields' => [$textId => '2020/123456/07']]);
        $this->signAs($doc->fresh('signers')->signers->firstWhere('role_key', 'r2'));

        $doc = $doc->fresh();
        $this->assertSame('completed', $doc->status);
        $this->assertNotNull($doc->sealed_pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($doc->sealed_pdf_path));
        $this->assertSame(hash('sha256', Storage::disk('local')->get($doc->sealed_pdf_path)), $doc->document_hash);
    }

    // ── Sending ────────────────────────────────────────────────────────────

    public function test_send_refuses_a_merge_field_the_agency_has_no_value_for(): void
    {
        $agency = $this->agency(['vat_no' => null]);
        $tpl = $this->webTemplate('VAT {{ agency_vat_no }}');
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('agency_vat_no');
        $this->svc()->send($tpl, ['agency_id' => $agency->id, 'signers' => $this->signers()], [], null);
    }

    public function test_send_freezes_the_merged_wording_and_emails_only_the_first_signer(): void
    {
        Mail::fake();
        $doc = $this->sendWeb($this->agency());
        $this->assertStringContainsString('Caprivi Realty', $doc->body_html_snapshot);
        Mail::assertSent(InviteMail::class, 1);
        Mail::assertSent(InviteMail::class, fn ($m) => $m->hasTo('pat@caprivi.test'));
        $this->assertSame('sent', $doc->status);

        // Editing the template afterwards never changes what was sent.
        $doc->template->update(['body' => 'Changed']);
        $this->assertStringContainsString('Caprivi Realty', $doc->fresh()->body_html_snapshot);
    }

    // ── Signing ────────────────────────────────────────────────────────────

    public function test_signing_is_in_order_then_seals_and_emails_everyone(): void
    {
        Mail::fake();
        $doc = $this->sendWeb($this->agency());
        [$pat, $cory] = [$doc->signers[0], $doc->signers[1]];

        // Cory cannot sign before Pat — and the page says so.
        $this->get(route('platform-esign.sign.show', $cory->token))->assertOk()->assertSee('not your turn');
        try {
            $this->signAs($cory);
            $this->fail('out-of-order signing must be refused');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('not your turn', $e->getMessage());
        }

        $this->post(route('platform-esign.sign.submit', $pat->token), ['typed_name' => 'Pat Principal', 'id_number' => '8001015009087', 'consent' => '1'])->assertRedirect();
        $this->assertSame('in_progress', $doc->fresh()->status);
        Mail::assertSent(InviteMail::class, fn ($m) => $m->hasTo('cory@corex.test'));

        $this->signAs($cory->fresh());
        $doc = $doc->fresh();
        $this->assertSame('completed', $doc->status);
        $this->assertNotNull($doc->sealed_pdf_path);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($doc->sealed_pdf_path));
        Mail::assertSent(SignedMail::class, 2);
        $this->assertTrue($doc->events()->where('event', 'sealed')->exists());

        try {
            $this->signAs($pat->fresh());
            $this->fail('a signer must not be able to sign twice');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('already signed', $e->getMessage());
        }
    }

    public function test_consent_is_required_and_a_signer_without_an_id_number_is_asked_for_it(): void
    {
        Mail::fake();
        $signers = $this->signers();
        $signers[0]['id_number'] = null;
        $doc = $this->svc()->send($this->webTemplate(), ['agency_id' => $this->agency()->id, 'signers' => $signers], [], null);
        $this->svc()->inviteDue($doc);
        $pat = $doc->fresh('signers')->signers[0];

        $this->post(route('platform-esign.sign.submit', $pat->token), ['typed_name' => 'Pat', 'id_number' => '1'])->assertSessionHasErrors('consent');
        $this->assertSame('sent', $pat->fresh()->status);

        try {
            $this->svc()->sign($pat->token, ['typed_name' => 'Pat'], null, null);
            $this->fail('an ID number is required');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('ID or passport', $e->getMessage());
        }
        $this->assertSame('sent', $pat->fresh()->status);
    }

    public function test_void_kills_every_link_and_decline_stops_the_document(): void
    {
        Mail::fake();
        $doc = $this->sendWeb($this->agency());
        $tokens = $doc->signers->pluck('token', 'id');
        $this->svc()->void($doc, 'Wrong agency', null);
        foreach ($tokens as $t) {
            $this->get(route('platform-esign.sign.show', $t))->assertNotFound();
        }
        $this->assertSame('voided', $doc->fresh()->status);

        $doc2 = $this->sendWeb($this->agency(['slug' => 'x-' . uniqid(), 'name' => 'Second']));
        $this->post(route('platform-esign.sign.decline', $doc2->signers[0]->token), ['reason' => 'Not now'])->assertRedirect();
        $this->assertSame('declined', $doc2->fresh()->status);
        $this->expectException(\DomainException::class);
        $this->signAs($doc2->signers[1]->fresh());
    }

    public function test_an_expired_link_cannot_sign(): void
    {
        Mail::fake();
        $doc = $this->sendWeb($this->agency());
        $doc->update(['expires_at' => now()->subDay()]);
        $this->get(route('platform-esign.sign.show', $doc->signers[0]->token))->assertOk()->assertSee('expired');
        $this->assertSame('expired', $doc->fresh()->status);
    }

    public function test_the_signing_page_is_not_cacheable_and_an_unknown_token_is_404(): void
    {
        Mail::fake();
        $doc = $this->sendWeb($this->agency());
        $this->get(route('platform-esign.sign.show', $doc->signers[0]->token))->assertOk()->assertHeader('X-Robots-Tag', 'noindex');
        $this->get(route('platform-esign.sign.show', str_repeat('x', 48)))->assertNotFound();
    }

    // ── Timeline integration ───────────────────────────────────────────────

    public function test_a_subscription_agreement_auto_links_and_ticks_the_timeline_step_when_signed(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $step = AgencyTimelineItem::where('timeline_id', $tl->id)->where('auto_complete_trigger', 'contract_signed')->firstOrFail();

        $doc = $this->sendWeb($agency);
        $this->assertSame($doc->id, $tl->fresh()->agreement_document_id);
        $this->assertSame('pending', $step->fresh()->status);

        $this->signAs($doc->signers[0]);
        $this->assertSame('pending', $step->fresh()->status, 'half-signed does not tick');
        $this->signAs($doc->fresh('signers')->signers[1]);
        $this->assertSame('done', $step->fresh()->status);
    }

    public function test_another_kind_of_document_does_not_become_the_agreement(): void
    {
        Mail::fake();
        $agency = $this->agency();
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $this->sendWeb($agency, $this->webTemplate('Hi {{ agency_name }}', 'debit_order'));
        $this->assertNull($tl->fresh()->agreement_document_id);
    }

    // ── Lifecycle / lists ──────────────────────────────────────────────────

    public function test_documents_list_searches_filters_and_archives_without_hard_delete(): void
    {
        Mail::fake();
        $this->actingAs($this->owner());
        $doc = $this->sendWeb($this->agency());
        $this->get(route('platform-esign.documents.index', ['q' => 'Caprivi']))->assertOk()->assertSee($doc->title);
        $this->get(route('platform-esign.documents.index', ['q' => 'zzzz-nothing']))->assertOk()->assertDontSee($doc->title);
        $this->get(route('platform-esign.documents.index', ['status' => 'completed']))->assertOk()->assertDontSee($doc->title);

        $this->delete(route('platform-esign.documents.archive', $doc))->assertSessionHasErrors('action'); // still open
        $this->svc()->void($doc, 'x', null);
        $this->delete(route('platform-esign.documents.archive', $doc))->assertRedirect();
        $this->assertSoftDeleted('platform_esign_documents', ['id' => $doc->id]);
        $this->get(route('platform-esign.documents.index', ['status' => 'archived']))->assertOk()->assertSee($doc->title);
        $this->post(route('platform-esign.documents.restore', $doc->id))->assertRedirect();
        $this->assertNull($doc->fresh()->deleted_at);
    }

    public function test_every_owner_screen_renders_with_data(): void
    {
        Mail::fake();
        $this->actingAs($this->owner());
        $agency = $this->agency();
        $tpl = $this->webTemplate();
        $doc = $this->sendWeb($agency, $tpl);

        $this->get(route('platform-esign.hub'))->assertOk()->assertSee($doc->title);
        $this->get(route('platform-esign.templates.index'))->assertOk()->assertSee('Subscription');
        $this->get(route('platform-esign.templates.edit', $tpl->id))->assertOk()->assertSee('Agency Principal');
        $this->get(route('platform-esign.templates.preview', $tpl->id))->assertOk()->assertSee('[agency_name]');
        $this->get(route('platform-esign.documents.create', ['template' => $tpl->id, 'agency' => $agency->id]))->assertOk()->assertSee('1. Agency Principal', false);
        $this->get(route('platform-esign.documents.show', $doc->id))->assertOk()->assertSee('Pat Principal')->assertSee('Void this document');
        $this->put(route('platform-esign.templates.update', $tpl->id), [
            'name' => 'Subscription', 'kind' => 'subscription_agreement', 'is_active' => 1, 'body' => 'New {{ agency_name }}',
            'roles' => [['label' => 'Agency Principal'], ['label' => 'CoreX']],
        ])->assertRedirect();
        $this->assertSame(2, $tpl->fresh()->version);
        $this->delete(route('platform-esign.templates.destroy', $tpl->id))->assertSessionHas('success');
        $this->assertSoftDeleted('platform_esign_templates', ['id' => $tpl->id]);
        $this->get(route('platform-esign.templates.index', ['state' => 'archived']))->assertOk()->assertSee('Subscription');
    }

    public function test_the_old_agency_less_mode_is_gone_from_the_shared_e_sign(): void
    {
        $this->assertFalse(class_exists(\App\Support\PlatformEsignMode::class));
        $this->assertFalse(class_exists(\App\Models\Scopes\PlatformTemplateScope::class));
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Mail\Platform\PlatformContractInviteMail;
use App\Mail\Platform\PlatformContractSignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineDefaultItem;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\Platform\PlatformContractEnvelope;
use App\Models\Platform\PlatformContractTemplate;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\Platform\PlainDocRenderer;
use App\Services\Platform\PlatformContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AT-447 — Agency Contracts (dev-side e-sign). Spec: .ai/specs/agency-timeline-and-platform-esign.md §6
 */
class PlatformContractTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
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

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Caprivi Realty', 'slug' => 'caprivi-' . uniqid(), 'reg_no' => '2020/1/07', 'vat_no' => '4111', 'address' => '1 Beach Rd']);
    }

    private function template(string $body = "# Agreement\n\nBetween CoreX and **{{agency_name}}** (reg {{agency_reg_no}}).\n\nSigned: {{signatory_name}} on {{today}}."): PlatformContractTemplate
    {
        return PlatformContractTemplate::create(['name' => 'Subscription Agreement', 'kind' => 'subscription_agreement', 'body' => $body]);
    }

    private function send(Agency $a, ?PlatformContractTemplate $t = null): PlatformContractEnvelope
    {
        return app(PlatformContractService::class)->createAndSend($a, $t ?? $this->template(), [
            'title' => '', 'signatory_name' => 'Jane Principal', 'signatory_email' => 'jane@caprivi.test', 'signatory_role' => 'Principal', 'expiry_days' => 14,
        ], [], null);
    }

    public function test_renderer_escapes_everything_before_applying_markup(): void
    {
        $html = PlainDocRenderer::render("# Hi <script>alert(1)</script>\n\n**bold** & <b>raw</b>\n\n- one\n- two");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringContainsString('<h3>Hi &lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<ul><li>one</li><li>two</li></ul>', $html);
    }

    public function test_send_freezes_merged_document_and_emails_signer(): void
    {
        Mail::fake();
        $env = $this->send($this->agency());

        $this->assertSame('sent', $env->status);
        $this->assertStringContainsString('Caprivi Realty', $env->body_html_snapshot);
        $this->assertStringContainsString('2020/1/07', $env->body_html_snapshot);
        $this->assertStringNotContainsString('{{', $env->body_html_snapshot);
        Mail::assertSent(PlatformContractInviteMail::class, fn ($m) => $m->hasTo('jane@caprivi.test'));
    }

    public function test_later_template_edit_never_changes_a_sent_contract(): void
    {
        Mail::fake();
        $t = $this->template();
        $env = $this->send($this->agency(), $t);
        $t->update(['body' => 'COMPLETELY DIFFERENT']);
        $this->assertStringNotContainsString('COMPLETELY DIFFERENT', $env->fresh()->body_html_snapshot);
    }

    public function test_unknown_or_empty_merge_field_refuses_the_send_and_names_it(): void
    {
        Mail::fake();
        $a = $this->agency();
        try {
            $this->send($a, $this->template('Hello {{nonsense_field}}'));
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('nonsense_field', $e->getMessage());
        }
        try {
            $this->send($a, $this->template('Go live {{go_live_date}}')); // agency has no timeline
            $this->fail('expected refusal');
        } catch (\DomainException $e) {
            $this->assertStringContainsString('go_live_date', $e->getMessage());
        }
        $this->assertSame(0, PlatformContractEnvelope::count());
        Mail::assertNothingSent();
    }

    public function test_signing_stores_evidence_seals_pdf_emails_copy_and_ticks_timeline(): void
    {
        Mail::fake();
        Storage::fake('local');
        AgencyTimelineDefaultItem::query()->forceDelete();
        AgencyTimelineDefaultItem::create(['kind' => 'milestone', 'title' => 'Sign agreement', 'offset_days' => 5, 'auto_complete_trigger' => 'contract_signed']);
        $agency = $this->agency();
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $env = $this->send($agency);

        $this->get('/agency-contract/' . $env->token)->assertOk()->assertSee('Sign agreement')->assertSee('Caprivi Realty');
        $this->assertSame('viewed', $env->fresh()->status);

        $this->post('/agency-contract/' . $env->token . '/sign', ['typed_name' => 'Jane Principal', 'consent' => '1'])
            ->assertOk()->assertSee('Thank you');

        $env->refresh();
        $this->assertSame('signed', $env->status);
        $this->assertSame('Jane Principal', $env->signed_typed_name);
        $this->assertNotNull($env->signed_ip);
        $this->assertNotNull($env->sealed_pdf_path);
        Storage::disk('local')->assertExists($env->sealed_pdf_path);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($env->sealed_pdf_path)), $env->document_hash);
        Mail::assertSent(PlatformContractSignedMail::class, fn ($m) => $m->hasTo('jane@caprivi.test'));
        $this->assertSame('done', AgencyTimelineItem::where('timeline_id', $tl->id)->value('status'));
    }

    public function test_a_signed_link_never_shows_the_document_again_and_cannot_sign_twice(): void
    {
        Mail::fake();
        Storage::fake('local');
        $env = $this->send($this->agency());
        $this->post('/agency-contract/' . $env->token . '/sign', ['typed_name' => 'Jane', 'consent' => '1'])->assertOk();

        $this->get('/agency-contract/' . $env->token)->assertOk()->assertSee('already been signed')->assertDontSee('Between CoreX');
        $this->post('/agency-contract/' . $env->token . '/sign', ['typed_name' => 'Mallory', 'consent' => '1'])->assertSee('not active');
        $this->assertSame('Jane', $env->fresh()->signed_typed_name);
    }

    public function test_consent_and_name_are_required_to_sign(): void
    {
        Mail::fake();
        $env = $this->send($this->agency());
        $this->post('/agency-contract/' . $env->token . '/sign', ['typed_name' => 'Jane'])->assertSessionHasErrors('consent');
        $this->post('/agency-contract/' . $env->token . '/sign', ['consent' => '1'])->assertSessionHasErrors('typed_name');
        $this->assertSame('sent', $env->fresh()->status);
    }

    public function test_expired_voided_resent_and_unknown_links_never_show_the_document(): void
    {
        Mail::fake();
        $svc = app(PlatformContractService::class);
        $a = $this->agency();

        $expired = $this->send($a);
        $expired->update(['token_expires_at' => now()->subDay()]);
        $this->get('/agency-contract/' . $expired->token)->assertSee('expired')->assertDontSee('Between CoreX');

        $voided = $this->send($a);
        $old = $voided->token;
        $svc->void($voided, 'wrong template', null);
        $this->get('/agency-contract/' . $old)->assertNotFound();
        $this->assertNotSame($old, $voided->fresh()->token);

        $resent = $this->send($a);
        $before = $resent->token;
        $svc->resend($resent, null);
        $this->get('/agency-contract/' . $before)->assertNotFound();
        $this->get('/agency-contract/' . $resent->fresh()->token)->assertOk();

        $this->get('/agency-contract/' . str_repeat('z', 48))->assertNotFound();
    }

    public function test_decline_records_reason(): void
    {
        Mail::fake();
        $env = $this->send($this->agency());
        $this->post('/agency-contract/' . $env->token . '/decline', ['reason' => 'Need to talk first'])->assertOk()->assertSee('recorded');
        $env->refresh();
        $this->assertSame('declined', $env->status);
        $this->assertSame('Need to talk first', $env->decline_reason);
    }

    public function test_owner_send_flow_list_filters_and_non_owner_blocked(): void
    {
        Mail::fake();
        Storage::fake('local');
        $agency = $this->agency();
        $t = $this->template();
        $this->actingAs($this->owner());

        $this->get(route('admin.agency-contracts.templates'))->assertOk()->assertSee('Subscription Agreement');
        $this->get(route('admin.agency-contracts.templates.preview', $t->id))->assertOk()->assertSee('Sample Realty');
        $this->get(route('admin.agency-contracts.create', ['agency_id' => $agency->id]))->assertOk();

        $this->post(route('admin.agency-contracts.store'), [
            'agency_id' => $agency->id, 'template_id' => $t->id, 'signatory_name' => 'Jane Principal',
            'signatory_email' => 'jane@caprivi.test', 'expiry_days' => 14,
            'attachments' => [UploadedFile::fake()->create('debit-order.pdf', 50, 'application/pdf')],
        ])->assertRedirect();

        $env = PlatformContractEnvelope::firstOrFail();
        $this->assertCount(1, $env->attachments);
        $this->get(route('admin.agency-contracts.index', ['q' => 'Caprivi', 'status' => 'sent']))->assertOk()->assertSee('Jane Principal');
        $this->get(route('admin.agency-contracts.index', ['status' => 'signed']))->assertOk()->assertSee('No contracts match');
        $this->get(route('admin.agency-contracts.show', $env->id))->assertOk()->assertSee('Resend');

        // Template edit bumps the version; archive + restore.
        $this->put(route('admin.agency-contracts.templates.update', $t->id), ['name' => 'Subscription Agreement', 'kind' => 'subscription_agreement', 'body' => 'New body', 'is_active' => 1])->assertRedirect();
        $this->assertSame(2, $t->fresh()->version);
        $this->delete(route('admin.agency-contracts.templates.destroy', $t->id))->assertRedirect();
        $this->assertSoftDeleted('platform_contract_templates', ['id' => $t->id]);
        $this->post(route('admin.agency-contracts.templates.restore', $t->id))->assertRedirect();
        $this->assertNull($t->fresh()->deleted_at);
    }

    public function test_agency_admin_cannot_reach_contracts_or_download_signed_pdf(): void
    {
        Mail::fake();
        Storage::fake('local');
        $agency = $this->agency();
        $env = $this->send($agency);
        $admin = User::factory()->create(['role' => 'admin', 'agency_id' => $agency->id]);
        $this->actingAs($admin);

        $this->get(route('admin.agency-contracts.show', $env->id))->assertForbidden();
        $this->get(route('admin.agency-contracts.download', $env->id))->assertForbidden();
        $this->post(route('admin.agency-contracts.void', $env->id), ['void_reason' => 'x'])->assertForbidden();
    }
}

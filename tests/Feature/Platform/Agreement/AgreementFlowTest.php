<?php

namespace Tests\Feature\Platform\Agreement;

use App\Mail\PlatformEsign\AgreementInviteMail;
use App\Mail\PlatformEsign\AgreementReceivedMail;
use App\Mail\PlatformEsign\AgreementSignedMail;
use App\Mail\PlatformEsign\SignedMail;
use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Models\Platform\AgencyTimelineItem;
use App\Models\PlatformEsign\Document;
use App\Models\PlatformEsign\Signer;
use App\Models\PlatformEsign\WordingVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\Platform\AgencyTimelineService;
use App\Services\PlatformEsign\Agreement\AgreementContent;
use App\Services\PlatformEsign\Agreement\AgreementSample;
use App\Services\PlatformEsign\Agreement\AgreementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Subscription Agreement web document — spec §11. Send → recipient fills / initials / signs → RR countersigns → sealed PDF.
 */
class AgreementFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
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

    private function agency(array $extra = []): Agency
    {
        return Agency::create(array_merge(['name' => 'Caprivi Realty', 'slug' => 'caprivi-' . uniqid(), 'reg_no' => '2020/123456/07', 'vat_no' => '4123456789', 'address' => '1 Beach Rd, Margate'], $extra));
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

    private function tokenOf(Document $doc, string $role = 'r1'): string
    {
        return Signer::where('document_id', $doc->id)->where('role_key', $role)->value('token');
    }

    /** A complete, valid set of recipient entries (the calibration sample + both signatures). */
    private function fullValues(Document $doc): array
    {
        $v = AgreementSample::ctx($doc->wording)['values'];

        return $v + ['sigA' => self::PNG, 'sigM' => self::PNG];
    }

    private function save(string $token, array $values, int $rev = 0)
    {
        return $this->postJson(route('platform-esign.agreement.save', $token), ['rev' => $rev, 'values' => $values]);
    }

    private function initialAll(string $token, int $total): void
    {
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'pp'])->assertOk();
        foreach (range(1, $total) as $p) {
            $this->postJson(route('platform-esign.agreement.initial-page', [$token, $p]))->assertOk();
        }
    }

    private function agencySignsEverything(Document $doc): void
    {
        $token = $this->tokenOf($doc);
        $total = $this->svc()->totalPages($doc);
        $this->initialAll($token, $total);
        $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $this->fullValues($doc), 'id_number' => '8001015009087', 'consent' => 1])->assertOk();
    }

    // ── Send ───────────────────────────────────────────────────────────────

    public function test_send_creates_a_document_pinned_to_the_wording_version_with_both_signers_and_emails_the_link(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);

        $this->assertSame('webdoc', $doc->source);
        $this->assertSame('sent', $doc->status);
        $this->assertSame('1.0', $doc->wording->version);
        $this->assertSame(sprintf('CX%06d', $doc->id), $doc->contract_ref);
        $this->assertCount(2, $doc->signers);
        $this->assertSame('Agency', $doc->signers[0]->role_label);
        $this->assertSame('RR Technologies (Pty) Ltd', $doc->signers[1]->role_label);
        $this->assertSame(1, $doc->signers[0]->sign_order);
        $this->assertSame(2, $doc->signers[1]->sign_order);
        $this->assertTrue($doc->expires_at->isSameDay(now()->addDays(30)), 'expiry defaults to 30 days');
        $this->assertSame('Pat Principal', $doc->form_data['sig_name']);
        Mail::assertSent(AgreementInviteMail::class, fn ($m) => $m->hasTo('pat@caprivi.test') && str_contains($m->signUrl, $doc->signers[0]->token));
        Mail::assertNotSent(AgreementInviteMail::class, fn ($m) => $m->hasTo($owner->email));
    }

    public function test_send_prefills_from_an_existing_agency_record_and_links_its_timeline(): void
    {
        $owner = $this->owner();
        $agency = $this->agency(['trading_name' => 'Caprivi Coastal', 'email' => 'office@caprivi.test']);
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);

        $doc = $this->send($owner, ['agency_id' => $agency->id]);

        $this->assertSame('Caprivi Realty', $doc->form_data['registered_name']);
        $this->assertSame('Caprivi Coastal', $doc->form_data['trading_name']);
        $this->assertSame('2020/123456/07', $doc->form_data['reg_no']);
        $this->assertSame('office@caprivi.test', $doc->form_data['notice_email']);
        $this->assertSame($doc->id, $tl->fresh()->agreement_document_id);
    }

    public function test_the_sender_screens_are_owner_only(): void
    {
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->get(route('platform-esign.agreements.create'))->assertForbidden();
        $this->actingAs($agent)->post(route('platform-esign.agreements.store'), ['name' => 'X', 'email' => 'x@y.test'])->assertForbidden();

        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->actingAs($agent)->get(route('platform-esign.agreements.review', $doc->id))->assertForbidden();
        $this->actingAs($agent)->get(route('platform-esign.agreements.countersign', $doc->id))->assertForbidden();
        $this->actingAs($agent)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'da_account'])->assertForbidden();
        $this->actingAs($agent)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [])->assertForbidden();
    }

    public function test_one_click_send_screen_sends_and_lands_on_the_document_with_the_link(): void
    {
        $owner = $this->owner();
        Mail::fake();
        $this->actingAs($owner)->get(route('platform-esign.agreements.create'))->assertOk()->assertSee('Send Subscription Agreement');
        $res = $this->actingAs($owner)->post(route('platform-esign.agreements.store'), ['name' => 'Pat Principal', 'email' => 'pat@caprivi.test']);
        $doc = Document::latest('id')->firstOrFail();
        $res->assertRedirect(route('platform-esign.documents.show', $doc->id));
        $this->actingAs($owner)->get(route('platform-esign.documents.show', $doc->id))->assertOk()
            ->assertSee($doc->contract_ref)->assertSee(route('platform-esign.agreement.show', $this->tokenOf($doc)), false)->assertSee('Sent');
        Mail::assertSent(AgreementInviteMail::class);
        $this->actingAs($owner)->post(route('platform-esign.agreements.store'), ['name' => '', 'email' => 'nope'])->assertSessionHasErrors(['name', 'email']);
    }

    public function test_the_variation_must_be_described_and_is_rr_side_only(): void
    {
        $owner = $this->owner();
        $this->expectException(\DomainException::class);
        $this->send($owner, ['variation_amount' => '500']);
    }

    // ── Recipient page: scoping ────────────────────────────────────────────

    public function test_the_recipient_page_renders_every_page_without_login_and_is_not_indexed(): void
    {
        $doc = $this->send($this->owner());
        $total = $this->svc()->totalPages($doc);

        $res = $this->get(route('platform-esign.agreement.show', $this->tokenOf($doc)));
        $res->assertOk()->assertHeader('X-Robots-Tag', 'noindex')->assertSee('Subscription Agreement')->assertSee('Initial this page');
        $res->assertSee('Page ' . $total . ' of ' . $total);
        $res->assertSee('Version 1.0 — 28 September 2026');
        $res->assertSee('CoreX OS Subscription Agreement');
        $this->assertGreaterThanOrEqual(15, $total);
        $this->assertSame('opened', Signer::where('document_id', $doc->id)->where('role_key', 'r1')->value('first_viewed_at') ? 'opened' : 'not');
    }

    public function test_tokens_are_scoped_rr_token_unknown_token_and_the_generic_signer_do_not_open_it(): void
    {
        $doc = $this->send($this->owner());
        $this->get(route('platform-esign.agreement.show', $this->tokenOf($doc, 'r2')))->assertNotFound();
        $this->get(route('platform-esign.agreement.show', str_repeat('a', 48)))->assertNotFound();
        $this->postJson(route('platform-esign.agreement.save', $this->tokenOf($doc, 'r2')), ['rev' => 0, 'values' => ['plan' => 'team']])->assertNotFound();
        // the generic e-sign signer link of a web document hands over to the agreement page, never to the generic signer
        $this->get(route('platform-esign.sign.show', $this->tokenOf($doc)))->assertRedirect(route('platform-esign.agreement.show', $this->tokenOf($doc)));
        $this->post(route('platform-esign.sign.submit', $this->tokenOf($doc)), ['typed_name' => 'X', 'consent' => 1])->assertNotFound();
    }

    // ── Autosave ───────────────────────────────────────────────────────────

    public function test_autosave_stores_values_encrypted_and_moves_the_document_to_in_progress(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->tokenOf($doc);

        $res = $this->save($token, ['registered_name' => 'Caprivi Realty (Pty) Ltd', 'plan' => 'agency', 'agents' => '25', 'da_account' => self::ACCOUNT])->assertOk();
        $this->assertSame(1, $res->json('rev'));
        $this->assertEqualsWithDelta(7920.0, $res->json('calc.total'), 0.001);

        $fresh = $doc->fresh();
        $this->assertSame('in_progress', $fresh->status);
        $this->assertSame(self::ACCOUNT, $fresh->form_data['da_account']);
        $raw = \DB::table('platform_esign_documents')->where('id', $doc->id)->value('form_data');
        $this->assertStringNotContainsString(self::ACCOUNT, $raw, 'bank details are encrypted at rest');
        $this->assertStringNotContainsString('Caprivi', $raw);
        foreach ($fresh->events as $e) {
            $this->assertStringNotContainsString(self::ACCOUNT, (string) $e->detail, 'entered values never reach the audit trail');
        }
        $this->assertTrue($fresh->events->contains('event', 'saved'));
    }

    public function test_autosave_ignores_rr_side_and_unknown_fields_and_cleans_junk(): void
    {
        $doc = $this->send($this->owner(), ['variation_text' => 'Launch discount', 'variation_amount' => '300']);
        $this->save($this->tokenOf($doc), ['variation_amount' => '99999', 'variation_text' => 'hack', 'rr_name' => 'Evil', 'bogus' => 'x', 'plan' => 'platinum', 'agents' => 'a2b'])->assertOk();
        $fresh = $doc->fresh();
        $this->assertSame('300', $fresh->rr_data['variation_amount']);
        $this->assertSame('Launch discount', $fresh->rr_data['variation_text']);
        $this->assertArrayNotHasKey('bogus', $fresh->form_data);
        $this->assertSame('', $fresh->form_data['plan']);
        $this->assertSame('2', $fresh->form_data['agents']);
    }

    public function test_a_second_tab_saving_against_a_stale_revision_gets_a_conflict_with_the_latest_values(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->tokenOf($doc);
        $this->save($token, ['trading_name' => 'First tab'], 0)->assertOk();
        $res = $this->save($token, ['trading_name' => 'Second tab'], 0);
        $res->assertStatus(409)->assertJsonPath('conflict', true)->assertJsonPath('rev', 1)->assertJsonPath('values.trading_name', 'First tab');
        $this->assertSame('First tab', $doc->fresh()->form_data['trading_name']);
        $this->save($token, ['trading_name' => 'Second tab'], 1)->assertOk();
    }

    public function test_an_expired_link_is_refused_with_a_clear_message_and_resend_reissues_keeping_the_entries(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $old = $this->tokenOf($doc);
        $this->save($old, ['trading_name' => 'Kept'])->assertOk();
        $doc->update(['expires_at' => now()->subDay()]);

        $this->get(route('platform-esign.agreement.show', $old))->assertOk()->assertSee('This link has expired');
        $this->save($old, ['trading_name' => 'x'], 1)->assertStatus(422);
        $this->assertSame('expired', $doc->fresh()->status);

        Mail::fake();
        $this->actingAs($owner)->post(route('platform-esign.documents.resend', $doc->id))->assertSessionHas('success');
        $new = $this->tokenOf($doc->fresh());
        $this->assertNotSame($old, $new);
        $this->get(route('platform-esign.agreement.show', $old))->assertNotFound();
        $this->get(route('platform-esign.agreement.show', $new))->assertOk()->assertSee('Kept', false);
        Mail::assertSent(AgreementInviteMail::class);
    }

    // ── Initials ───────────────────────────────────────────────────────────

    public function test_initials_are_one_tap_per_page_and_fixed_once_a_page_is_initialled(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->tokenOf($doc);
        $total = $this->svc()->totalPages($doc);

        $this->postJson(route('platform-esign.agreement.initial-page', [$token, 1]))->assertStatus(422); // no initials yet
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'p.p'])->assertOk()->assertJsonPath('initials', 'PP');
        $this->postJson(route('platform-esign.agreement.initial-page', [$token, 1]))->assertOk()->assertJsonPath('done', 1);
        $this->postJson(route('platform-esign.agreement.initial-page', [$token, 1]))->assertOk()->assertJsonPath('done', 1); // idempotent
        $this->postJson(route('platform-esign.agreement.initial-page', [$token, $total + 1]))->assertStatus(422);
        $this->postJson(route('platform-esign.agreement.initial-page', [$token, 0]))->assertStatus(422);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'ZZ'])->assertStatus(422);
        $this->assertTrue($doc->fresh()->events->contains('event', 'page_initialled'));
    }

    // ── Submit ─────────────────────────────────────────────────────────────

    public function test_submit_refuses_until_everything_is_filled_signed_and_every_page_initialled(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->tokenOf($doc);
        $res = $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => ['registered_name' => 'X'], 'consent' => 0])->assertStatus(422);
        $errors = $res->json('errors');
        foreach (['reg_no', 'plan', 'entity', 'da_account', 'sigA', 'sigM', 'm_first_payment', 'consent', 'initials', 'id_number'] as $k) {
            $this->assertArrayHasKey($k, $errors, "missing error for {$k}");
        }
        $this->assertArrayNotHasKey('vat_no', $errors);
        $this->assertNotEquals('awaiting_countersign', $doc->fresh()->status);
    }

    public function test_submit_needs_every_page_initialled(): void
    {
        $doc = $this->send($this->owner());
        $token = $this->tokenOf($doc);
        $this->postJson(route('platform-esign.agreement.initials', $token), ['initials' => 'PP'])->assertOk();
        $this->postJson(route('platform-esign.agreement.initial-page', [$token, 1]))->assertOk();
        $res = $this->postJson(route('platform-esign.agreement.submit', $token), ['values' => $this->fullValues($doc), 'id_number' => '8001015009087', 'consent' => 1])->assertStatus(422);
        $this->assertArrayHasKey('pages', $res->json('errors'));
        $this->assertStringContainsString('1 of ' . $this->svc()->totalPages($doc), $res->json('errors.pages'));
    }

    public function test_a_complete_submit_moves_it_to_awaiting_countersign_notifies_rr_and_a_second_submit_is_refused(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        Mail::fake();
        $this->agencySignsEverything($doc);

        $fresh = $doc->fresh(['signers']);
        $this->assertSame('awaiting_countersign', $fresh->status);
        $a = $fresh->signers[0];
        $this->assertSame('signed', $a->status);
        $this->assertSame('PP', $a->initials);
        $this->assertNotNull($a->signature_image);
        $this->assertNotNull($a->signature2_image);
        $this->assertSame('8001015009087', $a->id_number);
        Mail::assertSent(AgreementReceivedMail::class, fn ($m) => $m->hasTo($owner->email));

        $this->postJson(route('platform-esign.agreement.submit', $this->tokenOf($doc)), ['values' => $this->fullValues($doc), 'id_number' => '8001015009087', 'consent' => 1])->assertStatus(422);
        $this->save($this->tokenOf($doc), ['trading_name' => 'Changed after signing'], 99)->assertStatus(422);
        $this->assertSame('Caprivi Coastal Realty Holdings (Pty) Ltd t/a Caprivi Realty', $doc->fresh()->form_data['trading_name']);
        $this->get(route('platform-esign.agreement.show', $this->tokenOf($doc)))->assertOk()->assertSee('countersign');
    }

    // ── RR countersign ─────────────────────────────────────────────────────

    public function test_rr_cannot_countersign_before_the_agency_has_signed(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertNotFound();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => ['rr_name' => 'J'], 'initials' => 'JR', 'pages' => [1]])->assertStatus(422);
    }

    public function test_countersign_needs_every_page_and_the_rr_fields_and_cannot_touch_the_agencys_entries(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner, ['variation_text' => 'Launch discount', 'variation_amount' => '300']);
        $this->agencySignsEverything($doc);
        $total = $this->svc()->totalPages($doc);
        $before = $doc->fresh()->form_data;

        $this->actingAs($owner)->get(route('platform-esign.agreements.countersign', $doc->id))->assertOk()->assertSee('Countersign and seal');

        $res = $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), ['values' => ['rr_name' => 'Johan Reichel'], 'initials' => 'JR', 'pages' => [1, 2]])->assertStatus(422);
        foreach (['rr_capacity', 'rr_place', 'rr_date', 'sigR', 'pages'] as $k) {
            $this->assertArrayHasKey($k, $res->json('errors'), $k);
        }

        // RR tries to rewrite the agency's account number, the variation and the monthly total while countersigning
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG,
                'da_account' => '99999999', 'm_amount' => '1', 'variation_amount' => '0', 'variation_text' => 'gone', 'plan' => 'team'],
            'initials' => 'jr', 'pages' => range(1, $total),
        ])->assertOk();

        $fresh = $doc->fresh();
        $this->assertSame($before, $fresh->form_data, 'the agency\'s entries are untouched by the countersign');
        $this->assertSame('300', $fresh->rr_data['variation_amount']);
        $this->assertSame('Launch discount', $fresh->rr_data['variation_text']);
        $this->assertSame('completed', $fresh->status);
    }

    public function test_full_flow_seals_a_pdf_emails_both_parties_ticks_the_timeline_and_audits_everything(): void
    {
        $owner = $this->owner();
        $agency = $this->agency();
        $tl = app(AgencyTimelineService::class)->start($agency, now(), null);
        $step = AgencyTimelineItem::where('timeline_id', $tl->id)->where('auto_complete_trigger', 'contract_signed')->firstOrFail();
        $doc = $this->send($owner, ['agency_id' => $agency->id]);
        $this->save($this->tokenOf($doc), ['registered_name' => 'Caprivi Realty', 'da_account' => self::ACCOUNT])->assertOk();
        $this->agencySignsEverything($doc);
        $this->assertSame('pending', $step->fresh()->status);
        $total = $this->svc()->totalPages($doc);

        Mail::fake();
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.countersign.store', $doc->id), [
            'values' => ['rr_name' => 'Johan Reichel', 'rr_capacity' => 'Director', 'rr_place' => 'Southbroom', 'rr_date' => now()->toDateString(), 'sigR' => self::PNG],
            'initials' => 'JR', 'pages' => range(1, $total),
        ])->assertOk()->assertJsonPath('ok', true);

        $fresh = $doc->fresh(['signers', 'events']);
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->sealed_pdf_path);
        $this->assertTrue(Storage::disk('local')->exists($fresh->sealed_pdf_path));
        $pdf = Storage::disk('local')->get($fresh->sealed_pdf_path);
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(hash('sha256', $pdf), $fresh->document_hash);
        $this->assertGreaterThanOrEqual($total + 1, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $pdf), 'contract pages plus the signing record');
        $this->assertSame('done', $step->fresh()->status, 'signing ticks the agency timeline');
        $this->assertSame('contract_signed', $step->fresh()->completed_source);
        Mail::assertSent(AgreementSignedMail::class, fn ($m) => $m->hasTo('pat@caprivi.test'));
        Mail::assertSent(AgreementSignedMail::class, fn ($m) => $m->hasTo($owner->email));
        Mail::assertNotSent(SignedMail::class); // the attachment-carrying mail is never used for the agreement (spec §11.15)

        $events = $fresh->events->pluck('event')->all();
        foreach (['created', 'invited', 'saved', 'page_initialled', 'signed', 'countersigned', 'sealed', 'signed_copy_sent'] as $e) {
            $this->assertContains($e, $events, "audit event {$e}");
        }
        foreach ($fresh->events as $e) {
            $this->assertStringNotContainsString(self::ACCOUNT, (string) $e->detail);
        }
        $this->assertSame($total, \App\Models\PlatformEsign\Initial::where('signer_id', $fresh->signers[0]->id)->count());
        $this->assertSame($total, \App\Models\PlatformEsign\Initial::where('signer_id', $fresh->signers[1]->id)->count());

        // the recipient gets their completed copy on their own link; a stranger's link does not
        $this->get(route('platform-esign.agreement.download', $this->tokenOf($doc)))->assertOk();
        $this->get(route('platform-esign.agreement.download', $this->tokenOf($doc, 'r2')))->assertNotFound();
        $this->actingAs($owner)->get(route('platform-esign.documents.download', $doc->id))->assertOk();
        $agent = User::factory()->create(['role' => 'agent']);
        $this->actingAs($agent)->get(route('platform-esign.documents.download', $doc->id))->assertForbidden();
    }

    // ── Sensitive values ───────────────────────────────────────────────────

    public function test_owner_screens_mask_the_account_number_and_reveal_is_audited(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $this->save($this->tokenOf($doc), ['da_account' => self::ACCOUNT, 'm_account' => self::ACCOUNT, 'registered_name' => 'Caprivi'])->assertOk();

        $review = $this->actingAs($owner)->get(route('platform-esign.agreements.review', $doc->id))->assertOk();
        $review->assertDontSee(self::ACCOUNT);
        $review->assertSee('••••6789');

        $this->actingAs($owner)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'da_account'])->assertOk()->assertJsonPath('value', self::ACCOUNT);
        $this->actingAs($owner)->postJson(route('platform-esign.agreements.reveal', $doc->id), ['key' => 'registered_name'])->assertStatus(422);
        $ev = $doc->fresh()->events->firstWhere('event', 'bank_revealed');
        $this->assertNotNull($ev);
        $this->assertSame($owner->id, $ev->actor_user_id);
        $this->assertStringNotContainsString(self::ACCOUNT, (string) $ev->detail);
    }

    // ── Pinned version ─────────────────────────────────────────────────────

    public function test_a_sent_document_keeps_rendering_the_version_it_was_sent_with(): void
    {
        $owner = $this->owner();
        $doc = $this->send($owner);
        $v1 = $doc->wording;

        // a later published version with different wording and rates must not touch the sent document
        $content = $v1->content_json;
        $content['part_b'] = str_replace('B1. This agreement', 'B1. This amended agreement', $content['part_b']);
        $v2 = WordingVersion::create(['template_id' => $v1->template_id, 'version' => '1.1', 'version_date' => '2026-12-01', 'content_json' => $content,
            'rates_json' => array_merge($v1->rates_json, ['agency_base' => 1695]), 'is_published' => true, 'published_at' => now()]);

        $page = $this->get(route('platform-esign.agreement.show', $this->tokenOf($doc)))->assertOk();
        $page->assertSee('Version 1.0 — 28 September 2026')->assertSee('B1. This agreement')->assertSee('R1 495');
        $page->assertDontSee('This amended agreement')->assertDontSee('R1 695')->assertDontSee('Version 1.1');
        $this->assertSame($v1->id, $doc->fresh()->wording_version_id);
        $this->assertNotSame($v1->id, $v2->id);
    }

    public function test_the_generic_send_screen_does_not_offer_the_web_document(): void
    {
        $owner = $this->owner();
        app(AgreementContent::class)->ensureSeeded($owner->id);
        $this->actingAs($owner)->get(route('platform-esign.documents.create'))->assertOk()->assertDontSee('CoreX Subscription Agreement');
        $tpl = AgreementContent::template();
        $this->actingAs($owner)->post(route('platform-esign.documents.store'), ['template_id' => $tpl->id, 'expiry_days' => 14, 'signers' => [['role_key' => 'r1', 'name' => 'A', 'email' => 'a@b.test']]])->assertNotFound();
    }
}

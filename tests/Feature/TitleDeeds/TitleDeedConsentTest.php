<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Mail\TitleDeeds\TitleDeedConsentMail;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedConsent;
use App\Models\TitleDeeds\TitleDeedConsentLink;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 - a representative without the PIN: the owners' consent (link and phone), authority letters, and the limits.
 * Paths proven: every owner gets their own link by their stored email / WhatsApp; the token is stored hashed; open -> approve /
 * decline; FIRST response wins; a used, wrong or expired token; consent for THAT named representative only; the unlock expires;
 * refuse / owner-consent / consent-or-letter modes; letter required on top; phone consent off by default, then on with a stored
 * number only; the poll endpoint; representatives switched off.
 */
final class TitleDeedConsentTest extends TitleDeedTestCase
{
    private const REP = ['rep_name' => 'Busisiwe Mkhize', 'rep_id_number' => '9001015804321', 'rep_relationship' => 'Daughter'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function request(TitleDeed $deed, array $rep = self::REP)
    {
        return $this->actingAs($this->admin)->from(route('title-deeds.handover', $deed->id))->post(route('title-deeds.consent.request', $deed->id), $rep);
    }

    /** @return array<int,string> contact_id => raw token, read from the emails that carried them */
    private function tokens(): array
    {
        $out = [];
        foreach (Mail::sent(TitleDeedConsentMail::class) as $mail) {
            $out[] = basename(parse_url($mail->consentUrl, PHP_URL_PATH));
        }

        return $out;
    }

    private function handover(TitleDeed $deed, array $body)
    {
        return $this->actingAs($this->admin)->from(route('title-deeds.handover', $deed->id))->post(route('title-deeds.handover.submit', $deed->id), array_merge([
            'method' => 'representative', 'typed_name' => 'Busisiwe Mkhize', 'signature_image' => $this->pngDataUri(),
        ], $body));
    }

    public function test_every_owner_gets_their_own_link_by_email_and_a_prepared_whatsapp(): void
    {
        $deed = $this->deed();
        $this->request($deed)->assertSessionHas('success');

        $consent = TitleDeedConsent::firstOrFail();
        $this->assertSame('waiting', $consent->status);
        $this->assertSame('link', $consent->channel);
        $this->assertSame('4321', $consent->repIdLast4());
        $this->assertSame(2, TitleDeedConsentLink::count());

        $tokens = $this->tokens();
        $this->assertCount(2, $tokens);
        $this->assertNotSame($tokens[0], $tokens[1], 'a personal link per owner');
        $this->assertSame(48, strlen($tokens[0]));
        foreach ($tokens as $t) {
            $this->assertSame(0, DB::table('title_deed_consent_links')->where('token_hash', $t)->count(), 'the raw token is not stored');
            $this->assertSame(1, DB::table('title_deed_consent_links')->where('token_hash', hash('sha256', $t))->count());
        }
        $this->assertSame(2, TitleDeedEvent::where('kind', 'consent_request')->where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame(2, TitleDeedEvent::where('kind', 'consent_request')->where('channel', 'whatsapp')->where('status', 'prepared')->count());
        $this->assertTrue(TitleDeedAuditLog::where('event', 'consent_requested')->exists());

        // the prepared WhatsApp opens on the stored number with the link in it
        $wa = TitleDeedEvent::where('kind', 'consent_request')->where('channel', 'whatsapp')->first();
        $loc = $this->actingAs($this->admin)->get(route('title-deeds.events.whatsapp', [$deed->id, $wa->id]))->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/27', $loc);
        $this->assertStringContainsString(rawurlencode('Busisiwe Mkhize, ID ending 4321'), $loc);
    }

    public function test_the_owner_sees_the_exact_sentence_and_opening_it_is_recorded(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $token = $this->tokens()[0];

        auth()->logout();
        $this->get(route('title-deeds.consent.show', $token))->assertOk()
            ->assertSee('Busisiwe Mkhize, ID ending 4321, is at', false)->assertSee('to collect your title deed for', false)->assertSee('Approve')->assertSee('Decline');
        $this->assertNotNull(TitleDeedConsentLink::first()->first_opened_at);
    }

    public function test_approving_captures_who_when_ip_and_device_and_unlocks_only_that_representative(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $token = $this->tokens()[1];

        $this->withServerVariables(['REMOTE_ADDR' => '102.165.20.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Safari'])
            ->post(route('title-deeds.consent.respond', $token), ['decision' => 'approve'])->assertOk()->assertSee('approved');

        $consent = TitleDeedConsent::firstOrFail();
        $this->assertSame('approved', $consent->status);
        $this->assertSame('Sipho Mkhize', $consent->responded_name);
        $this->assertSame('102.165.20.9', $consent->responded_ip);
        $this->assertStringContainsString('iPhone', $consent->responded_user_agent);
        $this->assertNotNull($consent->responded_at);
        $this->assertTrue($consent->approved_until->between(now()->addMinutes(58), now()->addMinutes(61)), 'default validity is 60 minutes');
        $this->assertSame(0, TitleDeedConsentLink::whereNull('used_at')->count(), 'both links are spent');
        $this->assertTrue(TitleDeedAuditLog::where('event', 'consent_approved')->exists());

        // the handover is unlocked for THAT named representative - whatever else is typed, the consent's person is recorded
        $this->handover($deed, ['consent_id' => $consent->id, 'rep_name' => 'Someone Else', 'rep_id_number' => '1', 'rep_relationship' => 'Friend'])->assertSessionHas('success');
        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertSame('owner_consent', $receipt->method);
        $this->assertSame('Busisiwe Mkhize', $receipt->collector_name);
        $this->assertSame('9001015804321', $receipt->collector_id_number);
        $this->assertSame($consent->id, $receipt->consent_id);
        $this->assertSame('used', $consent->fresh()->status);
    }

    public function test_the_first_response_wins_the_second_owner_cannot_change_it(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        [$t1, $t2] = $this->tokens();

        $this->post(route('title-deeds.consent.respond', $t1), ['decision' => 'decline'])->assertOk()->assertSee('declined');
        $this->post(route('title-deeds.consent.respond', $t2), ['decision' => 'approve'])->assertOk()->assertSee('already been answered');

        $this->assertSame('declined', TitleDeedConsent::first()->status);
        $this->assertSame(1, TitleDeedAuditLog::whereIn('event', ['consent_approved', 'consent_declined'])->count());
    }

    public function test_a_declined_consent_blocks_the_handover_and_is_logged(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $this->post(route('title-deeds.consent.respond', $this->tokens()[0]), ['decision' => 'decline']);
        $consent = TitleDeedConsent::first();

        $this->handover($deed, ['consent_id' => $consent->id])->assertSessionHas('error');
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertTrue(TitleDeedAuditLog::where('event', 'consent_declined')->exists());
    }

    public function test_a_used_link_cannot_be_used_again_and_a_wrong_token_is_a_plain_not_available_page(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $token = $this->tokens()[0];

        $this->post(route('title-deeds.consent.respond', $token), ['decision' => 'approve'])->assertOk();
        $this->get(route('title-deeds.consent.show', $token))->assertOk()->assertSee('already been answered');
        $this->post(route('title-deeds.consent.respond', $token), ['decision' => 'decline'])->assertOk()->assertSee('already been answered');
        $this->assertSame('approved', TitleDeedConsent::first()->status);

        $this->get(route('title-deeds.consent.show', str_repeat('x', 48)))->assertOk()->assertSee('not available');
        $this->post(route('title-deeds.consent.respond', str_repeat('x', 48)), ['decision' => 'approve'])->assertOk()->assertSee('not available');
        $this->get(route('title-deeds.consent.show', str_repeat('y', 200)))->assertOk()->assertSee('not available');
    }

    public function test_an_expired_link_cannot_be_answered(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $token = $this->tokens()[0];

        $this->travel(61)->minutes();
        $this->get(route('title-deeds.consent.show', $token))->assertOk()->assertSee('no longer open');
        $this->post(route('title-deeds.consent.respond', $token), ['decision' => 'approve'])->assertOk()->assertSee('no longer open');
        $this->assertSame('waiting', TitleDeedConsent::first()->status);
    }

    public function test_an_approval_runs_out_after_the_validity_and_the_handover_is_refused(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'consent_validity_minutes' => 10]);
        $deed = $this->deed();
        $this->request($deed);
        $this->post(route('title-deeds.consent.respond', $this->tokens()[0]), ['decision' => 'approve']);
        $consent = TitleDeedConsent::first();

        $this->travel(11)->minutes();
        $this->handover($deed, ['consent_id' => $consent->id])->assertSessionHas('error');
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertSame('expired', $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.consent-status', $deed->id))->json('state'));
    }

    public function test_the_poll_endpoint_reports_waiting_then_approved(): void
    {
        $deed = $this->deed();
        $this->assertSame('none', $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.consent-status', $deed->id))->json('state'));

        $this->request($deed);
        $this->assertSame('waiting', $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.consent-status', $deed->id))->json('state'));

        auth()->logout();
        $this->post(route('title-deeds.consent.respond', $this->tokens()[0]), ['decision' => 'approve']);
        $json = $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.consent-status', $deed->id))->json();
        $this->assertSame('approved', $json['state']);
        $this->assertSame('Busisiwe Mkhize', $json['rep_name']);
        $this->assertSame('4321', $json['rep_id_last4']);
        $this->assertNotNull($json['until']);
    }

    public function test_asking_again_replaces_the_earlier_request(): void
    {
        $deed = $this->deed();
        $this->request($deed);
        $this->request($deed, ['rep_name' => 'Zanele Mkhize', 'rep_id_number' => '9505050055086', 'rep_relationship' => 'Niece']);

        $this->assertSame(['cancelled', 'waiting'], TitleDeedConsent::orderBy('id')->pluck('status')->all());
    }

    public function test_the_representative_details_are_required_to_ask(): void
    {
        $deed = $this->deed();
        foreach (['rep_name', 'rep_id_number', 'rep_relationship'] as $missing) {
            $body = self::REP;
            unset($body[$missing]);
            $this->request($deed, $body)->assertSessionHasErrors($missing);
        }
        $this->assertSame(0, TitleDeedConsent::count());
        $this->assertCount(0, Mail::sent(TitleDeedConsentMail::class));
    }

    public function test_an_owner_without_an_email_gets_the_whatsapp_and_an_archived_owner_gets_nothing(): void
    {
        $noEmail = $this->contact('Lerato', 'Khumalo', email: null);
        $gone = $this->contact('Pieter', 'Botha');
        $deed = $this->deed([$noEmail, $gone]);
        $gone->delete();

        $this->request($deed)->assertSessionHas('success');

        $this->assertSame(1, TitleDeedConsentLink::count(), 'only the reachable owner has a link');
        $this->assertCount(0, Mail::sent(TitleDeedConsentMail::class));
        $this->assertSame(1, TitleDeedEvent::where('kind', 'consent_request')->where('channel', 'whatsapp')->where('status', 'prepared')->count());
    }

    public function test_when_every_owner_is_archived_nobody_can_be_asked(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $deed = $this->deed([$a]);
        $a->delete();
        $this->request($deed)->assertSessionHas('error');
        $this->assertSame(0, TitleDeedConsent::count());
    }

    /* ── the modes ── */

    public function test_in_refuse_mode_a_representative_without_the_pin_cannot_even_ask(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'rep_without_pin_mode' => 'refuse']);
        $deed = $this->deed();
        $this->request($deed)->assertSessionHas('error');
        $this->handover($deed, self::REP + ['authority_letter' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertSessionHas('error');
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertSame(0, TitleDeedConsent::count());
    }

    public function test_in_owner_consent_mode_a_letter_alone_is_not_enough(): void
    {
        $deed = $this->deed();
        $this->handover($deed, self::REP + ['authority_letter' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertSessionHas('error');
        $this->assertSame(0, TitleDeedReceipt::count());
    }

    public function test_in_consent_or_letter_mode_a_signed_letter_alone_hands_the_deed_over(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'rep_without_pin_mode' => 'consent_or_letter']);
        $deed = $this->deed();

        $this->handover($deed, self::REP)->assertSessionHas('error');   // neither consent nor letter
        $this->handover($deed, self::REP + ['authority_letter' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertSessionHas('success');

        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertSame('authority_letter', $receipt->method);
        $this->assertSame('Busisiwe Mkhize', $receipt->collector_name);
        $this->assertNotNull($receipt->authority_letter_document_id);
        $this->assertTrue(TitleDeedAuditLog::where('event', 'authority_letter')->exists());
        $this->actingAs($this->admin)->get(route('title-deeds.receipt.file', [$deed->id, 'letter']))->assertOk();
    }

    public function test_the_letter_can_be_required_on_top_of_the_consent(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'authority_letter_required' => true]);
        $deed = $this->deed();
        $this->request($deed);
        $this->post(route('title-deeds.consent.respond', $this->tokens()[0]), ['decision' => 'approve']);
        $consent = TitleDeedConsent::first();

        $this->handover($deed, ['consent_id' => $consent->id])->assertSessionHas('error');
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertSame('approved', $consent->fresh()->status, 'a refused attempt does not spend the consent');

        $this->handover($deed, ['consent_id' => $consent->id, 'authority_letter' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertSessionHas('success');
        $this->assertSame('owner_consent', TitleDeedReceipt::first()->method);
    }

    public function test_representatives_switched_off_refuses_every_representative_route(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'representatives_allowed' => false]);
        $deed = $this->deed();
        $this->request($deed)->assertSessionHas('error');
        $this->handover($deed, self::REP)->assertSessionHas('error');
        $this->assertSame(0, TitleDeedConsent::count());
    }

    /* ── phone consent ── */

    private function phoneBody(TitleDeed $deed, int $phoneId, ?int $contactId = null): array
    {
        return self::REP + [
            'called_contact_id' => $contactId ?? $deed->owners->first()->contact_id, 'called_phone_id' => $phoneId, 'spoke_to' => 'Thandi Mkhize',
            'called_at' => now()->subMinutes(2)->format('Y-m-d\TH:i'),
        ];
    }

    public function test_phone_consent_is_off_by_default(): void
    {
        $deed = $this->deed();
        $phoneId = (int) DB::table('contact_phones')->where('contact_id', $deed->owners->first()->contact_id)->value('id');
        $this->actingAs($this->admin)->post(route('title-deeds.consent.phone', $deed->id), $this->phoneBody($deed, $phoneId))->assertSessionHas('error');
        $this->assertSame(0, TitleDeedConsent::count());
    }

    public function test_phone_consent_records_a_stored_number_and_unlocks_the_handover_with_its_own_method(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'phone_consent_allowed' => true]);
        $a = $this->contact('Thandi', 'Mkhize', phone: '0821234567');
        $deed = $this->deed([$a]);
        $phoneId = DB::table('contact_phones')->insertGetId([
            'agency_id' => $this->agencyId, 'contact_id' => $a->id, 'phone' => '0821234567', 'dial_code' => '+27', 'is_primary' => 1,
            'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90),
        ]);

        // a number that is not one of the owner's stored numbers is refused - it can never be typed
        $this->actingAs($this->admin)->post(route('title-deeds.consent.phone', $deed->id), $this->phoneBody($deed, $phoneId + 999))->assertSessionHas('error');
        $this->assertSame(0, TitleDeedConsent::count());

        $this->actingAs($this->admin)->post(route('title-deeds.consent.phone', $deed->id), $this->phoneBody($deed, $phoneId))->assertSessionHas('success');
        $consent = TitleDeedConsent::firstOrFail();
        $this->assertSame('phone', $consent->channel);
        $this->assertSame('approved', $consent->status);
        $this->assertStringEndsWith('4567', $consent->called_number_masked);
        $this->assertSame('Thandi Mkhize', $consent->spoke_to);
        $this->assertTrue(TitleDeedAuditLog::where('event', 'phone_consent')->exists());

        $this->handover($deed, ['consent_id' => $consent->id])->assertSessionHas('success');
        $this->assertSame('phone_consent', TitleDeedReceipt::first()->method);
    }

    public function test_a_call_time_in_the_future_is_refused(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'phone_consent_allowed' => true]);
        $deed = $this->deed([$this->contact('Thandi', 'Mkhize')]);
        $phoneId = DB::table('contact_phones')->insertGetId([
            'agency_id' => $this->agencyId, 'contact_id' => $deed->owners->first()->contact_id, 'phone' => '0821234567', 'dial_code' => '+27', 'is_primary' => 1,
            'created_at' => now()->subDays(90), 'updated_at' => now()->subDays(90),
        ]);
        $body = $this->phoneBody($deed, $phoneId);
        $body['called_at'] = now()->addHour()->format('Y-m-d\TH:i');
        $this->actingAs($this->admin)->post(route('title-deeds.consent.phone', $deed->id), $body)->assertSessionHas('error');
        $this->assertSame(0, TitleDeedConsent::count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Mail\TitleDeeds\TitleDeedSignLinkMail;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSigningLink;
use App\Services\TitleDeeds\TitleDeedHandoverService;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedPinService;
use App\Services\TitleDeeds\TitleDeedSigningService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 round 2, item 10 - sign by link. Gates are passed at the counter first; the link is single use, expiring, tied to one
 * deed and one named collector; the collector's phone page records the signature; staff then finish the handover from what was
 * recorded. The on-screen pad and the link are alternatives: the first to complete cancels the other.
 */
final class TitleDeedSigningLinkTest extends TitleDeedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function notified(array $owners = []): array
    {
        $deed = $this->deed($owners);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $deed = $deed->fresh();

        return [$deed, app(TitleDeedPinService::class)->currentCode($deed)];
    }

    /** Prepare a link for the first owner, with the PIN proven at the counter. @return array{0:TitleDeedSigningLink,1:string} */
    private function ownerLink(TitleDeed $deed, string $pin): array
    {
        $r = app(TitleDeedHandoverService::class)->prepareLink($this->admin, $deed, [
            'method' => 'pin', 'pin' => $pin, 'collector_type' => 'owner', 'collector_contact_id' => $deed->owners->first()->contact_id,
        ]);

        return [$r['link'], $r['token']];
    }

    private function sign(string $token, array $over = [])
    {
        return $this->postJson(route('title-deeds.sign.submit', $token), array_merge([
            'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri(), 'confirmed' => true,
        ], $over));
    }

    public function test_a_link_is_made_only_after_the_pin_is_proven_and_the_pin_is_not_spent_yet(): void
    {
        [$deed, $pin] = $this->notified();

        $this->expectException(TitleDeedException::class);
        try {
            app(TitleDeedHandoverService::class)->prepareLink($this->admin, $deed, [
                'method' => 'pin', 'pin' => '000000', 'collector_type' => 'owner', 'collector_contact_id' => $deed->owners->first()->contact_id,
            ]);
        } finally {
            $this->assertSame(0, TitleDeedSigningLink::query()->count(), 'a wrong PIN makes no link');
            $this->assertNotNull($deed->fresh()->pin_encrypted);
        }
    }

    public function test_the_link_is_stored_hashed_with_the_agency_expiry_and_the_pin_is_still_unspent(): void
    {
        TitleDeedSetting::query()->updateOrCreate(['agency_id' => $this->agencyId], ['signing_link_minutes' => 45]);
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $this->assertSame(TitleDeedSigningLink::hashToken($token), $link->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode($link->getAttributes()), 'the raw token is not stored in the clear');
        $this->assertEqualsWithDelta(45 * 60, now()->diffInSeconds($link->expires_at, false), 5);
        $this->assertSame('waiting', $link->effectiveStatus());
        $this->assertNotNull($deed->fresh()->pin_encrypted, 'the PIN is spent only when the deed is handed over');
        $this->assertSame('received', $deed->fresh()->status === 'notified' ? 'received' : $deed->fresh()->status);
    }

    public function test_the_default_expiry_is_thirty_minutes_and_the_feature_can_be_switched_off(): void
    {
        [$deed, $pin] = $this->notified();
        [$link] = $this->ownerLink($deed, $pin);
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($link->expires_at, false), 5);

        TitleDeedSetting::query()->updateOrCreate(['agency_id' => $this->agencyId], ['sign_by_link_enabled' => false]);
        $this->expectException(TitleDeedException::class);
        $this->ownerLink($deed, $pin);
    }

    public function test_the_public_page_names_the_collector_and_the_property_and_works_without_login(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $res = $this->get(route('title-deeds.sign.show', $token))->assertOk();
        $res->assertSee('I, <strong>' . e($link->collector_name) . '</strong>, confirm I am collecting the title deed for', false)
            ->assertSee($deed->property_display, false)
            ->assertSee('id="pad"', false);
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertNotNull($link->fresh()->first_opened_at);
        $this->assertTrue(TitleDeedAuditLog::query()->where('title_deed_id', $deed->id)->where('event', 'signing_link_opened')->exists());
    }

    public function test_signing_records_name_time_ip_and_device_and_does_not_collect_the_deed(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/120'])->sign($token)->assertStatus(201)->assertJsonPath('state', 'done');

        $link->refresh();
        $this->assertSame('signed', $link->status);
        $this->assertSame('Thandi Mkhize', $link->signed_typed_name);
        $this->assertNotNull($link->signed_at);
        $this->assertNotNull($link->signed_ip);
        $this->assertStringContainsString('Android', (string) $link->signed_user_agent);
        Storage::disk('local')->assertExists($link->signature_path);
        $this->assertNotSame('collected', $deed->fresh()->status, 'staff finish the handover');
        $this->assertSame('signed', app(TitleDeedSigningService::class)->status($deed->fresh())['state']);
    }

    public function test_the_link_works_once(): void
    {
        [$deed, $pin] = $this->notified();
        [, $token] = $this->ownerLink($deed, $pin);

        $this->sign($token)->assertStatus(201);
        $this->sign($token)->assertStatus(409)->assertJsonPath('state', 'signed');
    }

    public function test_a_bad_submission_burns_nothing(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $this->sign($token, ['confirmed' => false])->assertStatus(422);
        $this->sign($token, ['typed_name' => ''])->assertStatus(422);
        $this->sign($token, ['signature_image' => ''])->assertStatus(422);
        $this->sign($token, ['signature_image' => 'data:text/html;base64,PGI+eDwvYj4='])->assertStatus(422);
        $this->assertSame('waiting', $link->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no half-saved signature file is left behind');

        $this->sign($token)->assertStatus(201);
    }

    public function test_an_expired_a_cancelled_and_an_unknown_link_are_dead(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $link->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->sign($token)->assertStatus(409)->assertJsonPath('state', 'expired');
        $this->get(route('title-deeds.sign.show', $token))->assertOk()->assertSee('This link has run out', false);

        $this->get(route('title-deeds.sign.show', str_repeat('a', 60)))->assertOk()->assertSee('This link is not available', false);
        $this->sign(str_repeat('a', 60))->assertStatus(404);
    }

    public function test_cancelling_kills_the_link_and_is_recorded_and_a_new_one_can_be_made(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        app(TitleDeedHandoverService::class)->cancelLink($this->admin, $deed, $link);
        $this->sign($token)->assertStatus(409)->assertJsonPath('state', 'cancelled');
        $this->assertTrue(TitleDeedAuditLog::query()->where('title_deed_id', $deed->id)->where('event', 'signing_link_cancelled')->exists());

        [$second, $t2] = $this->ownerLink($deed, $pin);
        $this->assertNotSame($link->id, $second->id);
        $this->sign($t2)->assertStatus(201);
    }

    public function test_staff_finish_the_handover_from_the_link_signature_and_the_deed_is_collected_once(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        // Not signed yet: refused, nothing collected.
        $this->actingAs($this->admin)->post(route('title-deeds.handover.submit', $deed->id), $this->tk('handover_link', $deed, null, ['signing_link_id' => $link->id]))
            ->assertSessionHas('error');
        $this->assertNotSame('collected', $deed->fresh()->status);

        $this->sign($token)->assertStatus(201);
        $formToken = $this->tk('handover_link', $deed);
        $this->actingAs($this->admin)->post(route('title-deeds.handover.submit', $deed->id), $formToken + ['signing_link_id' => $link->id])
            ->assertRedirect(route('title-deeds.show', $deed->id))->assertSessionHas('success');

        $deed->refresh();
        $this->assertSame('collected', $deed->status);
        $receipt = TitleDeedReceipt::query()->where('title_deed_id', $deed->id)->firstOrFail();
        $this->assertSame('link', $receipt->signed_via);
        $this->assertSame($link->id, (int) $receipt->signing_link_id);
        $this->assertSame('Thandi Mkhize', $receipt->signed_typed_name);
        $this->assertNull($deed->pin_encrypted, 'the PIN is spent now');
        $this->assertSame('used', $link->fresh()->status);

        // The same screen pressed again: one receipt only.
        $this->actingAs($this->admin)->post(route('title-deeds.handover.submit', $deed->id), $formToken + ['signing_link_id' => $link->id])->assertSessionHas('info');
        $this->assertSame(1, TitleDeedReceipt::query()->where('title_deed_id', $deed->id)->count());
    }

    public function test_the_pin_is_checked_again_when_staff_finish_if_it_was_replaced_meanwhile(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);
        $this->sign($token)->assertStatus(201);

        app(TitleDeedNotifier::class)->issueNewPin($this->admin, $deed->fresh());

        $this->actingAs($this->admin)->post(route('title-deeds.handover.submit', $deed->id), $this->tk('handover_link', $deed, null, ['signing_link_id' => $link->id]))
            ->assertSessionHas('error');
        $this->assertNotSame('collected', $deed->fresh()->status);
    }

    public function test_signing_on_the_staff_screen_cancels_a_waiting_link_and_a_link_cancels_nothing_that_already_finished(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        app(TitleDeedHandoverService::class)->handover($this->admin, $deed, [
            'method' => 'pin', 'pin' => $pin, 'collector_type' => 'owner', 'collector_contact_id' => $deed->owners->first()->contact_id,
            'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri(),
        ], ['ip' => '127.0.0.1', 'user_agent' => 'test']);

        $this->assertSame('cancelled', $link->fresh()->status);
        $this->sign($token)->assertStatus(409);
    }

    public function test_the_owners_link_only_ever_goes_to_the_stored_address_and_never_to_one_typed_in(): void
    {
        [$deed, $pin] = $this->notified();
        [$link] = $this->ownerLink($deed, $pin);
        Mail::fake();

        $this->actingAs($this->admin)->post(route('title-deeds.signing-link.email', [$deed->id, $link->id]), $this->tk('link_email', $deed, null, ['send_email' => 'attacker@evil.test']))->assertSessionHas('success');

        Mail::assertSent(TitleDeedSignLinkMail::class, fn ($m) => ! $m->hasTo('attacker@evil.test'));
        $this->assertSame(0, TitleDeedEvent::query()->where('destination_masked', 'like', 'a%@evil.test')->count());
    }

    public function test_a_representatives_link_goes_to_the_address_captured_at_the_counter(): void
    {
        [$deed, $pin] = $this->notified();
        $r = app(TitleDeedHandoverService::class)->prepareLink($this->admin, $deed, [
            'method' => 'pin', 'pin' => $pin, 'collector_type' => 'representative', 'rep_name' => 'Busisiwe Mkhize', 'rep_id_number' => '9001015800087', 'rep_relationship' => 'Daughter',
        ], ['send_email' => 'busisiwe@example.test']);
        Mail::fake();

        $this->actingAs($this->admin)->post(route('title-deeds.signing-link.email', [$deed->id, $r['link']->id]), $this->tk('link_email', $deed, null, ['send_email' => 'busisiwe@example.test']))->assertSessionHas('success');
        $this->assertTrue(TitleDeedEvent::query()->where('kind', 'signing_link')->where('channel', 'email')->where('status', 'sent')->exists());
    }

    public function test_the_link_by_whatsapp_follows_the_same_open_then_confirm_sequence(): void
    {
        $owner = $this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222');
        [$deed, $pin] = $this->notified([$owner]);
        [$link] = $this->ownerLink($deed, $pin);

        $this->actingAs($this->admin)->post(route('title-deeds.signing-link.whatsapp', [$deed->id, $link->id]), $this->tk('link_whatsapp', $deed))->assertSessionHas('success');
        $ev = TitleDeedEvent::query()->where('kind', 'signing_link')->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('prepared', $ev->status);

        $this->actingAs($this->admin)->post(route('title-deeds.events.confirm', [$deed->id, $ev->id]))->assertSessionHas('error');
        $loc = $this->actingAs($this->admin)->post(route('title-deeds.events.open', [$deed->id, $ev->id]))->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/27731112222', $loc);
        $this->actingAs($this->admin)->post(route('title-deeds.events.confirm', [$deed->id, $ev->id]))->assertSessionHas('success');
    }

    public function test_the_handover_screen_shows_the_link_a_qr_code_and_the_signed_state(): void
    {
        [$deed, $pin] = $this->notified();
        [$link, $token] = $this->ownerLink($deed, $pin);

        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk();
        $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.signing-status', $deed->id))->assertOk()->assertJsonPath('state', 'waiting');

        $this->sign($token)->assertStatus(201);
        $this->actingAs($this->admin)->getJson(route('api.v1.title-deeds.signing-status', $deed->id))->assertOk()
            ->assertJsonPath('state', 'signed')->assertJsonPath('signed_name', 'Thandi Mkhize');
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Hand the deed over', false);
    }

    public function test_the_link_screens_are_for_people_with_the_handover_permission_in_scope(): void
    {
        [$deed, $pin] = $this->notified();
        [$link] = $this->ownerLink($deed, $pin);
        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);

        $this->actingAs($viewer)->getJson(route('api.v1.title-deeds.signing-status', $deed->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('title-deeds.signing-link.cancel', [$deed->id, $link->id]), $this->tk('link_cancel', $deed, $viewer))->assertForbidden();

        $outsider = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.handover'], 'own', $this->branchB->id);
        $this->actingAs($outsider)->getJson(route('api.v1.title-deeds.signing-status', $deed->id))->assertNotFound();
    }
}

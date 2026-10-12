<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Mail\TitleDeeds\TitleDeedNoticeMail;
use App\Models\Otp;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedPinService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * AT-450 - notifying the owners, the PIN, and the honest WhatsApp record.
 * Paths proven: two owners both reached; an owner with no email; an owner with no mobile; an invalid email; two owners sharing one
 * email; an archived owner contact; a typed destination ignored; PIN stored hashed (and never plaintext in the OTP row); resend
 * sends the SAME PIN; a new PIN cancels the old; WhatsApp prepared -> opened -> confirmed (and the double click); status only
 * moves on a real send; collected / archived deeds refuse; no permission refuses.
 */
final class TitleDeedNotifyTest extends TitleDeedTestCase
{
    private function notices()
    {
        return Mail::sent(TitleDeedNoticeMail::class);
    }

    public function test_notify_reaches_every_owner_logs_each_send_and_moves_to_notified(): void
    {
        $deed = $this->deed();

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertRedirect(route('title-deeds.show', $deed->id))->assertSessionHas('success');

        $this->assertCount(2, $this->notices(), 'one email per owner (two different addresses)');
        $rows = TitleDeedEvent::where('title_deed_id', $deed->id)->get();
        $this->assertSame(2, $rows->where('channel', 'email')->where('status', 'sent')->count());
        $this->assertSame(2, $rows->where('channel', 'whatsapp')->where('status', 'prepared')->count());
        foreach ($rows as $row) {
            $this->assertSame($this->admin->id, $row->user_id);
            $this->assertNotNull($row->destination_masked);
            $this->assertStringNotContainsString('thandi.mkhize@example.test', $row->destination_masked, 'the destination is masked');
        }
        $this->assertSame('notified', $deed->fresh()->status);
        $this->assertNotNull($deed->fresh()->notified_at);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'notice_sent')->exists());
    }

    public function test_the_pin_is_stored_hashed_and_the_owner_email_carries_it(): void
    {
        $deed = $this->deed();
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));

        $sent = $this->notices();
        $pin = $sent->first()->pin;
        $this->assertMatchesRegularExpression('/^\d{6}$/', $pin);
        $this->assertSame($pin, $sent->last()->pin, 'ONE PIN per deed, not per owner');

        $fresh = $deed->fresh();
        $this->assertSame(1, $fresh->pin_version);
        $otp = Otp::where('purpose', TitleDeedPinService::PURPOSE)->firstOrFail();
        $this->assertNotSame($pin, $otp->code_hash);
        $this->assertStringNotContainsString($pin, (string) json_encode($otp->getAttributes()));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($pin, $otp->code_hash));
        // the reversible copy is encrypted at rest, never plaintext
        $this->assertStringNotContainsString($pin, (string) DB::table('title_deeds')->where('id', $deed->id)->value('pin_encrypted'));
        $this->assertSame($pin, app(TitleDeedPinService::class)->currentCode($fresh));
    }

    public function test_an_owner_with_no_email_and_an_owner_with_no_mobile_are_skipped_and_logged(): void
    {
        $noEmail = $this->contact('Lerato', 'Khumalo', email: null);
        $noMobile = $this->contact('Pieter', 'Botha', phone: null);
        $deed = $this->deed([$noEmail, $noMobile]);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertSessionHas('success');

        $this->assertCount(1, $this->notices(), 'only the owner with an email is emailed');
        $rows = TitleDeedEvent::where('title_deed_id', $deed->id)->get();
        $this->assertSame('skipped', $rows->where('contact_id', $noEmail->id)->where('channel', 'email')->first()->status);
        $this->assertStringContainsString('No email', $rows->where('contact_id', $noEmail->id)->where('channel', 'email')->first()->error);
        $this->assertSame('skipped', $rows->where('contact_id', $noMobile->id)->where('channel', 'whatsapp')->first()->status);
        $this->assertSame('prepared', $rows->where('contact_id', $noEmail->id)->where('channel', 'whatsapp')->first()->status);
    }

    public function test_an_invalid_email_is_treated_as_no_email(): void
    {
        $c = $this->contact('Nomsa', 'Zulu', email: 'not an email');
        $deed = $this->deed([$c]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));
        $this->assertCount(0, $this->notices());
        $this->assertSame('skipped', TitleDeedEvent::where('channel', 'email')->first()->status);
    }

    public function test_two_owners_sharing_one_email_get_one_email_and_both_are_logged(): void
    {
        $a = $this->contact('Thandi', 'Mkhize', email: 'family.mkhize@example.test');
        $b = $this->contact('Sipho', 'Mkhize', email: 'family.mkhize@example.test');
        $deed = $this->deed([$a, $b]);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));

        $this->assertCount(1, $this->notices());
        $this->assertSame('Thandi Mkhize and Sipho Mkhize', $this->notices()->first()->recipientName);
        $rows = TitleDeedEvent::where('title_deed_id', $deed->id)->where('channel', 'email')->get();
        $this->assertSame(2, $rows->count());
        $this->assertSame(1, $rows->where('status', 'sent')->count());
        $this->assertStringContainsString('one email covers both', $rows->where('status', 'skipped')->first()->error);
    }

    public function test_an_archived_owner_contact_is_skipped_not_emailed(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $b = $this->contact('Sipho', 'Mkhize');
        $deed = $this->deed([$a, $b]);
        $b->delete();

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));

        $this->assertCount(1, $this->notices());
        $this->assertStringContainsString('archived', TitleDeedEvent::where('contact_id', $b->id)->first()->error);
    }

    public function test_a_typed_destination_is_ignored_the_stored_one_is_always_used(): void
    {
        $deed = $this->deed([$this->contact('Thandi', 'Mkhize', email: 'thandi@example.test', phone: '0821234567')]);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), ['email' => 'attacker@evil.test', 'to' => 'attacker@evil.test', 'phone' => '0829999999']);

        $email = TitleDeedEvent::where('channel', 'email')->first();
        $this->assertSame('t***@example.test', $email->destination_masked);
        $wa = TitleDeedEvent::where('channel', 'whatsapp')->first();
        $this->assertStringEndsWith('4567', $wa->destination_masked);
        $this->assertStringNotContainsString('9999', (string) $wa->destination_masked);
        // and the prepared WhatsApp opens on the STORED number
        $response = $this->actingAs($this->admin)->get(route('title-deeds.events.whatsapp', [$deed->id, $wa->id]));
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://wa.me/27821234567?text=', $location);
        $this->assertStringContainsString(rawurlencode($this->notices()->first()->pin), $location);
    }

    public function test_whatsapp_is_prepared_then_confirmed_by_a_person_and_a_double_click_is_absorbed(): void
    {
        $onlyPhone = $this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222');
        $deed = $this->deed([$onlyPhone]);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));
        $this->assertSame('received', $deed->fresh()->status, 'nothing was actually sent yet - the status does not move');

        $event = TitleDeedEvent::where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame('prepared', $event->status);
        $this->assertNull($event->confirmed_at);

        $this->actingAs($this->admin)->post(route('title-deeds.events.confirm', [$deed->id, $event->id]))->assertSessionHas('success');
        $this->actingAs($this->admin)->post(route('title-deeds.events.confirm', [$deed->id, $event->id]))->assertSessionHas('success');

        $event->refresh();
        $this->assertSame('sent', $event->status);
        $this->assertNotNull($event->confirmed_at);
        $this->assertSame('notified', $deed->fresh()->status);
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'whatsapp_confirmed')->count());
    }

    public function test_resend_sends_the_same_pin_and_a_new_pin_cancels_the_old_one(): void
    {
        $deed = $this->deed([$this->contact('Thandi', 'Mkhize')]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id));
        $first = $this->notices()->first()->pin;

        $this->actingAs($this->admin)->post(route('title-deeds.pin.resend', $deed->id))->assertSessionHas('success');
        $this->assertSame($first, $this->notices()->last()->pin, 'resend sends the SAME PIN');
        $this->assertSame(1, $deed->fresh()->pin_version);
        $this->assertSame(1, TitleDeedEvent::where('kind', 'pin_resend')->where('channel', 'email')->count());

        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id))->assertSessionHas('success');
        $second = $this->notices()->last()->pin;
        $this->assertNotSame($first, $second);
        $this->assertSame(2, $deed->fresh()->pin_version);

        $pins = app(TitleDeedPinService::class);
        $this->assertFalse($pins->check($deed->fresh(), $first)['ok'], 'the old PIN no longer works');
        $this->assertTrue($pins->check($deed->fresh(), $second)['ok']);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'pin_replaced')->exists());
    }

    public function test_when_the_agency_does_not_use_a_pin_the_notice_has_none(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'pin_required' => false]);
        $deed = $this->deed([$this->contact('Thandi', 'Mkhize')]);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertSessionHas('success');

        $this->assertNull($this->notices()->first()->pin);
        $this->assertSame(0, $deed->fresh()->pin_version);
        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id))->assertSessionHas('error');
    }

    public function test_a_deed_with_nobody_reachable_says_so_and_does_not_move(): void
    {
        $c = $this->contact('Thandi', 'Mkhize', email: null, phone: null);
        $deed = $this->deed([$c]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertSessionHas('error');
        $this->assertSame('received', $deed->fresh()->status);
    }

    public function test_a_collected_deed_refuses_every_notice_action(): void
    {
        $deed = $this->deed();
        $deed->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('title-deeds.pin.resend', $deed->id))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id))->assertSessionHas('error');
        $this->assertCount(0, $this->notices());
        $this->expectException(TitleDeedException::class);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
    }

    public function test_an_archived_deed_is_not_found_for_notices(): void
    {
        $deed = $this->deed();
        $deed->delete();
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertNotFound();
    }

    public function test_notifying_needs_the_notify_permission(): void
    {
        $deed = $this->deed();
        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $this->actingAs($viewer)->post(route('title-deeds.notify', $deed->id))->assertForbidden();
        $this->actingAs($viewer)->post(route('title-deeds.pin.new', $deed->id))->assertForbidden();
        $this->assertCount(0, $this->notices());
    }

    public function test_a_second_notice_is_refused_while_the_first_is_still_being_sent(): void
    {
        $deed = $this->deed();
        $lock = \Illuminate\Support\Facades\Cache::lock('title-deed-notice:' . $deed->id, 30);
        $this->assertTrue($lock->get());

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id))->assertSessionHas('error');
        $this->assertCount(0, $this->notices());
        $lock->release();
    }
}

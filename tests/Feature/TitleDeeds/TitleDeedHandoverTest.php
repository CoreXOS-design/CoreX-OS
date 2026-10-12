<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Mail\TitleDeeds\TitleDeedCollectedMail;
use App\Models\Contact;
use App\Models\Document;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Services\TitleDeeds\TitleDeedHandoverService;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedPinService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 - the handover. Paths proven: PIN + owner; PIN + representative (and the three required fields); wrong PIN counted and
 * logged, lock at N, a new PIN clears the lock; ID check; signature required / not required; a bad signature or a missing
 * photo burns nothing; double submit and an already-collected deed; an archived deed; the override gate (no permission, no
 * reason, reason); the collector ID photo; everything after collection (agent told, every owner emailed, PIN cleared).
 */
final class TitleDeedHandoverTest extends TitleDeedTestCase
{
    private function notified(array $owners = []): array
    {
        $deed = $this->deed($owners);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $deed = $deed->fresh();

        return [$deed, app(TitleDeedPinService::class)->currentCode($deed)];
    }

    private function submit(TitleDeed $deed, array $body, $user = null, ?string $token = null)
    {
        $tk = $token !== null ? ['_form_token' => $token] : $this->tk('handover', $deed, $user);

        return $this->actingAs($user ?? $this->admin)->from(route('title-deeds.handover', $deed->id))->post(route('title-deeds.handover.submit', $deed->id), $tk + $body);
    }

    private function ownerBody(TitleDeed $deed, string $pin, array $extra = []): array
    {
        return array_merge([
            'method' => 'pin', 'pin' => $pin, 'collector_type' => 'owner', 'collector_contact_id' => $deed->owners->first()->contact_id,
            'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri(),
        ], $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_the_owner_collects_with_the_pin_and_signs(): void
    {
        [$deed, $pin] = $this->notified();

        $this->submit($deed, $this->ownerBody($deed, $pin))->assertRedirect(route('title-deeds.show', $deed->id))->assertSessionHas('success');

        $deed->refresh();
        $this->assertSame('collected', $deed->status);
        $this->assertSame('Thandi Mkhize', $deed->collected_by_name);
        $this->assertSame('pin', $deed->collected_method);
        $this->assertNotNull($deed->collected_at);
        $this->assertNull($deed->pin_encrypted, 'nothing left to resend once collected');

        $receipt = TitleDeedReceipt::where('title_deed_id', $deed->id)->firstOrFail();
        $this->assertSame('owner', $receipt->collector_type);
        $this->assertSame('Thandi Mkhize', $receipt->signed_typed_name);
        $this->assertNotNull($receipt->signed_at);
        $this->assertNotNull($receipt->signed_ip);
        $this->assertSame($this->admin->id, $receipt->recorded_by_user_id);
        Storage::disk('local')->assertExists($receipt->signature_path);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'handover')->exists());
    }

    public function test_after_collection_the_agent_is_told_and_every_owner_is_emailed(): void
    {
        [$deed, $pin] = $this->notified();
        Mail::fake();   // forget the notice emails

        $this->submit($deed, $this->ownerBody($deed, $pin));

        Mail::assertSent(TitleDeedCollectedMail::class, 2);
        $mail = Mail::sent(TitleDeedCollectedMail::class)->first();
        $this->assertSame('Thandi Mkhize', $mail->collectorName);
        $this->assertSame('PIN', $mail->methodLabel);
        $note = $this->agent->notifications()->get()->first(fn ($n) => str_contains((string) json_encode($n->data), 'Title deed collected'));
        $this->assertNotNull($note, 'the responsible agent has an in-app notification');
        $this->assertSame(2, DB::table('title_deed_events')->where('kind', 'collected_notice')->where('status', 'sent')->count());
    }

    public function test_the_deals_agents_are_told_on_collection_and_the_listing_agent_follows_the_agency_setting(): void
    {
        $mk = fn (string $name) => \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => $name]);
        $listing = $mk('Listing One');
        $extra = $mk('Selling Two');
        $told = fn ($u) => $u->notifications()->get()->contains(fn ($n) => str_contains((string) json_encode($n->data), 'Title deed collected'));

        [$deed, $pin] = $this->notified();
        $deed->forceFill(['listing_agent_id' => $listing->id, 'additional_agent_ids' => [$extra->id]])->save();
        $this->submit($deed, $this->ownerBody($deed, $pin))->assertSessionHas('success');
        $this->assertTrue($told($this->agent), 'the responsible agent');
        $this->assertTrue($told($extra), 'the deal\'s other selling agent');
        $this->assertTrue($told($listing), 'the listing agent - default on');

        // Agency turns the listing agent off: a second deed does not tell them again.
        $before = $listing->notifications()->count();
        TitleDeedSetting::query()->updateOrCreate(['agency_id' => $this->agencyId], ['notify_listing_agent' => false]);
        [$deed2, $pin2] = $this->notified([$this->contact('Sipho', 'Dube')]);
        $deed2->forceFill(['listing_agent_id' => $listing->id])->save();
        $this->submit($deed2, $this->ownerBody($deed2, $pin2, ['typed_name' => 'Sipho Dube']))->assertSessionHas('success');
        $this->assertSame($before, $listing->notifications()->count());
    }

    public function test_a_wrong_pin_is_counted_and_logged_and_nothing_is_collected(): void
    {
        [$deed, $pin] = $this->notified();
        $wrong = $pin === '000000' ? '111111' : '000000';

        $this->submit($deed, $this->ownerBody($deed, $wrong))->assertSessionHas('error');

        $deed->refresh();
        $this->assertSame('notified', $deed->status);
        $this->assertSame(1, $deed->pin_failed_attempts, 'the miss was committed, not rolled back');
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'pin_wrong')->count());

        // and the right PIN still works afterwards
        $this->submit($deed, $this->ownerBody($deed, $pin))->assertSessionHas('success');
        $this->assertSame('collected', $deed->fresh()->status);
    }

    public function test_the_pin_locks_after_the_agency_limit_and_a_new_pin_unlocks_it(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'pin_lock_attempts' => 3]);
        [$deed, $pin] = $this->notified();
        $wrong = $pin === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 3; $i++) {
            $this->submit($deed, $this->ownerBody($deed, $wrong));
        }
        $deed->refresh();
        $this->assertNotNull($deed->pin_locked_at);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'pin_locked')->exists());

        // even the CORRECT pin is refused while locked
        $this->submit($deed, $this->ownerBody($deed, $pin))->assertSessionHas('error');
        $this->assertSame('notified', $deed->fresh()->status);

        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id), $this->tk('pin_new', $deed));
        $deed->refresh();
        $this->assertNull($deed->pin_locked_at);
        $this->assertSame(0, $deed->pin_failed_attempts);
        $newPin = app(TitleDeedPinService::class)->currentCode($deed);
        $this->submit($deed, $this->ownerBody($deed, $newPin))->assertSessionHas('success');
    }

    public function test_a_representative_with_the_pin_needs_name_id_and_relationship(): void
    {
        [$deed, $pin] = $this->notified();
        $rep = ['collector_type' => 'representative', 'rep_name' => 'Busisiwe Mkhize', 'rep_id_number' => '9001015800087', 'rep_relationship' => 'Daughter', 'typed_name' => 'Busisiwe Mkhize'];

        foreach (['rep_name', 'rep_id_number', 'rep_relationship'] as $missing) {
            $body = $this->ownerBody($deed, $pin, $rep);
            unset($body[$missing]);
            $this->submit($deed, $body)->assertSessionHas('error');
        }
        $this->assertSame('notified', $deed->fresh()->status);
        $this->assertSame(0, $deed->fresh()->pin_failed_attempts, 'a refusal for missing fields does not touch the PIN');

        $this->submit($deed, $this->ownerBody($deed, $pin, $rep))->assertSessionHas('success');
        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertSame('representative', $receipt->collector_type);
        $this->assertSame('Busisiwe Mkhize', $receipt->collector_name);
        $this->assertSame('9001015800087', $receipt->collector_id_number);
        $this->assertSame('Daughter', $receipt->collector_relationship);
    }

    public function test_representatives_can_be_switched_off_for_the_agency(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'representatives_allowed' => false]);
        [$deed, $pin] = $this->notified();

        $this->submit($deed, $this->ownerBody($deed, $pin, ['collector_type' => 'representative', 'rep_name' => 'Busisiwe Mkhize', 'rep_id_number' => '9001015800087', 'rep_relationship' => 'Daughter']))
            ->assertSessionHas('error');
        $this->assertSame('notified', $deed->fresh()->status);
    }

    public function test_an_owner_in_person_without_the_pin_is_released_on_an_id_check(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        $a->forceFill(['id_number' => '7001015800084'])->save();
        [$deed] = $this->notified([$a, $this->contact('Sipho', 'Mkhize')]);

        $this->submit($deed, ['method' => 'id_check', 'collector_contact_id' => $a->id, 'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri()])
            ->assertSessionHas('error');   // the ID number seen is required
        $this->assertSame('notified', $deed->fresh()->status);

        $this->submit($deed, ['method' => 'id_check', 'collector_contact_id' => $a->id, 'id_number_seen' => '7001015800084', 'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri()])
            ->assertSessionHas('success');

        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertSame('id_check', $receipt->method);
        $this->assertSame('7001015800084', $receipt->id_number_seen);
        $this->assertStringContainsString('matches', TitleDeedAuditLog::where('event', 'handover')->first()->summary);
        $this->assertSame('ID check', TitleDeed::METHOD_LABELS[$deed->fresh()->collected_method]);
    }

    public function test_a_collector_that_is_not_an_owner_of_the_deed_is_refused(): void
    {
        [$deed, $pin] = $this->notified();
        $stranger = $this->contact('Piet', 'Pompies');
        $this->submit($deed, $this->ownerBody($deed, $pin, ['collector_contact_id' => $stranger->id]))->assertSessionHas('error');
        $this->assertSame('notified', $deed->fresh()->status);
    }

    public function test_the_signature_is_required_by_default_and_refusing_it_does_not_burn_the_pin(): void
    {
        [$deed, $pin] = $this->notified();
        $body = $this->ownerBody($deed, $pin);

        unset($body['signature_image']);
        $this->submit($deed, $body)->assertSessionHas('error');
        $body = $this->ownerBody($deed, $pin);
        $body['typed_name'] = '';
        $this->submit($deed, $body)->assertSessionHas('error');

        $deed->refresh();
        $this->assertSame('notified', $deed->status);
        $this->assertSame(0, $deed->pin_failed_attempts);
        $this->assertSame(0, \App\Models\Otp::where('purpose', TitleDeedPinService::PURPOSE)->whereNotNull('used_at')->count(), 'the PIN was not consumed');
    }

    public function test_a_garbage_signature_rolls_everything_back_including_the_pin(): void
    {
        [$deed, $pin] = $this->notified();

        $this->submit($deed, $this->ownerBody($deed, $pin, ['signature_image' => 'data:image/png;base64,AAAA']))->assertSessionHas('error');

        $this->assertSame('notified', $deed->fresh()->status);
        $this->assertSame(0, TitleDeedReceipt::count());
        $this->assertSame(0, \App\Models\Otp::where('purpose', TitleDeedPinService::PURPOSE)->whereNotNull('used_at')->count());
        $this->assertSame([], Storage::disk('local')->allFiles('title-deed-signatures'));
        // and the real PIN still works
        $this->submit($deed, $this->ownerBody($deed, $pin))->assertSessionHas('success');
    }

    public function test_the_signature_can_be_switched_off(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'signature_required' => false]);
        [$deed, $pin] = $this->notified();
        $body = $this->ownerBody($deed, $pin);
        unset($body['signature_image'], $body['typed_name']);

        $this->submit($deed, $body)->assertSessionHas('success');
        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertNull($receipt->signature_path);
        $this->assertSame('Thandi Mkhize', $receipt->signed_typed_name, 'falls back to the collector name');
    }

    public function test_a_double_submit_collects_once(): void
    {
        [$deed, $pin] = $this->notified();
        $token = $this->tk('handover', $deed)['_form_token'];
        $this->submit($deed, $this->ownerBody($deed, $pin), null, $token)->assertSessionHas('success');
        $this->submit($deed, $this->ownerBody($deed, $pin), null, $token)->assertSessionHas('info');   // the SAME form sent again: already done

        $this->assertSame(1, TitleDeedReceipt::where('title_deed_id', $deed->id)->count());
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'handover')->count());
    }

    public function test_a_second_handover_on_a_collected_deed_is_refused_by_the_service(): void
    {
        [$deed, $pin] = $this->notified();
        $svc = app(TitleDeedHandoverService::class);
        $in = $this->ownerBody($deed, $pin);
        $svc->handover($this->admin, $deed, $in, ['ip' => '127.0.0.1', 'user_agent' => 'phpunit']);

        try {
            $svc->handover($this->admin, $deed->fresh(), $in, ['ip' => '127.0.0.1', 'user_agent' => 'phpunit']);
            $this->fail('expected the second handover to be refused');
        } catch (TitleDeedException $e) {
            $this->assertSame(TitleDeedException::ALREADY_COLLECTED, $e->reason);
        }
        $this->assertSame(1, TitleDeedReceipt::count());
    }

    public function test_an_archived_deed_cannot_be_handed_over(): void
    {
        [$deed, $pin] = $this->notified();
        $deed->delete();
        $this->submit($deed, $this->ownerBody($deed, $pin))->assertNotFound();
        $this->assertSame(0, TitleDeedReceipt::count());
    }

    public function test_the_handover_screen_renders_with_each_mode(): void
    {
        [$deed] = $this->notified();
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Hand over')->assertSee('Has the PIN');
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'pin_required' => false, 'collector_id_photo_required' => true, 'phone_consent_allowed' => true]);
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Photo of the collector');
        $deed->forceFill(['status' => 'collected', 'collected_at' => now()])->save();
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertRedirect(route('title-deeds.show', $deed->id));
    }

    /* ── contact details changed recently ── */

    private function flagRecentlyChanged(Contact $c): void
    {
        DB::table('contact_emails')->insert([
            'agency_id' => $this->agencyId, 'contact_id' => $c->id, 'email' => $c->email, 'email_normalised' => $c->email,
            'is_primary' => 1, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
    }

    public function test_a_recently_changed_contact_blocks_a_user_without_the_override_permission(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        [$deed, $pin] = $this->notified([$a]);
        $this->flagRecentlyChanged($a);   // AFTER the fixtures were aged
        $clerk = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.handover']);

        $this->submit($deed, $this->ownerBody($deed, $pin), $clerk)->assertSessionHas('error');
        $this->assertSame('notified', $deed->fresh()->status);
        $this->assertSame(0, $deed->fresh()->pin_failed_attempts);

        $this->actingAs($clerk)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Someone with permission to override');
    }

    public function test_an_override_user_must_give_a_reason_and_it_is_logged(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        [$deed, $pin] = $this->notified([$a]);
        $this->flagRecentlyChanged($a);   // AFTER the fixtures were aged
        $manager = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.handover', 'title_deeds.override'], name: 'Manager');

        $this->submit($deed, $this->ownerBody($deed, $pin), $manager)->assertSessionHas('error');   // no reason
        $this->assertSame('notified', $deed->fresh()->status);

        $this->submit($deed, $this->ownerBody($deed, $pin, ['override_reason' => 'Owner changed numbers at the counter, ID checked']), $manager)->assertSessionHas('success');

        $receipt = TitleDeedReceipt::firstOrFail();
        $this->assertSame($manager->id, $receipt->override_user_id);
        $this->assertSame('Owner changed numbers at the counter, ID checked', $receipt->override_reason);
        $this->assertTrue(TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'override')->exists());
    }

    public function test_the_contact_changed_window_can_be_switched_off(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'contact_changed_block_days' => 0]);
        $a = $this->contact('Thandi', 'Mkhize');
        [$deed, $pin] = $this->notified([$a]);
        $this->flagRecentlyChanged($a);   // AFTER the fixtures were aged
        $clerk = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.handover']);

        $this->submit($deed, $this->ownerBody($deed, $pin), $clerk)->assertSessionHas('success');
    }

    public function test_an_old_change_does_not_block(): void
    {
        $a = $this->contact('Thandi', 'Mkhize');
        DB::table('contact_emails')->insert([
            'agency_id' => $this->agencyId, 'contact_id' => $a->id, 'email' => $a->email, 'email_normalised' => $a->email,
            'is_primary' => 1, 'created_at' => now()->subDays(40), 'updated_at' => now()->subDays(40),
        ]);
        [$deed, $pin] = $this->notified([$a]);
        $clerk = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.handover']);
        $this->submit($deed, $this->ownerBody($deed, $pin), $clerk)->assertSessionHas('success');
    }

    /* ── collector ID photo ── */

    public function test_the_collector_id_photo_is_required_when_the_agency_asks_for_it(): void
    {
        TitleDeedSetting::create(['agency_id' => $this->agencyId, 'collector_id_photo_required' => true]);
        [$deed, $pin] = $this->notified();

        $this->submit($deed, $this->ownerBody($deed, $pin))->assertSessionHas('error');
        $this->assertSame('notified', $deed->fresh()->status);

        $this->submit($deed, $this->ownerBody($deed, $pin, ['collector_id_photo' => UploadedFile::fake()->image('id.jpg', 600, 400)]))->assertSessionHas('success');

        $receipt = TitleDeedReceipt::firstOrFail();
        $doc = Document::findOrFail($receipt->collector_id_photo_document_id);
        $this->assertSame('title_deed_collector_id', $doc->source_type);
        $this->assertSame('local', $doc->disk);
        Storage::disk('local')->assertExists($doc->storage_path);
        $this->assertSame(0, DB::table('document_contacts')->where('document_id', $doc->id)->count(), 'a collector\'s ID is not filed on the owner\'s contact');
        $this->actingAs($this->admin)->get(route('title-deeds.receipt.file', [$deed->id, 'id-photo']))->assertOk();
    }
}

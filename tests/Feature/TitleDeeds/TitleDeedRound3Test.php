<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedReceipt;
use App\Models\TitleDeeds\TitleDeedSetting;
use App\Models\TitleDeeds\TitleDeedSettingHistory;
use App\Models\TitleDeeds\TitleDeedStorageLocation;
use App\Models\User;
use App\Services\TitleDeeds\TitleDeedNotifier;
use App\Services\TitleDeeds\TitleDeedPinService;
use App\Services\TitleDeeds\TitleDeedPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 round 3 - the settings screen as sentences with explicit choices (Required / Optional / Not used, Yes / No), the effective
 * rules those choices produce, the combinations that are refused, the history of every change, and the handover / record following
 * the three-way values exactly. Plus the wording of the list, the WhatsApp panel, the create form, the record and the handover tabs.
 */
final class TitleDeedRound3Test extends TitleDeedTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** The page text with entities decoded, so an apostrophe is an apostrophe whether the view wrote it raw or through {{ }}. */
    private function text(string $html): string
    {
        return html_entity_decode($html, ENT_QUOTES);
    }

    private function save(array $post)
    {
        return $this->actingAs($this->admin)->post(route('corex.settings.title-deeds.update'), $post);
    }

    private function notified(array $owners = []): array
    {
        $deed = $this->deed($owners);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $deed = $deed->fresh();

        return [$deed, app(TitleDeedPinService::class)->currentCode($deed)];
    }

    private function submit(TitleDeed $deed, array $body)
    {
        return $this->actingAs($this->admin)->from(route('title-deeds.handover', $deed->id))
            ->post(route('title-deeds.handover.submit', $deed->id), $this->tk('handover', $deed) + $body);
    }

    private function pinBody(TitleDeed $deed, string $pin, array $extra = []): array
    {
        return array_merge([
            'method' => 'pin', 'pin' => $pin, 'collector_type' => 'owner', 'collector_contact_id' => $deed->owners->first()->contact_id,
            'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri(),
        ], $extra);
    }

    private function idBody(TitleDeed $deed, array $extra = []): array
    {
        return array_merge([
            'method' => 'id_check', 'collector_contact_id' => $deed->owners->first()->contact_id, 'id_number_seen' => '8001015009087',
            'typed_name' => 'Thandi Mkhize', 'signature_image' => $this->pngDataUri(),
        ], $extra);
    }

    /* ═════ The screen reads as sentences ═════ */

    public function test_the_settings_screen_is_sections_and_sentences_with_no_bare_ticks(): void
    {
        $html = $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->getContent();

        foreach (['To collect a title deed', 'Someone collecting for the owner', 'Ways to sign', 'Who is told', 'Limits and reminders', 'Storage locations', 'Who changed what'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
        foreach (['Collection PIN from the owner', 'Signature of the person collecting', "Photo of the collector's ID", 'May a representative collect?',
            'A representative who arrives without the PIN', "Accept the owner's consent by phone call", 'Authority letter', 'Sign on our screen',
            'Sign on their own phone from a link or QR', "Tell the deal's listing agent when a deed is collected"] as $sentence) {
            $this->assertStringContainsString($sentence, $this->text($html));
        }
        $this->assertStringContainsString('Required', $html);
        $this->assertStringContainsString('Optional', $html);
        $this->assertStringContainsString('Not used', $html);
        $this->assertStringNotContainsString('type="checkbox"', $html, 'no bare tick anywhere on the screen');
        // the numbers carry their unit in the label and say what happens
        $this->assertStringContainsString('Wrong PIN attempts before the PIN locks (attempts)', $html);
        $this->assertStringContainsString('until someone issues a new PIN', $html);
    }

    public function test_the_settings_form_state_is_a_well_formed_object(): void
    {
        // A stray ")" here broke every dependent control in a real browser while all server-side checks stayed green.
        $html = $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/id="td-settings-form"\s+x-data="(\{.*?\})"/s', $html, $m), 'x-data is one closed object');
        $this->assertSame(substr_count($m[1], '{'), substr_count($m[1], '}'));
        $this->assertStringNotContainsString('})"', $m[0]);
    }

    public function test_the_storage_locations_section_counts_them_and_warns_when_there_are_none(): void
    {
        $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->assertSee('1 active location', false)->assertDontSee('deeds cannot be logged', false);

        $this->location->delete();
        $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()->assertSee('No storage locations - deeds cannot be logged.', false);
    }

    /* ═════ What the choices mean (the effective rules) ═════ */

    public function test_a_required_pin_is_the_only_way_in_and_hides_the_representative_without_pin_rules(): void
    {
        $this->settings(['pin_mode' => 'required', 'representatives_allowed' => true, 'rep_without_pin_mode' => 'consent_or_letter', 'phone_consent_allowed' => true]);
        $p = TitleDeedPolicy::for($this->agencyId);

        $this->assertTrue($p->pinRequired());
        $this->assertFalse($p->ownerIdCheckOffered());
        $this->assertFalse($p->repWithoutPinOffered());
        $this->assertSame('refuse', $p->repRule);
        $this->assertFalse($p->phoneConsent, 'phone consent only exists where a representative can arrive without the PIN');
    }

    public function test_optional_and_not_used_pin_open_the_other_routes(): void
    {
        $this->settings(['pin_mode' => 'optional', 'phone_consent_allowed' => true]);
        $p = TitleDeedPolicy::for($this->agencyId);
        $this->assertTrue($p->pinUsed());
        $this->assertFalse($p->pinRequired());
        $this->assertTrue($p->ownerIdCheckOffered());
        $this->assertTrue($p->repWithoutPinOffered());
        $this->assertTrue($p->phoneConsent);

        $this->settings(['pin_mode' => 'not_used']);
        $this->assertFalse(TitleDeedPolicy::for($this->agencyId)->pinUsed());
    }

    public function test_signature_not_used_removes_every_way_to_sign_and_representatives_off_removes_the_letter(): void
    {
        $this->settings(['signature_mode' => 'not_used', 'sign_on_screen_enabled' => true, 'sign_by_link_enabled' => true, 'representatives_allowed' => false, 'authority_letter_mode' => 'required']);
        $p = TitleDeedPolicy::for($this->agencyId);

        $this->assertFalse($p->signatureUsed());
        $this->assertFalse($p->signOnScreen);
        $this->assertFalse($p->signByLink);
        $this->assertFalse($p->letterUsed(), 'no representatives, so no authority letter');
        $this->assertFalse($p->repWithoutPinOffered());
    }

    /* ═════ Combinations that cannot work are refused in plain words ═════ */

    public function test_a_required_signature_needs_at_least_one_way_to_sign(): void
    {
        $r = $this->save(['signature_mode' => 'required', 'sign_on_screen_enabled' => '0', 'sign_by_link_enabled' => '0']);
        $r->assertSessionHasErrors('sign_on_screen_enabled');
        $this->assertStringContainsString('at least one way to sign', session('errors')->first('sign_on_screen_enabled'));
        $this->assertSame(0, TitleDeedSetting::withoutGlobalScopes()->count(), 'nothing is saved');

        $this->save(['signature_mode' => 'required', 'sign_on_screen_enabled' => '0', 'sign_by_link_enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse(TitleDeedSetting::signOnScreenEnabledFor($this->agencyId));

        // Optional or Not used need neither
        $this->save(['signature_mode' => 'optional', 'sign_on_screen_enabled' => '0', 'sign_by_link_enabled' => '0'])->assertSessionHasNoErrors();
    }

    public function test_no_pin_with_representatives_allowed_cannot_also_refuse_every_representative(): void
    {
        $this->save(['pin_mode' => 'not_used', 'representatives_allowed' => '1', 'rep_without_pin_mode' => 'refuse'])->assertSessionHasErrors('rep_without_pin_mode');
        $this->assertSame(0, TitleDeedSetting::withoutGlobalScopes()->count());

        // answering No to "May a representative collect?" makes the rule irrelevant
        $this->save(['pin_mode' => 'not_used', 'representatives_allowed' => '0', 'rep_without_pin_mode' => 'refuse'])->assertSessionHasNoErrors();
        // and with the PIN Required the rule is hidden (not posted), so a stored Refuse is never an error
        $this->save(['pin_mode' => 'required', 'representatives_allowed' => '1'])->assertSessionHasNoErrors();
    }

    public function test_the_owner_link_or_letter_rule_needs_the_letter_to_be_used(): void
    {
        $this->save(['pin_mode' => 'optional', 'representatives_allowed' => '1', 'rep_without_pin_mode' => 'consent_or_letter', 'authority_letter_mode' => 'not_used'])
            ->assertSessionHasErrors('authority_letter_mode');
        $this->save(['pin_mode' => 'optional', 'representatives_allowed' => '1', 'rep_without_pin_mode' => 'consent_or_letter', 'authority_letter_mode' => 'optional'])->assertSessionHasNoErrors();
    }

    /* ═════ History ═════ */

    public function test_every_change_is_kept_with_who_from_to_and_when_and_unchanged_answers_are_not_logged(): void
    {
        $this->save(['pin_mode' => 'optional', 'signature_mode' => 'required', 'reminder_days' => '30'])->assertSessionHasNoErrors();

        $rows = TitleDeedSettingHistory::query()->where('agency_id', $this->agencyId)->get()->keyBy('setting_key');
        $this->assertSame(['pin_mode', 'reminder_days'], $rows->keys()->sort()->values()->all(), 'signature_mode was posted but did not change');
        $this->assertSame('required', $rows['pin_mode']->from_value);
        $this->assertSame('optional', $rows['pin_mode']->to_value);
        $this->assertSame('14', $rows['reminder_days']->from_value);
        $this->assertSame('30', $rows['reminder_days']->to_value);
        $this->assertSame($this->admin->id, (int) $rows['pin_mode']->changed_by_user_id);
        $this->assertNotNull($rows['pin_mode']->created_at);

        $this->save(['sign_by_link_enabled' => '0'])->assertSessionHasNoErrors();
        $link = TitleDeedSettingHistory::query()->where('setting_key', 'sign_by_link_enabled')->firstOrFail();
        $this->assertSame('yes', $link->from_value);
        $this->assertSame('no', $link->to_value);

        $this->actingAs($this->admin)->get(route('corex.settings.title-deeds.edit'))->assertOk()
            ->assertSee('Collection PIN from the owner', false)->assertSee('Required', false)->assertSee('Optional', false)->assertSee($this->admin->name, false);
    }

    /* ═════ Handover: PIN ═════ */

    public function test_pin_required_offers_only_the_pin_and_the_server_refuses_the_other_routes(): void
    {
        $this->settings(['pin_mode' => 'required']);
        [$deed, $pin] = $this->notified();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('The owner is here without the PIN', $html);
        $this->assertStringNotContainsString('Someone else is here without the PIN', $html);
        $this->assertStringContainsString("needs the owner's PIN", $this->text($html));

        $this->submit($deed, $this->idBody($deed))->assertSessionHas('error');
        $this->assertNotSame('collected', $deed->fresh()->status);
        $this->submit($deed, $this->pinBody($deed, $pin))->assertSessionHas('success');
        $this->assertSame('collected', $deed->fresh()->status);
    }

    public function test_pin_optional_offers_all_the_routes_and_the_record_says_not_captured_when_it_was_skipped(): void
    {
        $this->settings(['pin_mode' => 'optional']);
        [$deed] = $this->notified();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringContainsString('The collector has the PIN', $html);
        $this->assertStringContainsString('The owner is here without the PIN', $html);
        $this->assertStringContainsString('Someone else is here without the PIN', $html);

        $this->submit($deed, $this->idBody($deed))->assertSessionHas('success');
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->assertSee('PIN', false)->assertSee('Not captured', false);
    }

    public function test_pin_not_used_shows_no_pin_route_and_sends_no_pin(): void
    {
        $this->settings(['pin_mode' => 'not_used']);
        $deed = $this->deed();
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('The collector has the PIN', $html);
        $this->assertStringContainsString('The owner is here without the PIN', $html);
        $this->assertSame(0, (int) $deed->fresh()->pin_version, 'no PIN was issued');

        $this->submit($deed, $this->pinBody($deed, '123456'))->assertSessionHas('error');
        $this->submit($deed, $this->idBody($deed))->assertSessionHas('success');
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->assertDontSee('Not captured', false);
        $this->assertSame('not_used', TitleDeedReceipt::first()->proof_modes['pin']);
    }

    /* ═════ Handover: signature ═════ */

    public function test_signature_required_cannot_be_skipped(): void
    {
        $this->settings(['pin_mode' => 'optional', 'signature_mode' => 'required']);
        [$deed] = $this->notified();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringContainsString('Signature box', $html);
        $this->assertStringContainsString("Collector's full name", $this->text($html));
        $this->assertStringNotContainsString("Collector's full name (optional)", $this->text($html));

        $this->submit($deed, $this->idBody($deed, ['signature_image' => '']))->assertSessionHas('error');
        $this->assertNotSame('collected', $deed->fresh()->status);
    }

    public function test_signature_optional_may_be_skipped_and_the_record_says_not_captured(): void
    {
        $this->settings(['pin_mode' => 'optional', 'signature_mode' => 'optional']);
        [$deed] = $this->notified();

        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Collector signs here (optional)', false);
        $this->submit($deed, $this->idBody($deed, ['signature_image' => '', 'typed_name' => '']))->assertSessionHas('success');

        $receipt = TitleDeedReceipt::first();
        $this->assertNull($receipt->signature_path);
        $this->assertNull($receipt->signed_at, 'a skipped signature leaves no signing time');
        $this->assertSame('optional', $receipt->proof_modes['signature']);
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->assertSee('Signature', false)->assertSee('Not captured', false);
    }

    public function test_signature_not_used_shows_no_box_no_link_and_nothing_on_the_record(): void
    {
        $this->settings(['pin_mode' => 'not_used', 'signature_mode' => 'not_used']);
        $deed = $this->deed();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Signature box', $html);
        $this->assertStringNotContainsString('Collector signs here', $html);
        $this->assertStringNotContainsString('data-link="1"', $html, 'no button to create a signing link');

        // a signature posted anyway is ignored, never stored
        $this->submit($deed, $this->idBody($deed))->assertSessionHas('success');
        $this->assertNull(TitleDeedReceipt::first()->signature_path);
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->assertDontSee('Not captured', false);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_sign_on_our_screen_off_removes_the_box_and_a_required_signature_must_then_come_by_link(): void
    {
        $this->settings(['pin_mode' => 'optional', 'signature_mode' => 'required', 'sign_on_screen_enabled' => false, 'sign_by_link_enabled' => true]);
        [$deed] = $this->notified();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Signature box', $html);
        $this->assertStringContainsString('data-link="1"', $html);

        $this->submit($deed, $this->idBody($deed))->assertSessionHas('error');
        $this->assertNotSame('collected', $deed->fresh()->status);
    }

    /* ═════ Handover: photo of the ID ═════ */

    public function test_id_photo_required_optional_and_not_used(): void
    {
        $this->settings(['pin_mode' => 'optional', 'collector_id_photo_mode' => 'required']);
        [$deed] = $this->notified();
        $page = $this->text($this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent());
        $this->assertStringContainsString("Photo of the collector's ID", $page);
        $this->assertStringNotContainsString("Photo of the collector's ID (optional)", $page);
        $this->submit($deed, $this->idBody($deed))->assertSessionHas('error');

        $this->settings(['collector_id_photo_mode' => 'optional']);
        $this->assertStringContainsString("Photo of the collector's ID (optional)", $this->text($this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent()));
        $this->submit($deed, $this->idBody($deed))->assertSessionHas('success');
        $record = $this->text($this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->getContent());
        $this->assertStringContainsString("Collector's ID photo", $record);
        $this->assertStringContainsString('Not captured', $record);

        $this->settings(['collector_id_photo_mode' => 'not_used']);
        [$other] = $this->notified([$this->contact('Sipho', 'Dube')]);
        $this->assertStringNotContainsString("Photo of the collector's ID", $this->text($this->actingAs($this->admin)->get(route('title-deeds.handover', $other->id))->assertOk()->getContent()));
        $this->submit($other, $this->idBody($other, ['collector_id_photo' => UploadedFile::fake()->image('id.jpg', 600, 400)]))->assertSessionHas('success');
        $this->assertNull(TitleDeedReceipt::where('title_deed_id', $other->id)->first()->collector_id_photo_document_id, 'a photo sent while Not used is ignored');
    }

    /* ═════ Handover: authority letter, phone consent ═════ */

    public function test_authority_letter_required_applies_to_every_representative_and_not_used_hides_it(): void
    {
        $this->settings(['pin_mode' => 'optional', 'authority_letter_mode' => 'required']);
        [$deed, $pin] = $this->notified();
        $rep = ['method' => 'pin', 'pin' => $pin, 'collector_type' => 'representative', 'rep_name' => 'Busisiwe Mkhize', 'rep_id_number' => '9001015800087', 'rep_relationship' => 'Daughter',
            'typed_name' => 'Busisiwe Mkhize', 'signature_image' => $this->pngDataUri()];

        $this->submit($deed, $rep)->assertSessionHas('error');
        $this->submit($deed, $rep + ['authority_letter' => UploadedFile::fake()->create('letter.pdf', 50, 'application/pdf')])->assertSessionHas('success');
        $this->assertNotNull(TitleDeedReceipt::first()->authority_letter_document_id);

        $this->settings(['authority_letter_mode' => 'not_used']);
        [$d2] = $this->notified([$this->contact('Sipho', 'Dube')]);
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $d2->id))->assertOk()->assertDontSee('name="authority_letter"', false);
    }

    public function test_phone_consent_is_only_offered_when_the_rule_allows_a_representative_without_the_pin(): void
    {
        $this->settings(['pin_mode' => 'optional', 'phone_consent_allowed' => true, 'rep_without_pin_mode' => 'owner_consent']);
        [$deed] = $this->notified();
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertSee('Record a phone call instead', false);

        $this->settings(['rep_without_pin_mode' => 'refuse']);
        $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->assertDontSee('Record a phone call instead', false)->assertDontSee('Someone else is here without the PIN', false);
    }

    /* ═════ Handover: tabs spelled as the counter, and the steps ═════ */

    public function test_the_tabs_describe_who_is_at_the_counter_and_each_shows_what_happens_next(): void
    {
        $this->settings(['pin_mode' => 'optional', 'signature_mode' => 'required', 'collector_id_photo_mode' => 'optional']);
        [$deed] = $this->notified();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.handover', $deed->id))->assertOk()->getContent();
        foreach (['The collector has the PIN', 'The owner is here without the PIN', 'Someone else is here without the PIN'] as $tab) {
            $this->assertStringContainsString($tab, $html);
        }
        $this->assertStringNotContainsString('Rep, no PIN', $html);
        $this->assertStringNotContainsString('Owner, ID check', $html);
        $this->assertStringContainsString('What happens next:', $html);
        $this->assertStringContainsString('1. Type the PIN the collector gives you', $html);
        $this->assertStringContainsString('2. Photograph the collector&#039;s ID (optional)', $html);
        $this->assertStringContainsString('3. The collector signs in the box', $html);
        $this->assertStringContainsString('4. Press &quot;Hand the deed over&quot;', $html);
    }

    /* ═════ Item A + B: the list in words ═════ */

    public function test_list_rows_have_one_labelled_action_and_a_more_panel_and_no_icon_only_buttons(): void
    {
        $this->settings(['pin_mode' => 'optional']);
        $received = $this->deed();
        [$notified] = $this->notified([$this->contact('Sipho', 'Dube')]);

        $html = $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>Notify owners</button>', $html);
        $this->assertStringContainsString('>Hand over</a>', $html);
        $this->assertStringContainsString('>More</button>', $html);
        $this->assertStringNotContainsString('title="Email owner"', $html);
        $this->assertStringNotContainsString('title="Resend PIN"', $html);
        $this->assertStringNotContainsString('aria-label="Open', $html);
        $table = substr($html, (int) strpos($html, 'data-row-actions>'));
        $table = substr($table, 0, (int) strpos($table, '</table>'));
        $this->assertStringNotContainsString('<svg', $table, 'no icon in the rows');
        // the More panel carries every other action as words, Archive last
        foreach (['Send the PIN again', 'Issue a new PIN', 'Email the owners', 'Prepare a WhatsApp to the owners', 'Archive this title deed', 'Open this title deed'] as $word) {
            $this->assertStringContainsString($word, $html);
        }
        $this->assertSame(1, preg_match('/Archive this title deed.*?\]/s', $html));
    }

    public function test_a_collected_row_offers_open_and_the_status_is_plain_text(): void
    {
        $deed = $this->deed();
        $deed->forceFill(['status' => TitleDeed::STATUS_COLLECTED, 'collected_at' => now(), 'collected_by_name' => 'Thandi Mkhize'])->save();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->getContent();
        $this->assertStringContainsString('>Open</a>', $html);
        $this->assertStringNotContainsString('var(--brand-icon)">Owner notified', $html, 'a status never wears the link colour');
    }

    public function test_the_whatsapp_panel_names_the_message_says_since_when_and_can_be_cleared(): void
    {
        $this->settings(['pin_mode' => 'required']);
        $deed = $this->deed([$this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222')]);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $ev = TitleDeedEvent::where('channel', 'whatsapp')->firstOrFail();

        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()
            ->assertSee('Collection notice with the PIN', false)->assertSee('Prepared', false)->assertSee("Don't send - clear this", false)->assertSee('Open WhatsApp and send', false);

        $this->actingAs($this->admin)->post(route('title-deeds.events.dismiss', [$deed->id, $ev->id]), ['return_to' => route('title-deeds.index', [], false)])
            ->assertRedirect(route('title-deeds.index', [], false))->assertSessionHas('success');

        $this->assertSame('not_sent', $ev->fresh()->status);
        $this->assertNull($ev->fresh()->payload_encrypted);
        $this->assertSame('received', $deed->fresh()->status, 'deciding not to send never marks the owner as notified');
        $audit = TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'whatsapp_not_sent')->firstOrFail();
        $this->assertStringStartsWith('WhatsApp to Lerato Khumalo not sent - cleared by ' . $this->admin->name, $audit->summary);
        $this->assertSame($this->admin->id, (int) $audit->user_id);
        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->assertDontSee('WhatsApp messages waiting', false);

        // the record still shows, honestly, that no WhatsApp went out - nothing was deleted
        $record = $this->text($this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->getContent());
        $this->assertStringContainsString('Not sent', $record);
        $this->assertStringContainsString('cleared by ' . $this->admin->name, $record);
        $this->assertStringContainsString('WhatsApp to Lerato Khumalo not sent - cleared by ' . $this->admin->name, $record, 'the history line');
        $this->assertSame(1, TitleDeedEvent::where('channel', 'whatsapp')->count(), 'the message row is kept, never deleted');
    }

    public function test_an_opened_whatsapp_can_also_be_cleared_and_the_confirm_goes_through_the_app_dialog(): void
    {
        $deed = $this->deed([$this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222')]);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $ev = TitleDeedEvent::where('channel', 'whatsapp')->firstOrFail();
        $this->actingAs($this->admin)->post(route('title-deeds.events.open', [$deed->id, $ev->id]));
        $this->assertSame('opened', $ev->fresh()->status);

        $html = $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->getContent();
        $this->assertStringContainsString('Mark as sent', $html);
        $this->assertStringContainsString("Don't send - clear this", $this->text($html));
        $this->assertStringContainsString('data-confirm-modal', $html, 'a short confirm in the app dialog, never a browser box');
        $this->assertStringNotContainsString('I did not send it', $html);

        $this->actingAs($this->admin)->post(route('title-deeds.events.dismiss', [$deed->id, $ev->id]))->assertSessionHas('success');
        $this->assertSame('not_sent', $ev->fresh()->status);
        // pressing it twice is absorbed, and a message already marked sent cannot be cleared into "not sent"
        $this->actingAs($this->admin)->post(route('title-deeds.events.dismiss', [$deed->id, $ev->id]))->assertSessionHas('success');
        $this->assertSame(1, TitleDeedAuditLog::where('title_deed_id', $deed->id)->where('event', 'whatsapp_not_sent')->count());
    }

    public function test_only_people_who_may_notify_or_hand_over_can_dismiss_a_whatsapp(): void
    {
        $deed = $this->deed([$this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222')]);
        app(TitleDeedNotifier::class)->notifyOwners($this->admin, $deed);
        $ev = TitleDeedEvent::where('channel', 'whatsapp')->firstOrFail();

        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $this->actingAs($viewer)->post(route('title-deeds.events.dismiss', [$deed->id, $ev->id]))->assertForbidden();
        $this->assertSame('prepared', $ev->fresh()->status);
    }

    /* ═════ Item C: the create form ═════ */

    public function test_the_agent_list_has_only_active_people_with_access_and_tells_same_names_apart(): void
    {
        $johan = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Johan Reichel']);
        $johanAssistant = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchB->id, 'role' => 'agent', 'name' => 'Johan Reichel', 'is_assistant' => 1]);
        $gone = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Former Agent', 'is_active' => 0]);
        $johanAssistant->forceFill(['is_assistant' => 1])->save();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.create'))->assertOk()->getContent();

        $this->assertStringContainsString('Johan Reichel - ', $html);
        $this->assertSame(1, substr_count($html, 'Johan Reichel - '), 'the assistant with the same name is not offered');
        $this->assertStringNotContainsString('Former Agent', $html);
        $this->assertStringContainsString('Anele Dlamini - ', $html);
        $this->assertStringContainsString(', Margate', $html, 'the branch is part of the option text');
    }

    public function test_required_fields_are_marked_and_the_deal_box_says_what_picking_does(): void
    {
        $html = $this->actingAs($this->admin)->get(route('title-deeds.create'))->assertOk()->getContent();

        $this->assertSame(4, substr_count($html, 'font-normal" style="color: var(--ds-crimson);"> (required)'), 'property, owners, date received, where it is kept');
        $this->assertStringContainsString('Fields marked', $html);
        $this->assertStringContainsString('Fills property, owners, agents and attorney.', $html);
        $this->assertStringContainsString('x-show="!deal"', $html, 'the deal sentence shows only while no deal is picked');
        $form = substr($html, (int) strpos($html, 'id="deed-form"'));
        $form = substr($form, 0, (int) strpos($form, '</form>'));
        $this->assertStringNotContainsString('&times;', $form, 'no icon-only remove buttons in the form');
        $this->assertStringContainsString('(optional)', $html);
    }

    /* ═════ Item D: the record ═════ */

    public function test_the_record_has_one_primary_action_and_archive_is_apart_and_confirmed(): void
    {
        $this->settings(['pin_mode' => 'optional']);
        $deed = $this->deed();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()->getContent();
        $header = substr($html, (int) strpos($html, 'corex-page-banner'), 6000);
        $header = substr($header, 0, (int) strpos($header, 'Archive this title deed'));
        preg_match_all('/<(?:a|button)\b(?![^>]*data-confirm-go)[^>]*corex-btn-primary[^>]*>/', $header, $solid);
        $this->assertCount(1, $solid[0], 'one solid button in the header: the next step - ' . json_encode($solid[0]));
        $this->assertSame(1, preg_match('/corex-btn-primary text-xs[^>]*>\s*Notify the owners/', $header));
        $this->assertStringContainsString('Notify the owners', $html);
        $this->assertStringContainsString('Archive this title deed', $html);
        $this->assertStringContainsString('data-confirm-modal', $html, 'archive goes through the app dialog');

        [$notified] = $this->notified([$this->contact('Sipho', 'Dube')]);
        $html = $this->actingAs($this->admin)->get(route('title-deeds.show', $notified->id))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/corex-btn-primary text-xs no-underline">Hand over/', $html), 'for a notified deed the one primary action is Hand over');
    }

    public function test_a_deed_with_no_storage_location_says_so_and_offers_to_choose_one(): void
    {
        $deed = $this->deed();
        $deed->forceFill(['storage_location_id' => null, 'storage_location' => null])->save();

        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertOk()
            ->assertSee('No storage location chosen', false)->assertSee('Choose a location', false)->assertSee(route('title-deeds.edit', $deed->id), false);
    }

    /* ═════ The Setup Wizard says the same thing ═════ */

    public function test_the_wizard_uses_the_same_wording_and_choices(): void
    {
        $controls = collect(config('agency-onboarding-copy.properties.controls'))->where('source', 'title_deeds')->keyBy('key');

        $this->assertSame('Collection PIN from the owner', $controls['pin_mode']['label']);
        $this->assertSame(['required' => 'Required', 'optional' => 'Optional', 'not_used' => 'Not used'], $controls['pin_mode']['options']);
        $this->assertSame('Sign on their own phone from a link or QR', $controls['sign_by_link_enabled']['label']);
        $this->assertSame(['1' => 'Yes', '0' => 'No'], $controls['representatives_allowed']['options']);
        foreach (['pin_mode', 'signature_mode', 'collector_id_photo_mode', 'authority_letter_mode'] as $k) {
            $this->assertSame('select', $controls[$k]['type']);
        }
        $this->assertSame(TitleDeedSetting::LABELS['pin_mode'], $controls['pin_mode']['label']);
    }

    public function test_the_wizard_saves_a_conflicting_combination_with_the_same_refusal(): void
    {
        $req = \Illuminate\Http\Request::create('/x', 'POST', ['signature_mode' => 'required', 'sign_on_screen_enabled' => '0', 'sign_by_link_enabled' => '0']);
        $req->setUserResolver(fn () => $this->admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Http\Controllers\TitleDeeds\TitleDeedSettingsController::class)->save($req);
    }
}

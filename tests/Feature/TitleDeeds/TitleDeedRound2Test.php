<?php

declare(strict_types=1);

namespace Tests\Feature\TitleDeeds;

use App\Exceptions\TitleDeedDuplicateException;
use App\Models\Deal;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedEvent;
use App\Models\TitleDeeds\TitleDeedFormClaim;
use App\Models\TitleDeeds\TitleDeedStorageLocation;
use App\Services\TitleDeeds\TitleDeedService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * AT-450 round 2, items 1-8: one-time form tokens and the duplicate guard, the visible agent pre-fill, the deal picker and the
 * agents that come from the deal, the agency's storage locations, the scan on the create form, the list's row actions and the
 * honest WhatsApp sequence.
 */
class TitleDeedRound2Test extends TitleDeedTestCase
{
    private function body(array $extra = []): array
    {
        return $this->tk('create') + array_merge([
            'property_id' => $this->property()->id,
            'owner_ids' => [$this->contact('Thandi', 'Mkhize')->id],
            'agent_id' => $this->agent->id,
            'branch_id' => $this->branchA->id,
            'received_at' => now()->toDateString(),
            'storage_location_id' => $this->location->id,
        ], $extra);
    }

    /** A registered DR2 deal with a selling agent, a listing agent and a buyer contact. */
    private function registeredDeal(array $overrides = []): Deal
    {
        $deal = Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'period' => '2026-09', 'deal_date' => '2026-09-01',
            'property_value' => 1_950_000, 'total_commission' => 97_500, 'deal_no' => 1785, 'accepted_status' => 'R',
            'registration_date' => now()->subDays(2)->toDateString(), 'attorney_name' => 'Shepstone & Wylie',
            'property_address' => '23 Shelly Strand', 'seller_name' => 'V. Keys', 'buyer_name' => 'A. Van Buuren',
        ], $overrides));

        return $deal;
    }

    /* ═════ Item 1: Back + submit never makes two deeds ═════ */

    public function test_the_same_form_sent_twice_makes_one_deed_and_lands_on_it(): void
    {
        $body = $this->body();

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $body)->assertSessionHas('success');
        $first = TitleDeed::query()->latest('id')->first();
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $body)
            ->assertRedirect(route('title-deeds.show', $first->id))->assertSessionHas('info');

        $this->assertSame(1, TitleDeed::query()->count());
    }

    public function test_a_failed_save_does_not_burn_the_token(): void
    {
        $body = $this->body(['received_at' => 'not-a-date']);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $body)->assertSessionHasErrors('received_at');
        $this->assertSame(0, TitleDeedFormClaim::query()->count(), 'validation failed before anything was claimed');

        $body['received_at'] = now()->toDateString();
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $body)->assertSessionHas('success');
        $this->assertSame(1, TitleDeed::query()->count());
    }

    public function test_two_tabs_with_the_same_open_property_ask_before_making_a_second_deed(): void
    {
        $property = $this->property();
        $owner = $this->contact('Thandi', 'Mkhize');
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['property_id' => $property->id, 'owner_ids' => [$owner->id]]));
        $this->assertSame(1, TitleDeed::query()->count());

        // A different form (second tab, new token) for the SAME property: warned, nothing created.
        $second = $this->body(['property_id' => $property->id, 'owner_ids' => [$owner->id]]);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $second)->assertSessionHas('duplicate_deed_id');
        $this->assertSame(1, TitleDeed::query()->count());

        // Only the explicit confirmation makes another one.
        $confirm = $this->body(['property_id' => $property->id, 'owner_ids' => [$owner->id], 'confirm_duplicate' => '1']);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $confirm)->assertSessionHas('success');
        $this->assertSame(2, TitleDeed::query()->count());
    }

    public function test_a_collected_deed_does_not_block_a_later_deed_for_the_same_property(): void
    {
        $property = $this->property();
        $deed = $this->deed([], ['_property' => $property]);
        $deed->forceFill(['status' => TitleDeed::STATUS_COLLECTED])->save();

        $again = $this->deed([], ['_property' => $property]);
        $this->assertNotSame($deed->id, $again->id);
    }

    public function test_the_duplicate_guard_looks_agency_wide_not_just_at_my_own_deeds(): void
    {
        $property = $this->property();
        $this->deed([], ['_property' => $property], $this->admin);

        $this->expectException(TitleDeedDuplicateException::class);
        app(TitleDeedService::class)->create($this->agent, [
            'property_id' => $property->id, 'owner_ids' => [$this->contact('Sipho', 'Dube')->id], 'received_at' => now()->toDateString(),
            'storage_location_id' => $this->location->id,
        ]);
    }

    public function test_a_token_is_for_one_purpose_and_one_person(): void
    {
        $wrongPurpose = $this->tk('notify');   // a notify token on the create form
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $wrongPurpose + $this->body())->assertSessionHas('error');
        $this->assertSame(0, TitleDeed::query()->count());

        $someoneElses = $this->tk('create', null, $this->agent);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $someoneElses + $this->body())->assertSessionHas('error');
        $this->assertSame(0, TitleDeed::query()->count());

        $this->actingAs($this->admin)->post(route('title-deeds.store'), array_diff_key($this->body(), ['_form_token' => 1]))->assertSessionHas('error');
        $this->assertSame(0, TitleDeed::query()->count());
    }

    public function test_notify_pressed_twice_with_one_token_emails_once(): void
    {
        $deed = $this->deed();
        $t = $this->tk('notify', $deed);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $t);
        $count = TitleDeedEvent::query()->where('kind', 'owner_notice')->count();
        $this->assertGreaterThan(0, $count);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $t)->assertSessionHas('info');
        $this->assertSame($count, TitleDeedEvent::query()->where('kind', 'owner_notice')->count());
    }

    public function test_a_new_pin_pressed_twice_with_one_token_issues_one_pin(): void
    {
        $deed = $this->deed();
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed));
        $before = $deed->fresh()->pin_version;
        $t = $this->tk('pin_new', $deed);
        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id), $t);
        $this->actingAs($this->admin)->post(route('title-deeds.pin.new', $deed->id), $t);

        $this->assertSame($before + 1, $deed->fresh()->pin_version);
    }

    /* ═════ Item 2: the responsible agent is what the form shows ═════ */

    public function test_the_responsible_agent_saved_is_the_one_posted_even_when_the_property_has_another(): void
    {
        $other = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Pieter Botha']);
        $property = $this->property(agent: $this->agent);

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['property_id' => $property->id, 'agent_id' => $other->id]));

        $this->assertSame($other->id, (int) TitleDeed::query()->latest('id')->first()->agent_id);
    }

    public function test_the_property_suggestion_names_its_agent_and_lists_open_deeds_for_it(): void
    {
        $property = $this->property();
        $deed = $this->deed([], ['_property' => $property]);

        $json = $this->actingAs($this->admin)->getJson(route('title-deeds.search.property-suggestions', $property->id))->assertOk()->json();
        $this->assertSame($this->agent->id, $json['agent_id']);
        $this->assertSame('Anele Dlamini', $json['agent_name']);
        $this->assertSame($deed->id, $json['existing'][0]['id']);
    }

    /* ═════ Items 3 + 8: the deal picker and the agents that come from the deal ═════ */

    public function test_the_deal_search_finds_registered_deals_only_by_number_property_and_name(): void
    {
        $deal = $this->registeredDeal();
        $this->registeredDeal(['deal_no' => 1790, 'accepted_status' => 'P', 'registration_date' => null, 'property_address' => '9 Hidden Lane']);

        $find = fn (string $q) => collect($this->actingAs($this->admin)->getJson(route('title-deeds.search.deals', ['q' => $q]))->assertOk()->json('results'))->pluck('id')->all();

        $this->assertSame([$deal->id], $find('1785'));
        $this->assertSame([$deal->id], $find('Shelly Strand'));
        $this->assertSame([$deal->id], $find('Van Buuren'));
        $this->assertSame([], $find('Hidden Lane'), 'a deal that is not registered yet is not offered');
        $this->assertSame([], $find('%'), 'a wildcard is searched for literally');
    }

    public function test_a_deal_in_another_agency_is_not_offered_or_pre_filled(): void
    {
        $otherAgency = $this->makeAgency('Cape Town');
        $foreign = Deal::create([
            'agency_id' => $otherAgency, 'branch_id' => $this->branchA->id, 'period' => '2026-09', 'deal_date' => '2026-09-01',
            'property_value' => 1, 'total_commission' => 1, 'deal_no' => 5001, 'accepted_status' => 'R', 'property_address' => 'Foreign Rd',
        ]);

        $this->assertSame([], $this->actingAs($this->admin)->getJson(route('title-deeds.search.deals', ['q' => 'Foreign']))->json('results'));
        $this->actingAs($this->admin)->getJson(route('title-deeds.search.deal-prefill', $foreign->id))->assertNotFound();
    }

    public function test_the_deal_prefill_uses_the_selling_agent_as_responsible_and_never_guesses_contacts(): void
    {
        $selling1 = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Selling One']);
        $selling2 = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Selling Two']);
        $listing = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Listing One']);
        $deal = $this->registeredDeal();
        $deal->agents()->attach($selling1->id, ['side' => 'selling']);
        $deal->agents()->attach($selling2->id, ['side' => 'selling']);
        $deal->agents()->attach($listing->id, ['side' => 'listing']);

        $p = $this->actingAs($this->admin)->getJson(route('title-deeds.search.deal-prefill', $deal->id))->assertOk()->json();

        $this->assertSame($selling1->id, $p['agent_id']);
        $this->assertSame([$selling2->id], collect($p['additional_agents'])->pluck('id')->all());
        $this->assertSame($listing->id, $p['listing_agent']['id']);
        $this->assertSame('Shepstone & Wylie', $p['attorney_firm']);
        $this->assertSame($this->branchA->id, $p['branch_id']);
        $this->assertSame([], $p['owners'], 'no buyer contact is linked on the deal: nothing is guessed from the typed name');
        $this->assertSame('A. Van Buuren', $p['buyer_text']);
    }

    public function test_buyers_linked_on_the_deal_are_offered_as_owners(): void
    {
        $deal = $this->registeredDeal();
        $buyer = $this->contact('Annelie', 'Van Buuren');
        $deal->contacts()->attach($buyer->id, ['role' => 'buyer']);

        $p = $this->actingAs($this->admin)->getJson(route('title-deeds.search.deal-prefill', $deal->id))->json();
        $this->assertSame([$buyer->id], collect($p['owners'])->pluck('id')->all());
    }

    public function test_creating_from_a_deal_stores_the_deal_the_listing_agent_and_the_others_and_shows_the_link(): void
    {
        $selling1 = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Selling One']);
        $selling2 = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Selling Two']);
        $listing = \App\Models\User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA->id, 'role' => 'agent', 'name' => 'Listing One']);
        $deal = $this->registeredDeal();

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body([
            'deal_id' => $deal->id, 'agent_id' => $selling1->id, 'listing_agent_id' => $listing->id, 'additional_agent_ids' => [$selling2->id],
        ]))->assertSessionHas('success');

        $deed = TitleDeed::query()->latest('id')->first();
        $this->assertSame($deal->id, (int) $deed->deal_id);
        $this->assertSame($selling1->id, (int) $deed->agent_id);
        $this->assertSame($listing->id, (int) $deed->listing_agent_id);
        $this->assertSame([$selling2->id], array_map('intval', $deed->additional_agent_ids));

        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))
            ->assertOk()->assertSee('Deal 1785', false)->assertSee('Listing agent', false)->assertSee('Listing One', false);
    }

    public function test_a_deal_from_another_agency_or_an_agent_from_another_agency_cannot_be_posted_in(): void
    {
        $otherAgency = $this->makeAgency('Cape Town');
        $foreign = Deal::create([
            'agency_id' => $otherAgency, 'branch_id' => $this->branchA->id, 'period' => '2026-09', 'deal_date' => '2026-09-01',
            'property_value' => 1, 'total_commission' => 1, 'accepted_status' => 'R',
        ]);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['deal_id' => $foreign->id]))->assertSessionHasErrors('deal_id');
        $this->assertSame(0, TitleDeed::query()->count());

        $outsider = \App\Models\User::factory()->create(['role' => 'agent']);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $outsider->id)->update(['agency_id' => $otherAgency]);   // the factory stamps the acting user's agency
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['additional_agent_ids' => [$outsider->id]]))->assertSessionHasErrors('additional_agent_ids');
        $this->assertSame(0, TitleDeed::query()->count(), 'a person from another agency is never stored as an agent to tell');
    }

    /* ═════ Item 4: where it is kept ═════ */

    public function test_where_it_is_kept_must_be_one_of_the_agencys_own_locations(): void
    {
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['storage_location_id' => null]))->assertSessionHasErrors();
        $this->assertSame(0, TitleDeed::query()->count());

        $otherAgency = $this->makeAgency('Cape Town');
        // inserted directly: the model would stamp the acting user's agency on it
        $foreignId = (int) \Illuminate\Support\Facades\DB::table('title_deed_storage_locations')->insertGetId(['agency_id' => $otherAgency, 'name' => 'Their safe', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['storage_location_id' => $foreignId]))->assertSessionHasErrors();
        $this->assertSame(0, TitleDeed::query()->count());

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body())->assertSessionHas('success');
        $this->assertSame($this->location->id, (int) TitleDeed::query()->latest('id')->first()->storage_location_id);
    }

    public function test_a_branch_location_is_only_for_that_branchs_deeds(): void
    {
        $branchOnly = TitleDeedStorageLocation::create(['agency_id' => $this->agencyId, 'name' => 'Southbroom strong room', 'branch_id' => $this->branchB->id]);

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['branch_id' => $this->branchA->id, 'storage_location_id' => $branchOnly->id]))->assertSessionHasErrors();
        $this->assertSame(0, TitleDeed::query()->count());
    }

    public function test_moving_a_deed_records_where_it_came_from_and_where_it_went(): void
    {
        $deed = $this->deed();
        $other = TitleDeedStorageLocation::create(['agency_id' => $this->agencyId, 'name' => 'Strong room']);

        $this->actingAs($this->admin)->put(route('title-deeds.update', $deed->id), [
            'property_id' => $deed->property_id, 'owner_ids' => $deed->owners()->pluck('contact_id')->all(), 'agent_id' => $deed->agent_id,
            'branch_id' => $deed->branch_id, 'received_at' => $deed->received_at->toDateString(), 'storage_location_id' => $other->id,
        ])->assertSessionHas('success');

        $this->assertSame($other->id, (int) $deed->fresh()->storage_location_id);
        $entry = TitleDeedAuditLog::query()->where('title_deed_id', $deed->id)->where('event', 'location_changed')->first();
        $this->assertNotNull($entry);
        $this->assertStringContainsString('Safe 2', $entry->summary);
        $this->assertStringContainsString('Strong room', $entry->summary);
    }

    public function test_a_legacy_text_location_is_shown_and_an_archived_location_keeps_showing_on_old_deeds(): void
    {
        $deed = $this->deed();
        $deed->forceFill(['storage_location_id' => null, 'storage_location' => 'Top shelf, grey cabinet'])->save();
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertSee('Legacy: Top shelf, grey cabinet', false);

        $deed->forceFill(['storage_location_id' => $this->location->id])->save();
        $this->location->delete();
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertSee('Safe 2', false);
    }

    public function test_an_agency_with_no_locations_is_told_where_to_add_one_and_cannot_log_a_deed(): void
    {
        $this->location->delete();

        $this->actingAs($this->admin)->get(route('title-deeds.create'))->assertOk()->assertSee('no storage locations yet', false);
        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['storage_location_id' => $this->location->id]))->assertSessionHasErrors();
    }

    public function test_the_locations_screen_is_permission_gated_and_full_crud_with_soft_delete(): void
    {
        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $this->actingAs($viewer)->get(route('corex.settings.title-deeds.locations'))->assertForbidden();

        $manager = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.manage_settings']);
        $this->actingAs($manager)->post(route('corex.settings.title-deeds.locations.store'), ['name' => 'Fire-proof cabinet', 'sort_order' => 5])->assertSessionHas('success');
        $loc = TitleDeedStorageLocation::query()->where('name', 'Fire-proof cabinet')->firstOrFail();
        $this->assertSame($this->agencyId, (int) $loc->agency_id);

        $this->actingAs($manager)->get(route('corex.settings.title-deeds.locations', ['q' => 'fire']))->assertOk()->assertSee('Fire-proof cabinet', false);
        $this->actingAs($manager)->put(route('corex.settings.title-deeds.locations.update', $loc->id), ['name' => 'Fireproof cabinet'])->assertSessionHas('success');
        $this->actingAs($manager)->delete(route('corex.settings.title-deeds.locations.destroy', $loc->id))->assertSessionHas('success');
        $this->assertNotNull(TitleDeedStorageLocation::withTrashed()->find($loc->id)->deleted_at);
        $this->actingAs($manager)->post(route('corex.settings.title-deeds.locations.restore', $loc->id))->assertSessionHas('success');
        $this->assertNull(TitleDeedStorageLocation::query()->find($loc->id)->deleted_at);
    }

    public function test_another_agencys_location_is_not_reachable_by_id(): void
    {
        $otherAgency = $this->makeAgency('Cape Town');
        $foreign = TitleDeedStorageLocation::create(['agency_id' => $otherAgency, 'name' => 'Their safe']);
        $manager = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.manage_settings']);

        $this->actingAs($manager)->put(route('corex.settings.title-deeds.locations.update', $foreign->id), ['name' => 'Mine now'])->assertNotFound();
        $this->actingAs($manager)->delete(route('corex.settings.title-deeds.locations.destroy', $foreign->id))->assertNotFound();
        $this->assertSame('Their safe', $foreign->fresh()->name);
    }

    /* ═════ Item 5: the scan on the create form ═════ */

    public function test_a_scan_chosen_on_the_create_form_is_saved_with_the_deed(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('deed.pdf', 200, 'application/pdf');

        $this->actingAs($this->admin)->post(route('title-deeds.store'), $this->body(['scan' => $file]))->assertSessionHas('success');

        $deed = TitleDeed::query()->latest('id')->first();
        $this->assertNotNull($deed->scan_document_id);
        $this->actingAs($this->admin)->get(route('title-deeds.scan.download', $deed->id))->assertOk();
    }

    public function test_a_bad_scan_creates_nothing_and_keeps_what_was_typed(): void
    {
        $bad = UploadedFile::fake()->create('virus.exe', 20, 'application/octet-stream');

        $r = $this->actingAs($this->admin)->from(route('title-deeds.create'))->post(route('title-deeds.store'), $this->body(['scan' => $bad, 'notes' => 'Keep these words']));

        $r->assertSessionHasErrors('scan');
        $this->assertSame(0, TitleDeed::query()->count());
        $this->assertSame('Keep these words', session()->getOldInput('notes'));
    }

    public function test_replacing_a_scan_keeps_the_earlier_one_downloadable(): void
    {
        Storage::fake('local');
        $deed = $this->deed();
        $this->actingAs($this->admin)->post(route('title-deeds.scan.upload', $deed->id), ['scan' => UploadedFile::fake()->create('first.pdf', 50, 'application/pdf')]);
        $first = $deed->fresh()->scan_document_id;
        $this->actingAs($this->admin)->post(route('title-deeds.scan.upload', $deed->id), ['scan' => UploadedFile::fake()->create('second.pdf', 50, 'application/pdf')]);

        $this->assertNotSame($first, $deed->fresh()->scan_document_id);
        $this->actingAs($this->admin)->get(route('title-deeds.show', $deed->id))->assertSee('Earlier scans', false)->assertSee('first.pdf', false);
        $this->actingAs($this->admin)->get(route('title-deeds.scan.earlier', [$deed->id, $first]))->assertOk();
    }

    /* ═════ Item 6: quick actions on the list ═════ */

    public function test_the_list_offers_only_the_actions_that_fit_the_row_and_the_person(): void
    {
        $received = $this->deed();
        $collected = $this->deed([], ['_property' => $this->property('2 Beach Rd')]);
        $collected->forceFill(['status' => TitleDeed::STATUS_COLLECTED])->save();

        $html = $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->getContent();
        $this->assertStringContainsString('Notify the owners of', $html);
        $this->assertStringContainsString(route('title-deeds.handover', $received->id), $html);
        $this->assertStringNotContainsString(route('title-deeds.handover', $collected->id), $html, 'a collected deed has no hand over action');

        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $viewerHtml = $this->actingAs($viewer)->get(route('title-deeds.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Notify the owners of', $viewerHtml);
        $this->assertStringNotContainsString(route('title-deeds.handover', $received->id), $viewerHtml);
    }

    public function test_a_row_action_returns_to_the_same_filtered_list_and_ignores_a_foreign_return_address(): void
    {
        $deed = $this->deed();
        $list = route('title-deeds.index', ['status' => 'received', 'page' => 1], false);

        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed, null, ['return_to' => $list]))->assertRedirect($list);

        $deed2 = $this->deed([], ['_property' => $this->property('7 Other Rd')]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed2->id), $this->tk('notify', $deed2, null, ['return_to' => '//evil.example/x']))
            ->assertRedirect(route('title-deeds.show', $deed2->id));
    }

    public function test_a_row_action_on_a_deed_outside_my_scope_is_not_found(): void
    {
        $deed = $this->deed();
        $other = $this->userWith(['access_title_deeds', 'title_deeds.view', 'title_deeds.notify'], 'own', $this->branchB->id);

        $this->actingAs($other)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed, $other))->assertNotFound();
    }

    /* ═════ Item 7: WhatsApp is one honest step ═════ */

    public function test_whatsapp_cannot_be_marked_sent_before_it_was_opened_and_not_sent_is_recorded(): void
    {
        $onlyPhone = $this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222');
        $deed = $this->deed([$onlyPhone]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed));
        $ev = TitleDeedEvent::query()->where('channel', 'whatsapp')->firstOrFail();

        $this->actingAs($this->admin)->post(route('title-deeds.events.confirm', [$deed->id, $ev->id]))->assertSessionHas('error');
        $this->actingAs($this->admin)->post(route('title-deeds.events.not-sent', [$deed->id, $ev->id]))->assertSessionHas('error');
        $this->assertSame('prepared', $ev->fresh()->status);

        $this->actingAs($this->admin)->post(route('title-deeds.events.open', [$deed->id, $ev->id]));
        $this->actingAs($this->admin)->post(route('title-deeds.events.not-sent', [$deed->id, $ev->id]))->assertSessionHas('success');
        $this->assertSame('not_sent', $ev->fresh()->status);
        $this->assertSame('received', $deed->fresh()->status, 'a WhatsApp nobody sent never makes an owner "notified"');
    }

    public function test_the_list_shows_the_whatsapp_waiting_to_be_sent(): void
    {
        $onlyPhone = $this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222');
        $deed = $this->deed([$onlyPhone]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed));

        $this->actingAs($this->admin)->get(route('title-deeds.index'))->assertOk()->assertSee('WhatsApp waiting to be sent', false)->assertSee('Open WhatsApp and send', false);
    }

    public function test_opening_whatsapp_is_audited_with_who_and_a_viewer_cannot_do_it(): void
    {
        $onlyPhone = $this->contact('Lerato', 'Khumalo', email: null, phone: '0731112222');
        $deed = $this->deed([$onlyPhone]);
        $this->actingAs($this->admin)->post(route('title-deeds.notify', $deed->id), $this->tk('notify', $deed));
        $ev = TitleDeedEvent::query()->where('channel', 'whatsapp')->firstOrFail();

        $res = $this->actingAs($this->admin)->post(route('title-deeds.events.open', [$deed->id, $ev->id]));
        $this->assertSame(302, $res->getStatusCode());
        $this->assertStringStartsWith('https://wa.me/', (string) $res->headers->get('Location'), (string) session('error'));
        $this->assertTrue(TitleDeedAuditLog::query()->where('title_deed_id', $deed->id)->where('user_id', $this->admin->id)->where('event', 'whatsapp_opened')->exists());

        // (the viewer role is created last: once any role has grants, the plain admin role is fail-closed)
        $viewer = $this->userWith(['access_title_deeds', 'title_deeds.view']);
        $this->actingAs($viewer)->post(route('title-deeds.events.open', [$deed->id, $ev->id]))->assertForbidden();
    }

    /* ═════ Item 4: the Setup Wizard walks an agency through its storage locations ═════ */

    public function test_the_setup_wizard_adds_and_removes_storage_locations_for_the_own_agency_only(): void
    {
        $theirs = (int) \Illuminate\Support\Facades\DB::table('title_deed_storage_locations')->insertGetId(['agency_id' => $this->makeAgency('Cape Town'), 'name' => 'Their safe', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->admin)->post(route('corex.agency-setup.collection.add', ['collection' => 'title_deed_location']), ['name' => 'Wizard strong room'])->assertRedirect();
        $made = TitleDeedStorageLocation::query()->where('name', 'Wizard strong room')->firstOrFail();
        $this->assertSame($this->agencyId, (int) $made->agency_id);

        $this->actingAs($this->admin)->get(route('corex.agency-setup.step', ['step' => 'properties']))->assertOk()
            ->assertSee('Wizard strong room', false)->assertSee('Where title deeds are kept', false)->assertDontSee('Their safe', false);

        $this->actingAs($this->admin)->delete(route('corex.agency-setup.collection.remove', ['collection' => 'title_deed_location', 'id' => $theirs]));
        $this->assertNull(TitleDeedStorageLocation::withoutGlobalScopes()->withTrashed()->find($theirs)->deleted_at, 'another agency\'s location is untouched');

        $this->actingAs($this->admin)->delete(route('corex.agency-setup.collection.remove', ['collection' => 'title_deed_location', 'id' => $made->id]));
        $this->assertNotNull(TitleDeedStorageLocation::withTrashed()->find($made->id)->deleted_at, 'removing is a soft archive');
    }
}

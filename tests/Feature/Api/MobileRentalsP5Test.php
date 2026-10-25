<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\RentalInspection;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileInspectionExtras;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 P5 — the staff inspection API for the app. .ai/specs/rental-inspections.md §14.2, at-508-mobile-rentals.md §4/§5.
 * Stage (a): checklist, start, list, detail (the full-resync source), observations, observation photos, notes, attendance, discrepancy resolve.
 * Stage (b) (signatures, completion) is in the second half of this file.
 */
final class MobileRentalsP5Test extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;
    use BuildsMobileInspectionExtras;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        $this->buildInspectionExtras();
    }

    private function checklistUrl(): string
    {
        return "/api/v1/mobile/properties/{$this->property->id}/rental-inspection-items";
    }

    /** An open Routine inspection on the world's lease, created by the property's agent. */
    private function open(array $attrs = []): RentalInspection
    {
        return $this->inspectionRow(RentalInspection::TYPE_AD_HOC, $attrs);
    }

    private function observe(RentalInspection $i, array $over = [], $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->asStaff($as ?? $this->admin)->postJson($this->base("/{$i->id}/observations"), $over + [
            'item_id' => $this->ceiling->id, 'condition' => 'good', 'client_idempotency_key' => $this->uuid(),
        ]);
    }

    // ── checklist ─────────────────────────────────────────────────────────────────────────────

    public function test_the_checklist_is_the_propertys_own_rooms_items_and_meters_with_the_agencys_condition_words(): void
    {
        $res = $this->asStaff($this->admin)->getJson($this->checklistUrl())->assertOk();

        $res->assertJsonPath('rooms.0.label', 'Bedroom 1')
            ->assertJsonPath('rooms.0.items.0.label', 'Ceiling')
            ->assertJsonPath('rooms.0.items.1.label', 'Floor')
            ->assertJsonPath('meters.0.label', 'Electricity meter')
            ->assertJsonPath('property.id', $this->property->id)
            ->assertJsonPath('meta.empty_reason', null);
        $this->assertContains('good', array_column($res->json('condition_states'), 'key'));
        $this->assertNotEmpty($res->json('refusal_reason_presets'));
    }

    public function test_the_checklist_of_a_property_without_rooms_says_why_it_is_empty_and_retired_items_stay_out(): void
    {
        $this->floor->update(['is_retired' => true]);
        $res = $this->asStaff($this->admin)->getJson($this->checklistUrl())->assertOk();
        $this->assertCount(1, $res->json('rooms.0.items'));
        $this->assertCount(2, $this->asStaff($this->admin)->getJson($this->checklistUrl() . '?include_retired=1')->json('rooms.0.items'));

        $bare = \App\Models\Property::forceCreate(['agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id, 'title' => '1 Bare Road', 'address' => '1 Bare Road', 'status' => 'active', 'listing_type' => 'rental']);
        $empty = $this->asStaff($this->admin)->getJson("/api/v1/mobile/properties/{$bare->id}/rental-inspection-items")->assertOk();
        $this->assertNotEmpty($empty->json('meta.empty_reason'));
    }

    public function test_the_checklist_is_scoped_and_permissioned(): void
    {
        $this->grantEveryone();

        $this->asStaff($this->agent)->getJson($this->checklistUrl())->assertOk();                       // the property's own agent
        $this->asStaff($this->outsider)->getJson($this->checklistUrl())->assertNotFound();              // another agency
        $this->asStaff($this->colleague)->getJson($this->checklistUrl())->assertForbidden();            // same agency, not their listing
        $this->asStaff($this->otherBranchManager)->getJson($this->checklistUrl())->assertForbidden();   // same agency, another branch

        $nobody = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'nobody_role', 'is_active' => true]);
        $this->asStaff($nobody)->getJson($this->checklistUrl())->assertForbidden();                     // no rental_inspections.view
    }

    // ── start ─────────────────────────────────────────────────────────────────────────────────

    public function test_start_creates_the_inspection_and_a_replay_returns_the_same_one(): void
    {
        $key = $this->uuid();
        $payload = ['lease_id' => $this->lease->id, 'type' => 'in', 'client_idempotency_key' => $key];

        $first = $this->asStaff($this->admin)->postJson($this->base(), $payload)->assertCreated();
        $first->assertJsonPath('duplicate', false)->assertJsonPath('type', 'in')->assertJsonPath('status', 'draft')
            ->assertJsonPath('property.address', fn ($a) => str_contains((string) $a, '12 Sea Road'));
        $this->assertSame('Tina Test', $first->json('parties.tenants.0.name'));
        $this->assertSame('Lenny Test', $first->json('parties.landlord.name'));
        $this->assertSame('Ceiling', $first->json('checklist.rooms.0.items.0.label'));

        $again = $this->asStaff($this->admin)->postJson($this->base(), $payload)->assertOk();
        $again->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, RentalInspection::where('lease_id', $this->lease->id)->count());
        $this->assertSame($key, RentalInspection::first()->client_idempotency_key);
    }

    public function test_start_stores_the_time_the_agent_tapped_start_not_the_time_the_phone_found_signal(): void
    {
        $captured = now()->subHours(3)->startOfSecond();
        $res = $this->asStaff($this->admin)->postJson($this->base(), [
            'lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'client_idempotency_key' => $this->uuid(), 'captured_at' => $captured->toIso8601String(),
        ])->assertCreated();

        $this->assertSame($captured->toIso8601String(), \Carbon\Carbon::parse($res->json('started_at'))->toIso8601String());
        $this->assertTrue(RentalInspection::find($res->json('id'))->created_at->equalTo($captured));
    }

    public function test_start_refuses_a_second_in_inspection_with_a_clear_409_and_validates_its_input(): void
    {
        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'in', 'client_idempotency_key' => $this->uuid()])->assertCreated();

        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'in', 'client_idempotency_key' => $this->uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'cannot_start');

        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'in'])->assertStatus(422)->assertJsonValidationErrors('client_idempotency_key');
        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'sideways', 'client_idempotency_key' => $this->uuid()])->assertStatus(422)->assertJsonValidationErrors('type');
        $this->asStaff($this->admin)->postJson($this->base(), ['type' => 'in', 'client_idempotency_key' => $this->uuid()])->assertStatus(422)->assertJsonValidationErrors('lease_id');
    }

    public function test_start_is_scoped_and_permissioned(): void
    {
        $this->grantEveryone();
        $body = fn () => ['lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'client_idempotency_key' => $this->uuid()];

        $this->asStaff($this->outsider)->postJson($this->base(), $body())->assertNotFound();        // another agency's lease
        $this->asStaff($this->colleague)->postJson($this->base(), $body())->assertForbidden();      // same agency, not their lease
        $this->asStaff($this->agent)->postJson($this->base(), $body())->assertCreated();            // the lease's own creator

        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
        $this->asStaff($viewer)->postJson($this->base(), $body())->assertForbidden();               // may look, may not record
    }

    // ── list ──────────────────────────────────────────────────────────────────────────────────

    public function test_the_list_pages_at_25_and_defaults_to_the_soonest_due_first(): void
    {
        foreach (range(1, 27) as $n) {
            $this->open(['scheduled_for' => now()->addDays($n)->toDateString()]);
        }

        $p1 = $this->asStaff($this->admin)->getJson($this->base())->assertOk();
        $this->assertCount(25, $p1->json('data'));
        $this->assertSame(27, $p1->json('meta.total'));
        $this->assertSame(2, $p1->json('meta.last_page'));
        $this->assertSame(25, $p1->json('meta.per_page'));
        $this->assertNull($p1->json('meta.empty_reason'));
        $dates = array_column($p1->json('data'), 'scheduled_for');
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates, 'soonest due first');

        $p2 = $this->asStaff($this->admin)->getJson($this->base() . '?page=2')->assertOk();
        $this->assertCount(2, $p2->json('data'));
        $this->assertSame(2, $p2->json('meta.page'));

        $desc = $this->asStaff($this->admin)->getJson($this->base() . '?direction=desc')->json('data.0.scheduled_for');
        $this->assertSame(now()->addDays(27)->toDateString(), $desc);
    }

    public function test_the_list_searches_sorts_and_filters(): void
    {
        $in = $this->open(['type' => 'in', 'scheduled_for' => now()->addDays(5)->toDateString()]);
        $out = $this->open(['type' => 'out', 'scheduled_for' => now()->addDays(2)->toDateString(), 'status' => 'completed']);
        $routine = $this->open(['scheduled_for' => now()->addDays(9)->toDateString()]);

        $this->assertSame([$out->id, $in->id, $routine->id], array_column($this->asStaff($this->admin)->getJson($this->base())->json('data'), 'id'));
        $byType = array_column($this->asStaff($this->admin)->getJson($this->base() . '?sort=type')->json('data'), 'type');
        $this->assertSame(['ad_hoc', 'in', 'out'], $byType);

        $this->assertSame([$out->id], array_column($this->asStaff($this->admin)->getJson($this->base() . '?type=out')->json('data'), 'id'));
        $this->assertSame([$out->id], array_column($this->asStaff($this->admin)->getJson($this->base() . '?status=completed')->json('data'), 'id'));
        $range = $this->base() . '?date_from=' . now()->addDays(4)->toDateString() . '&date_to=' . now()->addDays(6)->toDateString();
        $this->assertSame([$in->id], array_column($this->asStaff($this->admin)->getJson($range)->json('data'), 'id'));

        // search: the property's address and the tenant's name
        $this->assertCount(3, $this->asStaff($this->admin)->getJson($this->base() . '?q=Sea+Road')->json('data'));
        $this->assertCount(3, $this->asStaff($this->admin)->getJson($this->base() . '?search=Tina')->json('data'));
        $none = $this->asStaff($this->admin)->getJson($this->base() . '?q=Nowhere+Lane')->assertOk();
        $this->assertSame([], $none->json('data'));
        $this->assertSame('No inspections match your search or filters.', $none->json('meta.empty_reason'));

        $this->asStaff($this->admin)->getJson($this->base() . '?status=bogus')->assertStatus(422);
        $this->asStaff($this->admin)->getJson($this->base() . '?sort=bogus')->assertStatus(422);
    }

    public function test_an_empty_list_says_plainly_why_and_each_person_sees_only_what_their_scope_allows(): void
    {
        $this->grantEveryone();
        $empty = $this->asStaff($this->agent)->getJson($this->base())->assertOk();
        $this->assertSame([], $empty->json('data'));
        $this->assertSame('There are no inspections for you to see yet.', $empty->json('meta.empty_reason'));

        $mine = $this->open();

        $this->assertSame([$mine->id], array_column($this->asStaff($this->agent)->getJson($this->base())->json('data'), 'id'));
        $this->assertSame([], $this->asStaff($this->colleague)->getJson($this->base())->json('data'));        // own scope, not theirs
        $this->assertSame([], $this->asStaff($this->otherBranchManager)->getJson($this->base())->json('data')); // another branch
        $this->assertSame([], $this->asStaff($this->outsider)->getJson($this->base())->json('data'));         // another agency
        $this->assertSame('There are no inspections for you to see yet.', $this->asStaff($this->outsider)->getJson($this->base())->json('meta.empty_reason'));
    }

    // ── observations ──────────────────────────────────────────────────────────────────────────

    public function test_an_observation_is_recorded_and_a_replay_returns_the_same_row(): void
    {
        $i = $this->open();
        $key = $this->uuid();

        $first = $this->observe($i, ['client_idempotency_key' => $key, 'condition' => 'damaged', 'notes' => 'Crack above the window'])->assertCreated();
        $first->assertJsonPath('duplicate', false)->assertJsonPath('condition', 'damaged')->assertJsonPath('item_label', 'Ceiling')
            ->assertJsonPath('source', 'ad_hoc')->assertJsonPath('discrepancy_id', null);

        $again = $this->observe($i, ['client_idempotency_key' => $key, 'condition' => 'damaged', 'notes' => 'Crack above the window'])->assertOk();
        $again->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, RentalInspectionObservation::where('rental_inspection_id', $i->id)->count());

        // the same key on a DIFFERENT inspection is refused, never silently attached to the wrong one
        $other = $this->open();
        $this->observe($other, ['client_idempotency_key' => $key])->assertStatus(422);
    }

    public function test_a_late_observation_is_stored_at_the_time_it_was_captured_and_the_detail_orders_by_it(): void
    {
        $i = $this->open();
        $recent = now()->subMinutes(10)->startOfSecond();
        $earlier = now()->subHours(5)->startOfSecond();

        // the later fact arrives first (out of order)
        $a = $this->observe($i, ['item_id' => $this->ceiling->id, 'captured_at' => $recent->toIso8601String()])->assertCreated();
        $b = $this->observe($i, ['item_id' => $this->floor->id, 'captured_at' => $earlier->toIso8601String()])->assertCreated();

        $this->assertSame($earlier->toIso8601String(), \Carbon\Carbon::parse($b->json('captured_at'))->toIso8601String());
        $this->assertNotNull($b->json('received_at'));

        $detail = $this->asStaff($this->admin)->getJson($this->base("/{$i->id}"))->assertOk();
        $this->assertSame([$b->json('id'), $a->json('id')], array_column($detail->json('observations'), 'id'), 'ordered by when it was captured, not when it arrived');
    }

    public function test_an_observation_is_validated_and_a_conflicting_one_raises_a_discrepancy_the_agent_can_resolve(): void
    {
        $i = $this->open();

        $this->observe($i, ['condition' => 'sparkly'])->assertStatus(422)->assertJsonValidationErrors('condition');
        $this->observe($i, ['client_idempotency_key' => 'not-a-uuid'])->assertStatus(422)->assertJsonValidationErrors('client_idempotency_key');
        $this->observe($i, ['item_id' => 999999])->assertStatus(422)->assertJsonValidationErrors('item_id');
        $this->observe($i, ['condition' => 'damaged'])->assertStatus(422);   // damaged needs a reason on record
        $this->assertSame(0, RentalInspectionObservation::count());

        // two different people disagree about the same item -> one discrepancy, raised by the one shared path
        $first = $this->observe($i, ['condition' => 'good'], $this->admin)->assertCreated();
        $second = $this->observe($i, ['condition' => 'damaged', 'notes' => 'Water stain'], $this->agent)->assertCreated();
        $discrepancyId = $second->json('discrepancy_id');
        $this->assertNotNull($discrepancyId);

        $detail = $this->asStaff($this->admin)->getJson($this->base("/{$i->id}"))->assertOk();
        $this->assertSame([$discrepancyId], array_column($detail->json('discrepancies'), 'id'));
        $this->assertFalse($detail->json('discrepancies.0.resolved'));
        $this->assertEqualsCanonicalizing([$first->json('id'), $second->json('id')], $detail->json('discrepancies.0.observation_ids'));

        $url = $this->base("/{$i->id}/discrepancies/{$discrepancyId}/resolve");
        $this->asStaff($this->admin)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors('accepted_observation_id');
        $this->asStaff($this->admin)->postJson($url, ['accepted_observation_id' => 999999])->assertNotFound();   // an id that is not in this agency answers 404, like the web

        $body = ['accepted_observation_id' => $second->json('id'), 'resolution_note' => 'Confirmed on site'];
        $this->asStaff($this->admin)->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', false)->assertJsonPath('resolved', true)
            ->assertJsonPath('accepted_observation_id', $second->json('id'));
        // a retry of the same decision is answered, a different decision on an already-resolved difference is refused
        $this->asStaff($this->admin)->postJson($url, $body)->assertOk()->assertJsonPath('duplicate', true);
        $this->asStaff($this->admin)->postJson($url, ['accepted_observation_id' => $first->json('id')])->assertStatus(409);
    }

    // ── photos ────────────────────────────────────────────────────────────────────────────────

    public function test_a_photo_is_stored_with_an_absolute_url_and_a_replay_returns_the_same_photo(): void
    {
        $i = $this->open();
        $obs = $this->observe($i)->assertCreated()->json('id');
        $url = $this->base("/{$i->id}/observations/{$obs}/photos");
        $key = $this->uuid();
        $captured = now()->subHours(2)->startOfSecond();

        $first = $this->asStaff($this->admin)->post($url, ['photo' => $this->jpeg(), 'client_idempotency_key' => $key, 'captured_at' => $captured->toIso8601String()], ['Accept' => 'application/json'])->assertCreated();
        $first->assertJsonPath('duplicate', false)->assertJsonPath('observation_id', $obs)->assertJsonPath('taken_at_source', 'client');
        $this->assertStringStartsWith('http', (string) $first->json('url'));
        $this->assertSame($captured->toIso8601String(), \Carbon\Carbon::parse($first->json('taken_at'))->toIso8601String());

        $again = $this->asStaff($this->admin)->post($url, ['photo' => $this->jpeg(), 'client_idempotency_key' => $key], ['Accept' => 'application/json'])->assertOk();
        $again->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, RentalInspectionPhoto::where('rental_inspection_id', $i->id)->count());
    }

    public function test_a_photo_is_validated_and_the_observation_must_belong_to_the_inspection(): void
    {
        $i = $this->open();
        $obs = $this->observe($i)->json('id');
        $url = $this->base("/{$i->id}/observations/{$obs}/photos");

        $this->asStaff($this->admin)->post($url, ['client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->asStaff($this->admin)->post($url, ['photo' => $this->jpeg()], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('client_idempotency_key');

        $elsewhere = $this->open();
        $this->asStaff($this->admin)->post($this->base("/{$elsewhere->id}/observations/{$obs}/photos"), ['photo' => $this->jpeg(), 'client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertNotFound();
    }

    // ── notes + attendance ────────────────────────────────────────────────────────────────────

    public function test_a_room_note_is_idempotent_and_ordered_by_when_it_was_written(): void
    {
        $i = $this->open();
        $url = $this->base("/{$i->id}/rooms/{$this->room->id}/notes");
        $key = $this->uuid();
        $earlier = now()->subHours(4)->startOfSecond();

        $first = $this->asStaff($this->admin)->postJson($url, ['note' => 'Damp smell', 'client_idempotency_key' => $key, 'captured_at' => $earlier->toIso8601String()])->assertCreated();
        $first->assertJsonPath('duplicate', false)->assertJsonPath('note', 'Damp smell');
        $this->assertSame($earlier->toIso8601String(), \Carbon\Carbon::parse($first->json('captured_at'))->toIso8601String());

        $this->asStaff($this->admin)->postJson($url, ['note' => 'Damp smell', 'client_idempotency_key' => $key])->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('id', $first->json('id'));
        $this->assertSame(1, RentalInspectionRoomNote::where('rental_inspection_id', $i->id)->count());

        $this->asStaff($this->admin)->postJson($url, ['client_idempotency_key' => $this->uuid()])->assertStatus(422)->assertJsonValidationErrors('note');
        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/rooms/999999/notes"), ['note' => 'x', 'client_idempotency_key' => $this->uuid()])->assertNotFound();

        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/overall-notes"), ['overall_notes' => 'Clean and fair'])->assertOk()->assertJsonPath('overall_notes', 'Clean and fair');
    }

    public function test_attendance_is_recorded_once_per_key(): void
    {
        $i = $this->open();
        $key = $this->uuid();
        $body = ['party_role' => 'agent', 'party_user_id' => $this->agent->id, 'outcome' => 'attended', 'attended_as' => 'self', 'client_idempotency_key' => $key];

        $first = $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/attendance"), $body)->assertCreated();
        $first->assertJsonPath('duplicate', false);
        $this->assertNotEmpty($first->json('attendance_board'));

        $again = $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/attendance"), $body)->assertOk();
        $again->assertJsonPath('duplicate', true)->assertJsonPath('recorded_id', $first->json('recorded_id'));

        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/attendance"), ['outcome' => 'attended', 'client_idempotency_key' => $this->uuid()])->assertStatus(422);
        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/attendance"), $body + [])->assertOk();
    }

    // ── detail: scoping, archived, resync ─────────────────────────────────────────────────────

    public function test_detail_is_scoped_cross_agency_404_same_agency_outside_scope_403(): void
    {
        $this->grantEveryone();
        $i = $this->open();

        $this->asStaff($this->agent)->getJson($this->base("/{$i->id}"))->assertOk();
        $this->asStaff($this->outsider)->getJson($this->base("/{$i->id}"))->assertNotFound();
        $this->asStaff($this->colleague)->getJson($this->base("/{$i->id}"))->assertForbidden();
        $this->asStaff($this->otherBranchManager)->getJson($this->base("/{$i->id}"))->assertForbidden();
        $this->asStaff($this->agent)->getJson($this->base('/999999'))->assertNotFound();

        // writes are held to the same two layers
        $this->observe($i, [], $this->outsider)->assertNotFound();
        $this->observe($i, [], $this->colleague)->assertForbidden();
        $this->observe($i, [], $this->agent)->assertCreated();
        $viewer = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'viewer', 'is_active' => true]);
        $this->observe($i, [], $viewer)->assertForbidden();   // may look, may not record
    }

    public function test_a_write_for_an_inspection_archived_meanwhile_is_a_410_the_app_can_drop(): void
    {
        $i = $this->open();
        $obsKey = $this->uuid();
        $obs = $this->observe($i, ['client_idempotency_key' => $obsKey])->assertCreated()->json('id');
        $photoKey = $this->uuid();
        $this->asStaff($this->admin)->post($this->base("/{$i->id}/observations/{$obs}/photos"), ['photo' => $this->jpeg(), 'client_idempotency_key' => $photoKey], ['Accept' => 'application/json'])->assertCreated();

        $i->delete();   // archived (soft delete) while the phone still held queued writes

        $this->observe($i)->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->observe($i, ['client_idempotency_key' => $obsKey])->assertStatus(410)->assertJsonPath('code', 'archived');   // even a replay of a stored one
        $this->asStaff($this->admin)->post($this->base("/{$i->id}/observations/{$obs}/photos"), ['photo' => $this->jpeg(), 'client_idempotency_key' => $photoKey], ['Accept' => 'application/json'])->assertStatus(410);
        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/rooms/{$this->room->id}/notes"), ['note' => 'x', 'client_idempotency_key' => $this->uuid()])->assertStatus(410);
        $this->asStaff($this->admin)->getJson($this->base("/{$i->id}"))->assertStatus(410)->assertJsonPath('code', 'archived');
        $this->assertSame([], $this->asStaff($this->admin)->getJson($this->base())->json('data'), 'an archived inspection is not listed');

        // an archived START replays as 410 too
        $key = $this->uuid();
        $started = $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'client_idempotency_key' => $key])->assertCreated()->json('id');
        RentalInspection::find($started)->delete();
        $this->asStaff($this->admin)->postJson($this->base(), ['lease_id' => $this->lease->id, 'type' => 'ad_hoc', 'client_idempotency_key' => $key])->assertStatus(410)->assertJsonPath('code', 'archived');
    }

    public function test_the_detail_carries_everything_the_app_needs_to_rebuild_its_screen(): void
    {
        $i = $this->open(['inspector_user_id' => $this->agent->id]);
        $obs = $this->observe($i, ['condition' => 'good'])->json('id');
        $this->asStaff($this->admin)->post($this->base("/{$i->id}/observations/{$obs}/photos"), ['photo' => $this->jpeg(), 'client_idempotency_key' => $this->uuid()], ['Accept' => 'application/json'])->assertCreated();
        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/rooms/{$this->room->id}/notes"), ['note' => 'Fresh paint', 'client_idempotency_key' => $this->uuid()])->assertCreated();
        $this->asStaff($this->admin)->postJson($this->base("/{$i->id}/overall-notes"), ['overall_notes' => 'Fair overall'])->assertOk();

        $d = $this->asStaff($this->admin)->getJson($this->base("/{$i->id}"))->assertOk();

        foreach (['id', 'type', 'type_label', 'status', 'is_recordable', 'signed_locked', 'started_at', 'inspector', 'property', 'lease_id', 'parties', 'header',
            'overall_notes', 'checklist', 'observations', 'photos', 'room_notes', 'discrepancies', 'signatures', 'attendance', 'readiness', 'condition_states', 'refusal_reason_presets'] as $key) {
            $this->assertArrayHasKey($key, $d->json(), "detail is missing {$key}");
        }
        $d->assertJsonPath('status', 'draft')->assertJsonPath('is_recordable', true)->assertJsonPath('inspector.id', $this->agent->id)
            ->assertJsonPath('overall_notes', 'Fair overall')
            ->assertJsonPath('room_notes.0.note', 'Fresh paint')
            ->assertJsonPath('observations.0.id', $obs)
            ->assertJsonPath('checklist.rooms.0.items.0.latest_observation_id', $obs)
            ->assertJsonPath('signatures.required', false)
            ->assertJsonPath('attendance.required', false);
        $this->assertCount(1, $d->json('observations.0.photos'));
        $this->assertStringStartsWith('http', $d->json('observations.0.photos.0.url'));
        $this->assertCount(1, $d->json('photos'));
        $this->assertSame('Tina Test', $d->json('parties.tenants.0.name'));
    }
}

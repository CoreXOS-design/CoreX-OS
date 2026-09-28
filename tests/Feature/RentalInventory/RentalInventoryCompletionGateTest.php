<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInventory;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\PropertyRoom;
use App\Models\RentalInventory;
use App\Models\RentalInventoryLine;
use App\Models\RentalInventoryRoomMark;
use App\Models\RentalInventorySignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-inventory.md §12 — Johan, decided not a question: an
 * inventory that says "complete" with nothing captured is worse than no
 * inventory at all, because it looks authoritative. RentalInventory::
 * markCompleted() must refuse when no line exists anywhere on the record,
 * and must refuse while any of the property's own rooms has neither a line
 * nor an explicit "nothing in this room" mark — the same distinction
 * rental-inspections' markRoomNa() draws, built here as
 * RentalInventoryRoomMark since an inventory room has no checklist items to
 * write a per-item observation against.
 */
final class RentalInventoryCompletionGateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInventory $inventory;
    private PropertyRoom $lounge;
    private PropertyRoom $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Completion Gate Agency', 'slug' => 'completion-gate-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Completion Gate Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->lounge = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->bedroom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 2,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
    }

    /** No lease tenants and no resolvable landlord — signs off with the agent's signature alone, isolating the new gates from the pre-existing signature gate. */
    private function signAgentOnly(): void
    {
        RentalInventorySignature::capture($this->inventory, RentalInventorySignature::PARTY_AGENT, RentalInventorySignature::DISPOSITION_SIGNED, [
            'party_signature_path' => '/fake/agent-signature.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);
    }

    public function test_completing_an_inventory_with_zero_lines_is_refused(): void
    {
        $this->assertSame(0, RentalInventoryLine::count());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nothing has been recorded yet');

        $this->inventory->markCompleted();
    }

    public function test_the_zero_lines_refusal_fires_even_when_every_signature_is_already_in(): void
    {
        // Nothing recorded, but signatures obtained anyway (an agent could,
        // in principle, get a tenant to sign a blank page) — the gate must
        // still hold. Regression guard for the exact shape of bug this
        // section exists to close: a signed-off, empty document.
        $this->signAgentOnly();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nothing has been recorded yet');

        $this->inventory->markCompleted();
    }

    public function test_completing_an_inventory_with_an_unvisited_room_is_refused(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        // Bedroom 1 has neither a line nor a mark — it was never opened.
        $this->signAgentOnly();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Bedroom 1');

        $this->inventory->markCompleted();
    }

    public function test_marking_a_room_empty_satisfies_the_completion_gate_for_that_room(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom->id, 'marked_empty_by_user_id' => $this->agent->id,
            'marked_empty_at' => now(),
        ]);
        $this->signAgentOnly();

        $this->inventory->markCompleted();

        $this->assertSame(RentalInventory::STATUS_COMPLETED, $this->inventory->fresh()->status);
    }

    public function test_a_room_with_lines_needs_no_mark_at_all(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom->id, 'room_label' => 'Bedroom 1',
            'quantity' => 2, 'description' => 'Single beds',
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->signAgentOnly();

        $this->inventory->markCompleted();

        $this->assertSame(RentalInventory::STATUS_COMPLETED, $this->inventory->fresh()->status);
    }

    public function test_a_retired_line_does_not_count_as_visiting_its_room(): void
    {
        $line = RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Added in error',
            'created_by_user_id' => $this->agent->id,
        ]);
        $line->retire();
        RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom->id, 'marked_empty_by_user_id' => $this->agent->id,
            'marked_empty_at' => now(),
        ]);

        // The retired line is the only line on the whole inventory, so the
        // "at least one line" gate itself must refuse — lines() excludes
        // retired rows by design (RentalInventory::lines()).
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nothing has been recorded yet');

        $this->inventory->markCompleted();
    }

    public function test_mark_room_empty_endpoint_creates_a_mark_and_is_idempotent(): void
    {
        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->bedroom]))
            ->assertStatus(201);

        $this->assertSame(1, RentalInventoryRoomMark::where('rental_inventory_id', $this->inventory->id)
            ->where('property_room_id', $this->bedroom->id)->count());

        // Marking again is a no-op on the row count — updateOrCreate(), not a second insert.
        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $this->bedroom]))
            ->assertStatus(201);

        $this->assertSame(1, RentalInventoryRoomMark::where('rental_inventory_id', $this->inventory->id)
            ->where('property_room_id', $this->bedroom->id)->count());
    }

    public function test_mark_room_empty_404s_for_a_room_on_a_different_property(): void
    {
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $foreignRoom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $otherProperty->id,
            'type' => 'lounge', 'label' => 'Foreign Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->postJson(route('corex.rental-inventories.rooms.mark-empty', [$this->inventory, $foreignRoom]))
            ->assertStatus(404);
    }

    public function test_complete_endpoint_returns_the_plain_language_refusal_not_a_500(): void
    {
        $response = $this->postJson(route('corex.rental-inventories.complete', $this->inventory));

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Cannot complete: nothing has been recorded yet. Add at least one item, or mark each room as having nothing in it, before completing this inventory.');
    }

    public function test_a_property_with_zero_rooms_is_still_blocked_by_the_zero_lines_gate(): void
    {
        $blankProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Blank Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $blankLease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $blankProperty->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now()->subMonth(),
            'created_by_user_id' => $this->agent->id,
        ]);
        $blankInventory = RentalInventory::start($blankProperty, $blankLease, $this->agent);

        $this->assertCount(0, $blankInventory->unvisitedRooms(), 'A property with zero rooms has zero unvisited rooms — the zero-lines gate is what must catch this, not room visitation.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nothing has been recorded yet');

        $blankInventory->markCompleted();
    }

    /**
     * Johan, 2026-09-28, property 5294 — "the space then shows 'Nothing in
     * this room' plus an Undo." The DELETE counterpart to markRoomEmpty()
     * (same URI, RESTful verb) — a room can be un-marked and go back to
     * genuinely unvisited, which the completion gate must then refuse
     * again exactly as if it had never been marked.
     */
    public function test_unmark_room_empty_reverses_the_mark_and_the_room_becomes_unvisited_again(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom->id, 'marked_empty_by_user_id' => $this->agent->id,
            'marked_empty_at' => now(),
        ]);
        $this->assertCount(0, $this->inventory->fresh()->unvisitedRooms());

        $this->deleteJson(route('corex.rental-inventories.rooms.unmark-empty', [$this->inventory, $this->bedroom]))
            ->assertOk();

        $this->assertSame(0, RentalInventoryRoomMark::where('rental_inventory_id', $this->inventory->id)
            ->where('property_room_id', $this->bedroom->id)->count());
        $this->assertCount(1, $this->inventory->fresh()->unvisitedRooms());
        $this->assertSame('Bedroom 1', $this->inventory->fresh()->unvisitedRooms()->first()->label);
    }

    public function test_unmark_room_empty_is_idempotent_for_an_already_unmarked_room(): void
    {
        $this->deleteJson(route('corex.rental-inventories.rooms.unmark-empty', [$this->inventory, $this->bedroom]))
            ->assertOk();
    }

    public function test_unmark_room_empty_404s_for_a_room_on_a_different_property(): void
    {
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Other Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $foreignRoom = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $otherProperty->id,
            'type' => 'lounge', 'label' => 'Foreign Lounge', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->deleteJson(route('corex.rental-inventories.rooms.unmark-empty', [$this->inventory, $foreignRoom]))
            ->assertStatus(404);
    }

    /**
     * Johan, 2026-09-28 — "the red completion warning must list the
     * unchecked rooms by name, each clickable to jump to that space."
     * RentalInventoryUnvisitedRoomsException carries the structured
     * {id, label} list; the complete() endpoint must surface it in the
     * JSON body, not just fold it into the plain-language message.
     */
    public function test_complete_endpoint_returns_the_structured_unvisited_room_list(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'room_label' => 'Lounge',
            'quantity' => 1, 'description' => 'Samsung TV 55"',
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->signAgentOnly();

        $response = $this->postJson(route('corex.rental-inventories.complete', $this->inventory));

        $response->assertStatus(409);
        $response->assertJsonPath('unvisited_rooms.0.id', $this->bedroom->id);
        $response->assertJsonPath('unvisited_rooms.0.label', 'Bedroom 1');
        $response->assertJsonPath('message', 'Cannot complete: Bedroom 1 has not been checked yet. Add items to it, or mark it as having nothing in it, before completing this inventory.');
    }

    /** The zero-lines/outstanding-signature refusals are plain \LogicException — no unvisited_rooms key to confuse the frontend into rendering an empty room list. */
    public function test_complete_endpoint_omits_unvisited_rooms_for_other_kinds_of_refusal(): void
    {
        $response = $this->postJson(route('corex.rental-inventories.complete', $this->inventory));

        $response->assertStatus(409);
        $response->assertJsonMissingPath('unvisited_rooms');
    }
}

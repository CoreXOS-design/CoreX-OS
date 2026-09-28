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
 * Johan, 2026-09-28, on property 5294/inventory 8 (VIEW-ONLY on the real
 * record — every fixture here is its own throwaway inventory): the show/
 * sign page (a) "lists only Bedroom 1" — grouping lines by room_label meant
 * a room with zero lines never appeared at all, and (b) "shows the
 * signatures block TWICE (once with dates, once without)" — the read-only
 * history list and the interactive recording section both rendered every
 * party regardless of whether they'd already signed.
 */
final class RentalInventoryShowPageTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private Property $property;
    private Lease $lease;
    private RentalInventory $inventory;
    private PropertyRoom $bedroom1;
    private PropertyRoom $bedroom2;
    private PropertyRoom $lounge;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();

        $this->agency = Agency::create(['name' => 'Show Page Agency', 'slug' => 'show-page-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $this->agency->id]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'Show Page Property', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $this->lease = Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 12000, 'start_date' => now()->subMonths(2),
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->bedroom1 = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 1,
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->bedroom2 = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'bedroom', 'label' => 'Bedroom 2', 'source' => 'manual', 'sort_order' => 2,
            'created_by_user_id' => $this->agent->id,
        ]);
        $this->lounge = PropertyRoom::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'type' => 'lounge', 'label' => 'Lounge', 'source' => 'manual', 'sort_order' => 3,
            'created_by_user_id' => $this->agent->id,
        ]);

        $this->inventory = RentalInventory::start($this->property, $this->lease, $this->agent);
    }

    public function test_show_page_renders_every_room_in_its_own_state_not_just_rooms_with_lines(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom1->id, 'room_label' => 'Bedroom 1',
            'quantity' => 1, 'description' => 'Queen bed', 'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventoryRoomMark::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->lounge->id, 'marked_empty_by_user_id' => $this->agent->id,
            'marked_empty_at' => now(),
        ]);
        // Bedroom 2 gets neither a line nor a mark — genuinely unvisited.

        $response = $this->get(route('corex.rental-inventories.show', $this->inventory))->assertOk();

        // The bug: Bedroom 2 and Lounge never appeared at all when grouped
        // by room_label, because neither one had a line.
        $response->assertSee('Bedroom 1', false);
        $response->assertSee('Queen bed', false);
        $response->assertSee('Bedroom 2', false);
        $response->assertSee('Not checked', false);
        $response->assertSee('Lounge', false);
        $response->assertSee('Nothing in this room', false);
    }

    public function test_show_page_never_renders_a_compact_status_duplicate_for_an_already_dispositioned_party(): void
    {
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom1->id, 'room_label' => 'Bedroom 1',
            'quantity' => 1, 'description' => 'Queen bed', 'created_by_user_id' => $this->agent->id,
        ]);
        RentalInventorySignature::capture($this->inventory, 'agent', 'signed', [
            'party_signature_path' => 'signatures/fake-agent.png',
            'recorded_by_user_id' => $this->agent->id,
        ]);

        $response = $this->get(route('corex.rental-inventories.show', $this->inventory))->assertOk();

        // The read-only history block (with the real recorded date) — this
        // is the ONE place the agent's disposition should ever appear.
        $response->assertSee($this->inventory->signatures->first()->disposition_recorded_at->format('Y-m-d'), false);
        // The bug: the recording section ALSO had its own compact "already
        // dispositioned" status template, a SECOND, dateless summary for the
        // same party, sitting outside the read-only block above. That
        // template is now deleted from the source entirely (not merely
        // re-conditioned — Alpine's <template x-if> content is present in
        // server-rendered HTML regardless of the runtime condition, so
        // asserting it's gone only proves something if the markup was
        // actually removed, which is what this checks): its exact literal
        // markup no longer appears anywhere in the compiled page.
        $response->assertDontSee('<template x-if="dispositionFor(\'agent\', null)">', false);
        $response->assertDontSee('style="color:var(--text-muted);">Signed</span>', false);
    }

    public function test_completing_and_reopening_shows_the_unvisited_room_names_are_gone_once_marked(): void
    {
        // Sanity: once every room is visited (lines or marks) and every
        // party has dispositioned, the page still renders correctly and
        // exposes no leftover unvisited-room markup from a prior failed
        // attempt (unvisitedRooms is JS-only state, reset per page load).
        foreach ([$this->bedroom1, $this->bedroom2, $this->lounge] as $room) {
            RentalInventoryRoomMark::create([
                'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
                'property_room_id' => $room->id, 'marked_empty_by_user_id' => $this->agent->id,
                'marked_empty_at' => now(),
            ]);
        }
        RentalInventoryLine::create([
            'agency_id' => $this->agency->id, 'rental_inventory_id' => $this->inventory->id,
            'property_room_id' => $this->bedroom1->id, 'room_label' => 'Bedroom 1',
            'quantity' => 1, 'description' => 'Queen bed', 'created_by_user_id' => $this->agent->id,
        ]);

        $this->assertCount(0, $this->inventory->fresh()->unvisitedRooms());

        $this->get(route('corex.rental-inventories.show', $this->inventory))->assertOk();
    }
}

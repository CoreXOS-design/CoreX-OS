<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\AgencyContactSettings;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Core Matches → "Update buyer pipeline" (Johan, 2026-10-07).
 *
 * The button moves the buyer on the Buyer Pipeline board ITSELF: it posts to the board's own
 * endpoints (command-center.buyers.update-state / .mark-lost) — there is no second copy of the
 * transition logic. These tests prove (a) the screen offers the control only where the board
 * would let the viewer move the buyer, (b) the board's endpoints do the move + audit + gate
 * effects the Core Matches row then reflects, and (c) a crafted request cannot move a buyer the
 * viewer cannot reach — which is the same route-model-binding rule as on the board.
 */
final class CoreMatchUpdateBuyerPipelineTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Pipeline Button Agency', 'slug' => 'pipe-btn-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent  = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true,
        ]);

        DB::table('agency_lost_deal_reasons')->insert([
            'agency_id' => $this->agency->id, 'code' => 'bought_elsewhere', 'label' => 'Bought through another agency',
            'category' => 'competition', 'applies_to_buyers' => 1, 'active' => 1, 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function buyer(string $first, ?string $state, string $listingType = 'sale', ?User $owner = null): Contact
    {
        $owner ??= $this->agent;
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $owner->id, 'agent_id' => $owner->id,
            'first_name' => $first, 'last_name' => 'Pipe', 'is_buyer' => true, 'buyer_state' => $state,
        ]);
        ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $owner->id, 'agent_id' => $owner->id,
            'name' => 'Wishlist', 'listing_type' => $listingType, 'status' => ContactMatch::STATUS_ACTIVE,
            'price_min' => 1_000_000, 'price_max' => 2_000_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);
        // Creating a wishlist auto-lands a state-less buyer on the pipeline; pin the state the test wants.
        Contact::withoutGlobalScopes()->whereKey($contact->id)->update(['buyer_state' => $state]);

        return $contact->fresh();
    }

    private function board(?User $viewer = null, string $route = 'corex.core-matches.index', array $query = ['scope' => 'own'])
    {
        return $this->actingAs($viewer ?? $this->agent)->get(route($route, $query));
    }

    private function excluded(?array $states): void
    {
        AgencyContactSettings::forAgency($this->agency->id)->update(['core_matches_excluded_buyer_states' => $states]);
        AgencyContactSettings::clearCoreMatchExcludedBuyerStatesCache();
    }

    private function grant(string $key, string $role = 'agent'): void
    {
        DB::table('role_permissions')->insert([
            'role' => $role, 'permission_key' => $key, 'agency_id' => $this->agency->id, 'scope' => 'all',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ---- The control ---------------------------------------------------------

    public function test_a_buyer_row_offers_update_buyer_pipeline_with_the_boards_own_statuses_and_endpoints(): void
    {
        $contact = $this->buyer('Warmie', 'warm');

        $this->board()->assertOk()
            ->assertSee('Update buyer pipeline', false)
            // @js() JSON-escapes the slashes in the URL it hands the popup
            ->assertSee(str_replace('/', '\\/', route('command-center.buyers.update-state', $contact)), false)
            ->assertSee(str_replace('/', '\\/', route('command-center.buyers.mark-lost', $contact)), false)
            // the board's own four statuses
            ->assertSee('<option value="new">New</option>', false)
            ->assertSee('<option value="warm">Warm</option>', false)
            ->assertSee('<option value="cold">Cold</option>', false)
            ->assertSee('<option value="lost">Lost</option>', false);
    }

    public function test_the_lost_choice_uses_the_shared_lost_reason_dialog_with_the_agencys_reasons(): void
    {
        $this->buyer('Warmie', 'warm');

        $this->board()->assertOk()
            ->assertSee('data-mark-lost-form', false)
            ->assertSee('Why is this', false)
            ->assertSee('Bought through another agency', false)
            ->assertSee('name="reason_code"', false)
            ->assertSee('name="notes"', false)
            ->assertSee('name="outcome"', false);
    }

    public function test_the_buyer_page_still_renders_the_same_shared_dialog(): void
    {
        $contact = $this->buyer('Warmie', 'warm');

        $this->actingAs($this->agent)->get(route('command-center.buyers.show', $contact))
            ->assertOk()
            ->assertSee('data-mark-lost-form', false)
            ->assertSee('Why is this', false)
            ->assertSee('Bought through another agency', false)
            ->assertSee(route('command-center.buyers.mark-lost', $contact), false);
    }

    public function test_a_contact_with_no_pipeline_status_is_not_offered_the_control(): void
    {
        $this->buyer('Nostate', null);

        $this->board()->assertOk()->assertSee('Nostate', false)->assertDontSee('Update buyer pipeline', false);
    }

    public function test_the_rentals_board_hides_the_control_without_the_rental_pipeline_permission(): void
    {
        $this->grant('core_matches.view');
        $this->buyer('Tenanty', 'warm', 'rental');

        $this->board(null, 'corex.rentals.core-matches.index', [])->assertOk()
            ->assertSee('Tenanty', false)->assertDontSee('Update buyer pipeline', false);
    }

    public function test_the_rentals_board_offers_the_control_with_the_rental_pipeline_permission(): void
    {
        $this->grant('core_matches.view');
        $this->grant('buyer_pipeline.view');
        $contact = $this->buyer('Tenanty', 'warm', 'rental');

        $this->board(null, 'corex.rentals.core-matches.index', [])->assertOk()
            ->assertSee('Update buyer pipeline', false)
            ->assertSee('Move this tenant to a different Buyer Pipeline status', false)
            ->assertSee(str_replace('/', '\\/', route('command-center.buyers.update-state', $contact)), false);
    }

    // ---- The move: the board's own endpoints, so the same audit + effects -----

    public function test_moving_to_cold_is_the_boards_own_transition_and_is_audited_and_the_row_shows_it(): void
    {
        $contact = $this->buyer('Warmie', 'warm');

        $this->actingAs($this->agent)->patchJson(route('command-center.buyers.update-state', $contact), ['state' => 'cold'])
            ->assertOk()->assertJson(['success' => true, 'new_state' => 'cold']);

        $this->assertSame('cold', $contact->fresh()->buyer_state);
        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $contact->id, 'agency_id' => $this->agency->id, 'from_state' => 'warm', 'to_state' => 'cold',
            'reason' => 'manual_override', 'triggered_by_user_id' => $this->agent->id,
        ]);

        $this->board()->assertOk()->assertSee('Warmie', false)->assertSee('data-buyer-state="cold"', false);
    }

    public function test_a_status_the_agency_excludes_drops_the_buyer_off_the_list_at_once(): void
    {
        $this->excluded(['cold']);
        $contact = $this->buyer('Warmie', 'warm');
        $this->board()->assertOk()->assertSee('Warmie', false);

        $this->actingAs($this->agent)->patchJson(route('command-center.buyers.update-state', $contact), ['state' => 'cold'])->assertOk();

        $this->board()->assertOk()->assertDontSee('Warmie', false);
    }

    public function test_lost_needs_a_reason_exactly_as_on_the_pipeline_and_then_drops_the_buyer_by_default(): void
    {
        $contact = $this->buyer('Warmie', 'warm');

        // same validation as the board's Mark-Lost: no reason, no move
        $this->actingAs($this->agent)->post(route('command-center.buyers.mark-lost', $contact), ['notes' => 'x'])
            ->assertSessionHasErrors('reason_code');
        $this->assertSame('warm', $contact->fresh()->buyer_state);

        $this->actingAs($this->agent)->from(route('corex.core-matches.index'))
            ->post(route('command-center.buyers.mark-lost', $contact), [
                'reason_code' => 'bought_elsewhere', 'notes' => 'Went with a rival', 'outcome' => 'Said thanks but no',
            ])->assertRedirect(route('corex.core-matches.index'));

        $this->assertSame('lost', $contact->fresh()->buyer_state);
        $this->assertDatabaseHas('buyer_lost_records', [
            'contact_id' => $contact->id, 'reason_code' => 'bought_elsewhere', 'reason_label' => 'Bought through another agency',
            'notes' => 'Went with a rival', 'outcome' => 'Said thanks but no', 'recorded_by_user_id' => $this->agent->id, 'source' => 'manual',
        ]);
        $this->assertDatabaseHas('buyer_state_transitions', [
            'contact_id' => $contact->id, 'from_state' => 'warm', 'to_state' => 'lost', 'reason' => 'manual_override', 'triggered_by_user_id' => $this->agent->id,
        ]);

        $this->board()->assertOk()->assertDontSee('Warmie', false);
    }

    // ---- Scoping: a crafted request cannot move what the viewer cannot reach --

    public function test_an_own_scope_agent_cannot_move_a_colleagues_buyer_by_crafted_request(): void
    {
        DB::table('role_permissions')->insert([
            'role' => 'agent', 'permission_key' => 'contacts.view', 'agency_id' => $this->agency->id, 'scope' => 'own',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rival = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true]);
        $theirs = $this->buyer('Rivalbuyer', 'warm', 'sale', $rival);

        $this->actingAs($this->agent)->patchJson(route('command-center.buyers.update-state', $theirs), ['state' => 'cold'])->assertNotFound();
        $this->actingAs($this->agent)->post(route('command-center.buyers.mark-lost', $theirs), ['reason_code' => 'bought_elsewhere'])->assertNotFound();

        $this->assertSame('warm', $theirs->fresh()->buyer_state);
        $this->assertDatabaseMissing('buyer_lost_records', ['contact_id' => $theirs->id]);
    }

    public function test_another_agencys_buyer_cannot_be_moved(): void
    {
        $otherAgency = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::create(['agency_id' => $otherAgency->id, 'name' => 'Other']);
        $foreign = Contact::withoutGlobalScopes()->create([
            'agency_id' => $otherAgency->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Foreign', 'last_name' => 'Buyer',
            'is_buyer' => true, 'buyer_state' => 'warm',
        ]);

        $this->actingAs($this->agent)->patchJson(route('command-center.buyers.update-state', $foreign), ['state' => 'cold'])->assertNotFound();
        $this->actingAs($this->agent)->post(route('command-center.buyers.mark-lost', $foreign), ['reason_code' => 'x'])->assertNotFound();

        $this->assertSame('warm', Contact::withoutGlobalScopes()->find($foreign->id)->buyer_state);
    }
}

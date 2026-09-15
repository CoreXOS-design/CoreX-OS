<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use App\Services\BuyerStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-Core-Matches follow-up — Johan tested this on QA1 and the buyer stayed
 * on the board after being marked Lost. ContactMatchSetAsideOnBuyerLostTest
 * already proved the event/listener set set_aside_at correctly; that test
 * never touched the actual board route, which is exactly the gap that let
 * a genuinely broken screen ship with green tests. This asserts the real
 * HTTP response, not the column.
 */
final class ContactMatchLostRemovesFromBoardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;
    private ContactMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Lost Board Test Agency', 'slug' => 'lost-board-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'created_by_user_id' => $this->agent->id,
            'first_name' => 'AboutToBeLost', 'last_name' => 'Buyer', 'is_buyer' => true,
            'buyer_state' => 'warm',
        ]);
        $this->match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'name' => 'Test', 'listing_type' => 'sale', 'status' => 'active',
        ]);
    }

    public function test_buyer_appears_on_the_board_before_being_marked_lost(): void
    {
        $resp = $this->actingAs($this->agent)->get(route('corex.core-matches.index'));

        $resp->assertStatus(200);
        $resp->assertSee('AboutToBeLost', false);
    }

    public function test_marking_the_buyer_lost_removes_them_from_the_board(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'manual_override', $this->agent->id);

        $resp = $this->actingAs($this->agent)->get(route('corex.core-matches.index'));

        $resp->assertStatus(200);
        $resp->assertDontSee('AboutToBeLost', false);
    }

    public function test_restoring_off_lost_brings_the_buyer_back_to_the_board(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'manual_override', $this->agent->id);
        $this->actingAs($this->agent)->get(route('corex.core-matches.index'))->assertDontSee('AboutToBeLost', false);

        app(BuyerStateService::class)->transitionTo($this->contact, 'warm', 'manual_override', $this->agent->id);

        $resp = $this->actingAs($this->agent)->get(route('corex.core-matches.index'));
        $resp->assertStatus(200);
        $resp->assertSee('AboutToBeLost', false);
    }

    /**
     * The whole reason the read-side approach was chosen over the event:
     * the filter must read the resulting buyer_state, not care how the
     * buyer got there. A transition via 'auto_recompute' (the nightly
     * job's reason, distinct from a manual pipeline move) must remove the
     * buyer from the board exactly like 'manual_override' does above.
     */
    public function test_an_auto_recompute_lost_transition_removes_the_buyer_exactly_like_a_manual_one(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'auto_recompute');

        $resp = $this->actingAs($this->agent)->get(route('corex.core-matches.index'));

        $resp->assertStatus(200);
        $resp->assertDontSee('AboutToBeLost', false);
    }

    /** Johan: "any buyer moving to won or lost needs to update core matches." */
    public function test_marking_the_buyer_won_removes_them_from_the_board(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, BuyerStateService::WON, 'manual_override', $this->agent->id);

        $resp = $this->actingAs($this->agent)->get(route('corex.core-matches.index'));

        $resp->assertStatus(200);
        $resp->assertDontSee('AboutToBeLost', false);
    }
}

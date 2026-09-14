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
 * AT-Core-Matches, Task 6 — Buyer Pipeline "Lost" takes the buyer off the
 * Core Matches board (set aside, never deleted); moving off Lost brings
 * them back. BuyerStateService::transitionTo() writes via updateQuietly(),
 * which suppresses Eloquent model events — proves the events are fired
 * EXPLICITLY, not relying on an observer that would never see this change.
 */
final class ContactMatchSetAsideOnBuyerLostTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $agent;
    private Contact $contact;
    private ContactMatch $match;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Lost Test Agency', 'slug' => 'lost-test-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $branch->id,
            'created_by_user_id' => $this->agent->id,
            'first_name' => 'Lost', 'last_name' => 'Buyer', 'is_buyer' => true,
            'buyer_state' => 'warm',
        ]);
        $this->match = ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'agent_id' => $this->agent->id,
            'name' => 'Test', 'listing_type' => 'sale',
        ]);
    }

    public function test_transitioning_to_lost_sets_the_match_aside(): void
    {
        $this->assertNull($this->match->set_aside_at);

        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'manual_override', $this->agent->id);

        $this->match->refresh();
        $this->assertNotNull($this->match->set_aside_at);
    }

    public function test_transitioning_off_lost_restores_the_match(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'manual_override', $this->agent->id);
        $this->match->refresh();
        $this->assertNotNull($this->match->set_aside_at);

        app(BuyerStateService::class)->transitionTo($this->contact, 'warm', 'manual_override', $this->agent->id);

        $this->match->refresh();
        $this->assertNull($this->match->set_aside_at, 'must come back when the buyer does');
    }

    public function test_set_aside_match_is_not_deleted(): void
    {
        app(BuyerStateService::class)->transitionTo($this->contact, 'lost', 'manual_override', $this->agent->id);

        $this->assertDatabaseHas('contact_matches', ['id' => $this->match->id, 'deleted_at' => null]);
    }
}

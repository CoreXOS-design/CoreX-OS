<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Prod-promotion audit 2026-09-16, H2 — reassignment was inert: the board
 * scoped on created_by_user_id, reassignTo() moved only agent_id, and no
 * creation path stamped agent_id at all. ContactMatchReassignmentTest
 * proves the permission gate and the audit row; THIS proves the part that
 * actually matters to the agents — what each of them sees on the board
 * before and after a move — against the real HTTP route, plus the model
 * default that makes every new match owned from birth.
 */
final class ContactMatchReassignmentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $manager;
    private User $agentA;
    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency  = Agency::create(['name' => 'Reassign Visibility Co', 'slug' => 'rv-' . uniqid()]);
        $this->branch  = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->manager = $this->makeUser('branch_manager');
        $this->agentA  = $this->makeUser('agent');
        $this->agentB  = $this->makeUser('agent');
    }

    private function makeUser(string $role): User
    {
        return User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => $role,
            'is_active' => true,
        ]);
    }

    private function makeMatch(User $createdBy, string $buyerFirstName, array $overrides = []): ContactMatch
    {
        $contact = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $createdBy->id,
            'first_name'         => $buyerFirstName,
            'last_name'          => 'Buyer',
            'is_buyer'           => true,
            'buyer_state'        => 'warm',
            'phone'              => '083' . random_int(1000000, 9999999),
        ]);

        return ContactMatch::create(array_merge([
            'agency_id'          => $this->agency->id,
            'contact_id'         => $contact->id,
            'created_by_user_id' => $createdBy->id,
            'name'               => 'Test wishlist',
            'listing_type'       => 'sale',
            'status'             => ContactMatch::STATUS_ACTIVE,
        ], $overrides));
    }

    private function board(User $viewer, array $query = ['scope' => 'own'])
    {
        return $this->actingAs($viewer)->get(route('corex.core-matches.index', $query));
    }

    public function test_a_newly_created_match_is_owned_by_its_creator_when_no_agent_is_given(): void
    {
        $match = $this->makeMatch($this->agentA, 'Fresh');

        $this->assertSame($this->agentA->id, $match->fresh()->agent_id);
    }

    public function test_an_explicit_agent_id_is_never_overridden_by_the_creator_default(): void
    {
        $match = $this->makeMatch($this->agentA, 'Explicit', ['agent_id' => $this->agentB->id]);

        $this->assertSame($this->agentB->id, $match->fresh()->agent_id);
    }

    public function test_a_match_created_without_a_creator_stays_ownerless_rather_than_guessing(): void
    {
        $match = $this->makeMatch($this->agentA, 'Orphan', ['created_by_user_id' => null]);

        $this->assertNull($match->fresh()->agent_id);
    }

    public function test_the_creator_sees_the_buyer_on_their_own_board_before_any_reassignment(): void
    {
        $this->makeMatch($this->agentA, 'Movable');

        $this->board($this->agentA)->assertOk()->assertSee('Movable', false);
        $this->board($this->agentB)->assertOk()->assertDontSee('Movable', false);
    }

    public function test_after_reassignment_the_buyer_leaves_agent_a_board_and_appears_on_agent_b_board(): void
    {
        $match = $this->makeMatch($this->agentA, 'Movable');

        $match->reassignTo($this->agentB, $this->manager, 'Agent A is on leave.');

        $this->board($this->agentA)->assertOk()->assertDontSee('Movable', false);
        $this->board($this->agentB)->assertOk()->assertSee('Movable', false);
    }

    public function test_the_manager_agent_filter_follows_the_assigned_agent_not_the_creator(): void
    {
        $match = $this->makeMatch($this->agentA, 'Filtered');
        $match->reassignTo($this->agentB, $this->manager, 'Handover.');

        $this->board($this->manager, ['scope' => 'agency', 'agent_id' => $this->agentA->id])
            ->assertOk()->assertDontSee('Filtered', false);
        $this->board($this->manager, ['scope' => 'agency', 'agent_id' => $this->agentB->id])
            ->assertOk()->assertSee('Filtered', false);
    }
}

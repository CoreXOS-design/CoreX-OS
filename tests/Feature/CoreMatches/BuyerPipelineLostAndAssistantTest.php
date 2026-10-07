<?php

declare(strict_types=1);

namespace Tests\Feature\CoreMatches;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Two Buyer Pipeline board fixes (Johan, 2026-10-07):
 *
 *  1. Drag-to-Lost opens the shared Mark-Lost dialog on the board itself (reason required, same
 *     endpoint + validation as everywhere); the state endpoint no longer accepts Lost at all, so a buyer
 *     cannot end up Lost without a reason; nothing is posted until a reason is saved, so cancelling
 *     leaves the card where it was.
 *  2. The board's move actions, the Core Matches "Update buyer pipeline" button and "Move buyer" apply
 *     the existing assistant rule (AuthorizesContactAccess): an assistant may SEE a colleague's buyer
 *     but not move, lose or reassign them.
 *
 * Spec: .ai/specs/buyer-pipeline.md, .ai/specs/core-matches.md.
 */
final class BuyerPipelineLostAndAssistantTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;      // the assistant's agent
    private User $agentB;      // a colleague
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agency = Agency::create(['name' => 'Pipeline Lost Agency', 'slug' => 'pl-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agentA    = $this->user('Sarah Nkosi', 'agent');
        $this->agentB    = $this->user('Pieter van Wyk', 'agent');
        $this->assistant = $this->user('Thandi Mokoena', 'assistant', true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        // The agent sees the whole agency's contacts; the assistant inherits that breadth.
        foreach (['access_contacts' => null, 'contacts.view' => 'all', 'access_core_matches' => null,
                  'core_matches.view' => 'all', 'core_matches.all_view' => null, 'core_matches.reassign' => null] as $key => $scope) {
            $this->grant($key, $scope);
        }

        DB::table('agency_lost_deal_reasons')->insert([
            'agency_id' => $this->agency->id, 'code' => 'bought_elsewhere', 'label' => 'Bought through another agency',
            'category' => 'competition', 'applies_to_buyers' => 1, 'active' => 1, 'display_order' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    // ── 1. Drag-to-Lost ──────────────────────────────────────────────────

    public function test_the_board_opens_the_shared_lost_dialog_instead_of_sending_the_agent_to_the_buyer_page(): void
    {
        $this->buyer('Warmie', 'warm', $this->agentA);

        $html = $this->actingAs($this->agentA)->get(route('command-center.buyers.pipeline'))->assertOk()->getContent();

        $this->assertStringContainsString('data-mark-lost-form', $html, 'the shared Mark-Lost dialog is on the board');
        $this->assertStringContainsString('open-pipeline-lost', $html);
        $this->assertStringContainsString('Bought through another agency', $html, 'the agency reason list');
        $this->assertStringNotContainsString('?action=mark-lost', $html, 'the dead redirect is gone');
        $this->assertStringContainsString("/mark-lost'", $html, 'drop targets the buyer\'s mark-lost endpoint');
    }

    public function test_the_state_endpoint_no_longer_accepts_lost_so_a_buyer_cannot_be_lost_without_a_reason(): void
    {
        $buyer = $this->buyer('Warmie', 'warm', $this->agentA);

        $this->actingAs($this->agentA)->patchJson(route('command-center.buyers.update-state', $buyer), ['state' => 'lost'])
            ->assertStatus(422)->assertJsonValidationErrors('state');

        $this->assertSame('warm', $buyer->fresh()->buyer_state);
        $this->assertDatabaseMissing('buyer_state_transitions', ['contact_id' => $buyer->id, 'to_state' => 'lost']);
        $this->assertDatabaseMissing('buyer_lost_records', ['contact_id' => $buyer->id]);
    }

    public function test_marking_lost_needs_the_reason_and_cancelling_posts_nothing(): void
    {
        $buyer = $this->buyer('Warmie', 'warm', $this->agentA);

        // Dialog submitted without a reason → refused, buyer untouched.
        $this->actingAs($this->agentA)->post(route('command-center.buyers.mark-lost', $buyer), ['notes' => 'x'])
            ->assertSessionHasErrors('reason_code');
        $this->assertSame('warm', $buyer->fresh()->buyer_state);
        $this->assertDatabaseMissing('buyer_lost_records', ['contact_id' => $buyer->id]);

        // (Cancel = nothing is posted at all: the card never left its column.)
        $this->assertSame('warm', $buyer->fresh()->buyer_state);

        // With a reason → Lost, recorded, back to the board the agent was on.
        $this->actingAs($this->agentA)->from(route('command-center.buyers.pipeline'))
            ->post(route('command-center.buyers.mark-lost', $buyer), ['reason_code' => 'bought_elsewhere', 'notes' => 'Rival', 'outcome' => 'Thanks, no'])
            ->assertRedirect(route('command-center.buyers.pipeline'));

        $this->assertSame('lost', $buyer->fresh()->buyer_state);
        $this->assertDatabaseHas('buyer_lost_records', ['contact_id' => $buyer->id, 'reason_code' => 'bought_elsewhere', 'recorded_by_user_id' => $this->agentA->id]);
    }

    // ── 2. The assistant rule on board moves ─────────────────────────────

    public function test_an_assistant_cannot_move_a_colleagues_buyer_on_the_board_but_can_move_their_agents(): void
    {
        $theirs = $this->buyer('Colleague', 'warm', $this->agentB);
        $mine   = $this->buyer('AgentsOwn', 'warm', $this->agentA);

        $this->actingAs($this->assistant)->patchJson(route('command-center.buyers.update-state', $theirs), ['state' => 'cold'])->assertForbidden();
        $this->assertSame('warm', $theirs->fresh()->buyer_state);
        $this->assertDatabaseMissing('buyer_state_transitions', ['contact_id' => $theirs->id, 'to_state' => 'cold']);

        $this->actingAs($this->assistant)->patchJson(route('command-center.buyers.update-state', $mine), ['state' => 'cold'])->assertOk();
        $this->assertSame('cold', $mine->fresh()->buyer_state);
    }

    public function test_an_assistant_cannot_mark_a_colleagues_buyer_lost_but_can_for_their_agents(): void
    {
        $theirs = $this->buyer('Colleague', 'warm', $this->agentB);
        $mine   = $this->buyer('AgentsOwn', 'warm', $this->agentA);

        $this->actingAs($this->assistant)->post(route('command-center.buyers.mark-lost', $theirs), ['reason_code' => 'bought_elsewhere'])->assertForbidden();
        $this->assertSame('warm', $theirs->fresh()->buyer_state);
        $this->assertDatabaseMissing('buyer_lost_records', ['contact_id' => $theirs->id]);

        $this->actingAs($this->assistant)->post(route('command-center.buyers.mark-lost', $mine), ['reason_code' => 'bought_elsewhere'])->assertRedirect();
        $this->assertSame('lost', $mine->fresh()->buyer_state);
    }

    public function test_an_assistant_cannot_move_a_colleagues_buyer_to_another_agent(): void
    {
        $theirs = $this->buyer('Colleague', 'warm', $this->agentB);
        $search = ContactMatch::where('contact_id', $theirs->id)->firstOrFail();

        $this->actingAs($this->assistant)->post(route('corex.core-matches.reassign-buyer', $theirs), ['to_agent_id' => $this->agentA->id, 'reason' => 'Test'])->assertForbidden();
        $this->actingAs($this->assistant)->post(route('corex.core-matches.reassign', $search), ['to_agent_id' => $this->agentA->id, 'reason' => 'Test'])->assertForbidden();

        $this->assertSame($this->agentB->id, (int) $theirs->fresh()->agent_id);
        $this->assertSame($this->agentB->id, (int) $search->fresh()->agent_id);
        $this->assertDatabaseCount('contact_match_reassignments', 0);
    }

    public function test_the_board_leaves_off_drag_and_move_for_an_assistant_on_a_colleagues_buyer_only(): void
    {
        $theirs = $this->buyer('Colleague', 'warm', $this->agentB);
        $mine   = $this->buyer('AgentsOwn', 'warm', $this->agentA);

        $html = $this->actingAs($this->assistant)->get(route('command-center.buyers.pipeline', ['scope' => 'agency']))->assertOk()->getContent();

        $this->assertStringContainsString('AgentsOwn', $html);
        $this->assertStringContainsString('Colleague', $html, 'the assistant can still SEE the colleague\'s buyer');
        $this->assertSame(1, substr_count($html, 'draggable="true"'), 'only the agent\'s own buyer can be dragged');
        $this->assertStringContainsString(route('corex.core-matches.reassign-buyer', $mine), str_replace('\\/', '/', $html));
        $this->assertStringNotContainsString(route('corex.core-matches.reassign-buyer', $theirs), str_replace('\\/', '/', $html));
    }

    public function test_core_matches_offers_the_buttons_only_for_buyers_the_assistant_may_move(): void
    {
        $theirs = $this->buyer('Colleague', 'warm', $this->agentB);
        $mine   = $this->buyer('AgentsOwn', 'warm', $this->agentA);

        $html = str_replace('\\/', '/', $this->actingAs($this->assistant)
            ->get(route('corex.core-matches.index', ['scope' => 'agency']))->assertOk()->getContent());

        $this->assertStringContainsString('AgentsOwn', $html, 'the board lists the buyers');
        $this->assertStringContainsString('Colleague', $html);
        $this->assertStringContainsString(route('command-center.buyers.update-state', $mine), $html);
        $this->assertStringNotContainsString(route('command-center.buyers.update-state', $theirs), $html);
        $this->assertStringContainsString(route('corex.core-matches.reassign-buyer', $mine), $html);
        $this->assertStringNotContainsString(route('corex.core-matches.reassign-buyer', $theirs), $html);
    }

    public function test_a_normal_agent_is_unaffected_by_the_assistant_rule(): void
    {
        $colleagues = $this->buyer('Colleague', 'warm', $this->agentB);
        $this->grantAgent('contacts.view', 'all');

        $this->actingAs($this->agentA)->patchJson(route('command-center.buyers.update-state', $colleagues), ['state' => 'cold'])->assertOk();
        $this->assertSame('cold', $colleagues->fresh()->buyer_state);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function user(string $name, string $role, bool $assistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $assistant,
        ]);
    }

    private function grant(string $key, ?string $scope): void
    {
        $this->grantAgent($key, $scope);
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
    }

    private function grantAgent(string $key, ?string $scope): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
        PermissionService::clearCache();
    }

    private function buyer(string $first, string $state, User $owner): Contact
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'created_by_user_id' => $owner->id, 'agent_id' => $owner->id,
            'first_name' => $first, 'last_name' => 'Pipe', 'is_buyer' => true, 'buyer_state' => $state,
        ]);
        ContactMatch::create([
            'agency_id' => $this->agency->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $owner->id, 'agent_id' => $owner->id,
            'name' => 'Wishlist', 'listing_type' => 'sale', 'status' => ContactMatch::STATUS_ACTIVE,
            'price_min' => 1_000_000, 'price_max' => 2_000_000, 'beds_min' => 2, 'property_types' => ['House'],
        ]);
        Contact::withoutGlobalScopes()->whereKey($contact->id)->update(['buyer_state' => $state]);

        return $contact->fresh();
    }
}

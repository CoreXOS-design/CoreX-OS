<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 (spec §7.2) — an assistant may OPEN (view) any listing their assigned agent can see, at
 * the agent's full breadth (branch/all), but may only EDIT/DELETE the agent's OWN listings.
 *
 * WHY THIS FILE EXISTS. PropertyController::show() called authorizeProperty() with the MUTATION
 * guard, which clamps an assistant to 'own' — so an assistant clicking a colleague's listing their
 * agent could plainly see got a bare 403 ("You don't have permission to access this page"). The
 * read path must use the VIEW breadth; only the write path pins to own. This mirrors
 * AuthorizesDealAccess::authorizeDeal($forEdit) exactly, so LIST visibility and OPEN can never
 * disagree — the assistant sees the listing and is not then 403'd trying to open it.
 */
final class AssistantPropertyViewScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;     // the assigned agent
    private User $agentB;     // an unrelated agent in the same branch
    private User $assistant;  // assigned to agentA
    private AssistantAssignment $assignment;
    private Property $propA;  // owned by agentA
    private Property $propB;  // owned by agentB

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agentA    = $this->makeUser('Sarah Nkosi', 'agent');
        $this->agentB    = $this->makeUser('Pieter van Wyk', 'agent');
        $this->assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $this->propA = $this->propertyFor($this->agentA, 'Marine Drive');
        $this->propB = $this->propertyFor($this->agentB, 'Panorama Parade');

        // The AGENT is branch-scope on properties — so the agent themselves may edit a colleague's
        // listing in the branch. This is what proves the assistant is strictly NARROWER, not equal.
        $this->grantRole('access_properties');
        $this->grantRole('properties.view', 'branch'); // getDataScope('properties') reads this scope

        $this->reset();
    }

    /**
     * THE BUG. The assistant may VIEW agentB's listing (their agent's branch breadth) but may NOT
     * edit it — pinned to the agent's own book. Before the fix, the VIEW was a 403 too.
     */
    public function test_an_assistant_can_view_but_not_edit_another_agents_listing(): void
    {
        // The agent hands the module over at branch breadth.
        $this->grantAssistant('access_properties');
        $this->grantAssistant('properties.view', 'branch');
        $this->reset();

        // VIEW agentB's listing — allowed (was wrongly 403 before the fix), and rendered read-only:
        // the page carries the "View only" lock so no edit affordance is shown.
        $this->actingAs($this->assistant)
            ->get(route('corex.properties.show', $this->propB))
            ->assertOk()
            ->assertSee('View only');

        // EDIT agentB's listing — refused: an assistant edits only the agent's own listings.
        $this->actingAs($this->assistant)
            ->put(route('corex.properties.update', $this->propB), [])
            ->assertForbidden();

        // The agent's OWN listing — the assistant's whole job: view AND edit both pass authorization
        // (the update may 302 back on validation, but it is never a 403). No read-only lock here.
        $this->actingAs($this->assistant)
            ->get(route('corex.properties.show', $this->propA))
            ->assertOk()
            ->assertDontSee('View only');
        $this->assertNotSame(
            403,
            $this->actingAs($this->assistant)->put(route('corex.properties.update', $this->propA), [])->status(),
            'The assistant must be authorized to edit their own agent\'s listing.'
        );
    }

    /**
     * The assistant is strictly narrower than the agent: the AGENT (branch scope) CAN edit the
     * colleague's listing the assistant cannot. Proves the pin is on the assistant, not the module.
     */
    public function test_the_agent_themselves_can_still_edit_the_branch_colleagues_listing(): void
    {
        $this->actingAs($this->agentA)
            ->get(route('corex.properties.show', $this->propB))
            ->assertOk();

        $this->assertNotSame(
            403,
            $this->actingAs($this->agentA)->put(route('corex.properties.update', $this->propB), [])->status(),
            'A branch-scope agent must be authorized to edit a branch colleague\'s listing.'
        );
    }

    /**
     * When the agent is only 'own'-scope, the assistant sees exactly the agent's own listings — and
     * is still 403'd opening an unrelated agent's listing, because the agent cannot see it either.
     */
    public function test_an_own_scope_agents_assistant_cannot_view_an_unrelated_listing(): void
    {
        $this->grantRole('properties.view', 'own');
        $this->grantAssistant('access_properties');
        $this->grantAssistant('properties.view', 'own');
        $this->reset();

        $this->actingAs($this->assistant)
            ->get(route('corex.properties.show', $this->propA))
            ->assertOk();

        $this->actingAs($this->assistant)
            ->get(route('corex.properties.show', $this->propB))
            ->assertForbidden();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function propertyFor(User $agent, string $street): Property
    {
        return Property::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'agent_id' => $agent->id, 'title' => $street, 'street_name' => $street,
            'street_number' => '14', 'suburb' => 'Margate', 'city' => 'Margate', 'status' => 'active',
        ]);
    }

    private function grantRole(string $key, ?string $scope = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
    }

    private function grantAssistant(string $key, ?string $scope = null): void
    {
        $this->grantRole($key, $scope); // the assistant can never exceed the agent's ceiling
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }
}

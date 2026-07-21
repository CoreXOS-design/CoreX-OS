<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — an assistant owns NO data of their own, so every data list loads the AGENT they work
 * under, and an assistant is never a selectable AGENT in an agent picker.
 */
final class AssistantAgentDefaultingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;     // the assigned agent
    private User $agentB;     // an unrelated agent
    private User $assistant;  // assigned to agentA
    private AssistantAssignment $assignment;

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
    }

    /**
     * The core requirement: an own-scope assistant's Properties list loads their AGENT's listings —
     * not the assistant's own (empty) book, and not another agent's.
     */
    public function test_properties_list_defaults_to_the_assigned_agents_listings(): void
    {
        $this->grant('access_properties');
        $this->grant('properties.view', 'own');
        $this->reset();

        $mine   = $this->propertyFor($this->agentA, 'Marine Drive');   // the assigned agent's
        $theirs = $this->propertyFor($this->agentB, 'Panorama Parade'); // an unrelated agent's

        $this->actingAs($this->assistant)
            ->get(route('corex.properties.index'))
            ->assertOk()
            ->assertSee('Marine Drive')
            ->assertDontSee('Panorama Parade');
    }

    /**
     * With a wide-scope agent the picker is shown — and the assistant must NOT appear in it as a
     * selectable agent, while the list still defaults to the assigned agent.
     */
    public function test_assistant_is_not_listed_as_a_selectable_agent(): void
    {
        $this->grant('access_properties');
        $this->grant('properties.view', 'all'); // agentA is agency-wide → picker shown
        $this->reset();

        $response = $this->actingAs($this->assistant)
            ->get(route('corex.properties.index'))
            ->assertOk();

        // The agent picker carries the agents as JSON — the agent is there, the assistant is not.
        $response->assertSee($this->agentA->email);
        $response->assertDontSee($this->assistant->email);
    }

    /**
     * Contacts mirror the same defaulting: an own-scope assistant's Contacts list loads the agent's
     * book, not the assistant's empty one.
     */
    public function test_contacts_list_defaults_to_the_assigned_agents_contacts(): void
    {
        $this->grant('access_contacts');
        $this->grant('contacts.view', 'own');
        $this->reset();

        $mine   = $this->contactFor($this->agentA, 'Alice', 'Owner');
        $theirs = $this->contactFor($this->agentB, 'Bob', 'Buyer');

        $this->actingAs($this->assistant)
            ->get(route('corex.contacts.index'))
            ->assertOk()
            ->assertSee('Alice')
            ->assertDontSee('Bob');
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

    private function contactFor(User $agent, string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => $first, 'last_name' => $last,
            'created_by_user_id' => $agent->id, 'agent_id' => $agent->id,
        ]);
    }

    private function grant(string $key, ?string $scope = null): void
    {
        RolePermission::updateOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
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

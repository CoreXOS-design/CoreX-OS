<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 spec §7.2 — an assistant SEES every contact the assigned agent sees, but may EDIT only
 * the agent's own contacts. Here the agent has agency-wide ('all') contacts scope, so the
 * assistant can open a colleague's contact — and is blocked from mutating it. Keyed on
 * created_by_user_id, matching ContactScope::applyAssistant() so LIST and MUTATE stay consistent.
 */
final class AssistantContactEditScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;      // the assigned agent — agency-wide contacts scope
    private User $agentB;      // an unrelated agent
    private User $assistant;   // assigned to agentA
    private AssistantAssignment $assignment;
    private Contact $contactA; // created by agentA
    private Contact $contactB; // created by agentB

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

        $this->contactA = $this->makeContact($this->agentA->id, 'Alice', 'Owner');
        $this->contactB = $this->makeContact($this->agentB->id, 'Bob', 'Buyer');

        // The agent sees ALL agency contacts; the matrix hands the full width over.
        $this->grant('access_contacts');
        $this->grant('contacts.view', 'all');

        $this->reset();
    }

    public function test_assistant_can_view_but_not_edit_another_agents_contact(): void
    {
        // Sanity: the assistant's VIEW breadth really is agency-wide (inherited from the agent).
        $this->assertSame('all', PermissionService::getDataScope($this->assistant, 'contacts'));

        // VIEW: the assistant may OPEN agentB's contact — they see what their agent sees.
        $this->actingAs($this->assistant)
            ->get(route('corex.contacts.show', $this->contactB))
            ->assertOk();

        // MUTATE: but may NOT delete/edit agentB's contact — pinned to the agent's own book.
        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $this->contactB))
            ->assertForbidden();
        $this->assertNotSoftDeleted('contacts', ['id' => $this->contactB->id]);

        // ...and CAN mutate the assigned agent's own contact.
        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $this->contactA))
            ->assertRedirect();
        $this->assertSoftDeleted('contacts', ['id' => $this->contactA->id]);
    }

    /**
     * AT-267 (Johan 2026-07-21) — an UNOWNED contact (no primary AND no secondary agent) is fair
     * game: an assistant may edit a contact nobody is responsible for. In practice these are
     * non-auth imports/webhooks — ContactObserver backfills agent_id from the creator on any
     * app-captured contact, so a genuine orphan has no creator either (created_by null keeps
     * agent_id null past the observer).
     */
    public function test_assistant_can_edit_an_unowned_contact_with_no_linked_agent(): void
    {
        $orphan = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => null, // no creator → observer leaves agent_id null
            'agent_id'           => null, // ...no agent is linked
            'second_agent_id'    => null,
            'first_name'         => 'Nomsa',
            'last_name'          => 'Unassigned',
            'email'              => 'nomsa.unassigned@example.co.za',
        ]);
        // Guard the premise: the row really is agentless (observer did not backfill it).
        $this->assertNull($orphan->fresh()->agent_id);

        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $orphan))
            ->assertRedirect();
        $this->assertSoftDeleted('contacts', ['id' => $orphan->id]);
    }

    /**
     * "No linked agent" means BOTH slots empty. A contact co-listed to another agent (second agent
     * only) still HAS a linked agent, so the assistant stays blocked.
     */
    public function test_a_contact_with_only_a_second_agent_is_still_protected(): void
    {
        $coListed = Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => null,               // avoid the observer's agent_id backfill
            'agent_id'           => null,
            'second_agent_id'    => $this->agentB->id,  // a linked agent exists
            'first_name'         => 'Sipho',
            'last_name'          => 'Colisted',
            'email'              => 'sipho.colisted@example.co.za',
        ]);

        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $coListed))
            ->assertForbidden();
        $this->assertNotSoftDeleted('contacts', ['id' => $coListed->id]);
    }

    public function test_a_non_assistant_with_wide_scope_is_unaffected(): void
    {
        // A plain agency-wide user (the agent) may delete any contact — the guard returns early
        // for non-assistants, so the pre-existing behaviour is unchanged.
        $this->actingAs($this->agentA)
            ->delete(route('corex.contacts.destroy', $this->contactB))
            ->assertRedirect();
        $this->assertSoftDeleted('contacts', ['id' => $this->contactB->id]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function makeContact(int $createdBy, string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'created_by_user_id' => $createdBy,
            'agent_id'           => $createdBy,
            'first_name'         => $first,
            'last_name'          => $last,
            'email'              => strtolower($first . '.' . $last) . '@example.co.za',
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

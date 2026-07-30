<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantLinkedAgent;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 multi-agent addendum, Prompt D — the admin CRUD for Sub-Agent links.
 *
 * Admin/super_admin manage this exclusively (M2) — this file proves the permission gate, the
 * full CRUD (link/unlink/restore, BUILD_STANDARD §1), and the guardrails from spec §5: an owner,
 * an assistant, the Main Agent themselves, and an already-linked agent are all ineligible
 * candidates, both in the picker and re-validated server-side against a hand-crafted POST.
 */
final class AssistantLinkedAgentAdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private User $mainAgent;
    private User $candidate;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        foreach (['admin', 'agent', 'assistant'] as $r) {
            Role::create(['name' => $r, 'label' => ucfirst($r), 'agency_id' => $this->agency->id]);
        }

        $this->admin     = $this->makeUser('Johan Reichel', 'admin');
        $this->mainAgent = $this->makeUser('Sarah Nkosi', 'agent');
        $this->candidate = $this->makeUser('Pieter van Wyk', 'agent');
        $assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->mainAgent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        RolePermission::create([
            'role' => 'admin', 'permission_key' => 'assistants.manage_linked_agents',
            'agency_id' => $this->agency->id,
        ]);

        $this->reset();
    }

    public function test_admin_can_link_a_sub_agent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $this->candidate->id,
            ])
            ->assertRedirect(route('admin.assistants.show', $this->assignment));

        $this->assertDatabaseHas('assistant_linked_agents', [
            'assistant_assignment_id' => $this->assignment->id,
            'agent_user_id'           => $this->candidate->id,
            'added_by_user_id'        => $this->admin->id,
        ]);
    }

    public function test_a_user_without_the_permission_is_forbidden(): void
    {
        $this->actingAs($this->mainAgent) // the Main Agent themselves — NOT admin (M2)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $this->candidate->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('assistant_linked_agents', [
            'assistant_assignment_id' => $this->assignment->id,
            'agent_user_id'           => $this->candidate->id,
        ]);
    }

    public function test_an_owner_cannot_be_linked(): void
    {
        Role::forceCreate(['name' => 'test-owner', 'label' => 'Owner', 'agency_id' => null, 'is_owner' => true]);
        $owner = $this->makeUser('Owner Person', 'test-owner');

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $owner->id,
            ])
            ->assertSessionHasErrors('agent_user_id');

        $this->assertDatabaseMissing('assistant_linked_agents', ['agent_user_id' => $owner->id]);
    }

    public function test_an_assistant_cannot_be_linked(): void
    {
        $otherAssistant = $this->makeUser('Rajesh Naidoo', 'assistant', isAssistant: true);

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $otherAssistant->id,
            ])
            ->assertSessionHasErrors('agent_user_id');
    }

    public function test_the_main_agent_cannot_be_linked_as_their_own_sub_agent(): void
    {
        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $this->mainAgent->id,
            ])
            ->assertSessionHasErrors('agent_user_id');
    }

    public function test_an_already_linked_agent_cannot_be_linked_again(): void
    {
        AssistantLinkedAgent::create([
            'agency_id' => $this->agency->id, 'assistant_assignment_id' => $this->assignment->id,
            'agent_user_id' => $this->candidate->id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.store', $this->assignment), [
                'agent_user_id' => $this->candidate->id,
            ])
            ->assertSessionHasErrors('agent_user_id');
    }

    public function test_admin_can_unlink_and_restore(): void
    {
        $link = AssistantLinkedAgent::create([
            'agency_id' => $this->agency->id, 'assistant_assignment_id' => $this->assignment->id,
            'agent_user_id' => $this->candidate->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.assistants.linked-agents.destroy', [$this->assignment, $link]))
            ->assertRedirect(route('admin.assistants.show', $this->assignment));

        $this->assertSoftDeleted('assistant_linked_agents', ['id' => $link->id]);

        $this->actingAs($this->admin)
            ->post(route('admin.assistants.linked-agents.restore', [$this->assignment, $link->id]))
            ->assertRedirect(route('admin.assistants.show', $this->assignment));

        $this->assertNotSoftDeleted('assistant_linked_agents', ['id' => $link->id]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }
}

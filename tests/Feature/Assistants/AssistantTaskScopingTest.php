<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\CommandCenter\CommandTask;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 H1 — Command Center task mutators bound a task by id with no owner check → any user could
 * edit/reassign/delete ANY task in the agency (task hijack). The guard pins an assistant to the
 * assigned agent's task list.
 */
final class AssistantTaskScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;
    private User $agentB;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agentA    = $this->makeUser('Sarah', 'agent');
        $this->agentB    = $this->makeUser('Pieter', 'agent');
        $this->assistant = $this->makeUser('Thandi', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->agentA->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_assistant_cannot_edit_or_delete_another_agents_task(): void
    {
        foreach (['command_center.tasks.view', 'command_center.tasks.edit', 'command_center.tasks.create'] as $k) {
            $this->grant($k, $k === 'command_center.tasks.view' ? 'own' : null);
        }
        $mine   = $this->taskFor($this->agentA->id);
        $theirs = $this->taskFor($this->agentB->id);
        $this->reset();

        // agentB's task — refused on edit and delete.
        $this->actingAs($this->assistant)
            ->put(route('command-center.tasks.update', $theirs->id), ['title' => 'Hijacked'])
            ->assertForbidden();
        $this->actingAs($this->assistant)
            ->delete(route('command-center.tasks.destroy', $theirs->id))
            ->assertForbidden();
        $this->assertDatabaseHas('command_tasks', ['id' => $theirs->id, 'title' => $theirs->title]);

        // the assigned agent's own task — authorized.
        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->put(route('command-center.tasks.update', $mine->id), ['title' => 'Updated by assistant'])->status());
    }

    /**
     * AT-267 ownership attribution — a task an assistant creates is filed under the AGENT, never the
     * assistant (and never a caller-supplied assigned_to).
     */
    public function test_a_task_an_assistant_creates_is_filed_under_the_agent(): void
    {
        $this->grant('command_center.tasks.view', 'own');
        $this->grant('command_center.tasks.create');
        $this->reset();

        $this->actingAs($this->assistant)
            ->post(route('command-center.tasks.store'), [
                'title' => 'Follow up seller', 'task_type' => 'custom',
                'assigned_to' => $this->agentB->id, // a hostile attempt to file it elsewhere
            ]);

        $this->assertDatabaseHas('command_tasks', ['title' => 'Follow up seller', 'assigned_to' => $this->agentA->id]);
        $this->assertDatabaseMissing('command_tasks', ['title' => 'Follow up seller', 'assigned_to' => $this->assistant->id]);
        $this->assertDatabaseMissing('command_tasks', ['title' => 'Follow up seller', 'assigned_to' => $this->agentB->id]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name' => $name, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'role' => $role, 'is_active' => true, 'is_assistant' => $isAssistant,
        ]);
    }

    private function taskFor(int $assignedTo): CommandTask
    {
        return CommandTask::create([
            'agency_id'   => $this->agency->id,
            'branch_id'   => $this->branch->id,
            'title'       => 'Task ' . uniqid(),
            'task_type'   => 'custom',
            'assigned_to' => $assignedTo,
            'assigned_by' => $assignedTo,
            'status'      => 'todo',
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

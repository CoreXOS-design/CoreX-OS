<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 audit polish:
 *  - E1: deactivating an agent persists a FREEZE on their assistant assignments (reversible), on
 *    top of the resolver's live !is_active check.
 */
final class AssistantLifecyclePolishTest extends TestCase
{
    use RefreshDatabase;

    public function test_deactivating_an_agent_freezes_and_reactivating_thaws_their_assignments(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);

        $agent     = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $assistant = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $assignment = AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        // Deactivate the agent → assignment frozen.
        $agent->update(['is_active' => false]);
        $assignment->refresh();
        $this->assertSame(AssistantAssignment::STATUS_SUSPENDED, $assignment->status);
        $this->assertSame('agent_deactivated', $assignment->suspend_reason);

        // Reactivate the agent → the auto-freeze is undone.
        $agent->update(['is_active' => true]);
        $assignment->refresh();
        $this->assertSame(AssistantAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertNull($assignment->suspend_reason);
    }

    public function test_a_manual_revoke_is_not_thawed_by_agent_reactivation(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        $agent     = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $assistant = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $assignment = AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id,
            'status' => AssistantAssignment::STATUS_SUSPENDED, 'suspend_reason' => 'manual',
        ]);

        // Deactivating then reactivating the agent must NOT resurrect a manually-suspended assignment.
        $agent->update(['is_active' => false]);
        $agent->update(['is_active' => true]);
        $assignment->refresh();
        $this->assertSame(AssistantAssignment::STATUS_SUSPENDED, $assignment->status);
        $this->assertSame('manual', $assignment->suspend_reason);
    }
}

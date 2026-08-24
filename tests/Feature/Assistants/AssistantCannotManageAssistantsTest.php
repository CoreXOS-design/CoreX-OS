<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 H2 — an assistant may NEVER manage assistants. The admin/assistants routes gate on
 * hasPermission('assistants.*'), but those keys can seed ON for an assistant of an admin/BM agent
 * (the 'assistants' section was missing from admin_default_off_sections), letting the assistant
 * reassign THEMSELVES to a higher agent to widen their ceiling. deny_assistant now blocks the whole
 * surface regardless of matrix, and the config default-off is the second layer.
 */
final class AssistantCannotManageAssistantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_assistant_is_blocked_from_the_assistant_management_routes(): void
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $agency->id]);

        $agent     = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent', 'is_active' => true]);
        $assistant = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'assistant', 'is_active' => true, 'is_assistant' => true]);

        $assignment = AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        PermissionService::clearCache();
        User::flushAssistantsEnabledCache();

        // deny_assistant redirects an assistant away from an agent-personal/admin surface — it never
        // reaches the controller, so the reassignment cannot execute.
        $this->actingAs($assistant)
            ->post(route('admin.assistants.reassign', $assignment), ['agent_user_id' => $agent->id])
            ->assertRedirect(route('agent.portal'));

        $this->actingAs($assistant)
            ->post(route('admin.assistants.store'), [])
            ->assertRedirect(route('agent.portal'));

        $this->actingAs($assistant)
            ->post(route('admin.assistants.revoke', $assignment), [])
            ->assertRedirect(route('agent.portal'));

        // The assignment is untouched — still active, still pointed at the same agent.
        $assignment->refresh();
        $this->assertSame(AssistantAssignment::STATUS_ACTIVE, $assignment->status);
        $this->assertSame($agent->id, (int) $assignment->agent_user_id);
    }

    public function test_the_assistants_section_defaults_off_in_the_matrix_config(): void
    {
        $this->assertContains('assistants', config('assistants.admin_default_off_sections'));
    }
}

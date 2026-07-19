<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\AssistantAssignment;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 V2 — the behaviour panel on the agent's assistant control page. The agent toggles
 * can-edit-delete / attribution / notify; ownership is always the agent (not a toggle).
 */
final class AssistantControlSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'assistants_enabled' => true]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $assistant = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'assistant',
            'is_active' => true, 'is_assistant' => true,
        ]);
        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $this->agent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    public function test_a_fresh_assignment_has_the_safe_default_settings(): void
    {
        $this->assertTrue($this->assignment->can_manage_my_records, 'edit/delete on by default');
        $this->assertTrue($this->assignment->show_attribution, 'attribution on by default');
        $this->assertFalse($this->assignment->notify_on_action, 'notify off by default (quiet)');
    }

    public function test_the_agent_can_change_the_behaviour_toggles(): void
    {
        $this->actingAs($this->agent)
            ->postJson(route('agent.assistants.matrix.save', $this->assignment), [
                'permissions' => [],
                'settings'    => [
                    'can_manage_my_records' => '0',
                    'show_attribution'      => '1',
                    'notify_on_action'      => '1',
                ],
            ])
            ->assertSuccessful();

        $this->assignment->refresh();
        $this->assertFalse($this->assignment->can_manage_my_records);
        $this->assertTrue($this->assignment->show_attribution);
        $this->assertTrue($this->assignment->notify_on_action);
    }

    public function test_a_permissions_only_save_does_not_wipe_the_settings(): void
    {
        // §6.1 — a request without the settings panel must leave the settings alone.
        $this->assignment->forceFill(['notify_on_action' => true])->save();

        $this->actingAs($this->agent)
            ->postJson(route('agent.assistants.matrix.save', $this->assignment), ['permissions' => []])
            ->assertSuccessful();

        $this->assertTrue($this->assignment->refresh()->notify_on_action, 'settings survive a permissions-only save');
    }
}

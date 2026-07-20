<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-267 — an assistant (and any 'own'-scope user) may open/mutate ONLY the deals their data
 * identity is linked to in deal_user, never a different agent's deal by id.
 *
 * WHY THIS FILE EXISTS. Deal edits were the one mutation surface with no per-record guard:
 * DealController::update/quickUpdate/edit/settle checked only the permission KEY, and
 * Deal::scopeVisibleTo() is a LOCAL scope that never runs on route-model binding. So list
 * visibility was correctly scoped while a hand-typed /admin/deals/{id}/edit opened ANY deal in
 * the agency — an assistant could edit another agent's deal, and an 'own'-scope agent a
 * colleague's. AuthorizesDealAccess closes it, mirroring AuthorizesPropertyAccess and the exact
 * membership rules of scopeVisibleTo() so LIST and OPEN can never disagree.
 */
final class AssistantDealEditScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;      // the assigned agent
    private User $agentB;      // an unrelated agent
    private User $assistant;   // assigned to agentA
    private AssistantAssignment $assignment;
    private int $dealA;        // owned by agentA
    private int $dealB;        // owned by agentB

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

        $this->dealA = $this->makeDeal($this->agentA->id);
        $this->dealB = $this->makeDeal($this->agentB->id);

        // Both agents are 'own'-scope on deals, and can reach the edit surface.
        foreach (['create_deals', 'deals.edit'] as $key) {
            $this->grantRole($key);
        }
        $this->grantRole('deals.view', 'own'); // getDataScope('deals') reads deals.view's scope

        $this->reset();
    }

    /**
     * The core row guard, proven without the assistant matrix: an 'own'-scope agent is 403'd
     * opening a colleague's deal, and served their own.
     */
    public function test_an_own_scope_agent_cannot_open_another_agents_deal(): void
    {
        // agentA owns dealA → allowed.
        $this->actingAs($this->agentA)
            ->get(route('admin.deals.edit', $this->dealA))
            ->assertOk();

        // agentA does NOT own dealB (same agency + branch, so binding resolves it) → 403, not 404.
        $this->actingAs($this->agentA)
            ->get(route('admin.deals.edit', $this->dealB))
            ->assertForbidden();

        // ...and the mutation path is guarded too, not just the edit form.
        $this->actingAs($this->agentA)
            ->post(route('admin.deals.quickUpdate', $this->dealB), ['accepted_status' => 'A'])
            ->assertForbidden();
    }

    /**
     * THE REQUIREMENT. The assistant assigned to agentA works agentA's deals — and is 403'd on
     * agentB's, on both the read (edit form) and the write (quickUpdate) paths.
     */
    public function test_an_assistant_can_work_their_agents_deal_but_not_another_agents(): void
    {
        foreach (['create_deals', 'deals.edit'] as $key) {
            $this->grantAssistant($key);
        }
        $this->grantAssistant('deals.view', 'own');
        $this->reset();

        // agentA's deal — the assistant's whole job.
        $this->actingAs($this->assistant)
            ->get(route('admin.deals.edit', $this->dealA))
            ->assertOk();

        // agentB's deal — must be refused on read...
        $this->actingAs($this->assistant)
            ->get(route('admin.deals.edit', $this->dealB))
            ->assertForbidden();

        // ...and on write.
        $this->actingAs($this->assistant)
            ->post(route('admin.deals.quickUpdate', $this->dealB), ['accepted_status' => 'A'])
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

    private function makeDeal(int $ownerUserId): int
    {
        $id = DB::table('deals')->insertGetId([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'period' => '2026-06', 'deal_date' => '2026-06-01',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('deal_user')->insert([
            'deal_id' => $id, 'user_id' => $ownerUserId, 'side' => 'listing',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function grantRole(string $key, ?string $scope = null): void
    {
        RolePermission::firstOrCreate(
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

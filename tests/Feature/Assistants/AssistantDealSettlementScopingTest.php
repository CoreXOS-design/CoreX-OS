<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\DealV2\DealV2;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-267 C3 — deal settlement (money) and DealV2 edit bound a deal by id and checked only the
 * permission key. AuthorizesDealAccess was on Admin\DealController but not the live Dr2/DealV2
 * twins, so a settle/edit holder — or an assistant of one — could rewrite settlements / edit any
 * agency deal by id. These guards now pin an assistant to the assigned agent's OWN deals.
 */
final class AssistantDealSettlementScopingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agentA;     // assigned agent
    private User $agentB;     // unrelated agent
    private User $assistant;  // assigned to agentA
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

    public function test_assistant_cannot_settle_another_agents_dr2_deal(): void
    {
        $this->grant('settle_deals');
        $this->grant('deals.view', 'own');
        $mine   = $this->makeDeal($this->agentA->id);
        $theirs = $this->makeDeal($this->agentB->id);
        $this->reset();

        $this->actingAs($this->assistant)
            ->post(route('deals-dr2.settle.save', $theirs), [])
            ->assertForbidden();

        // The assigned agent's own deal is authorized (may 302/validation, never 403 from the guard).
        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->post(route('deals-dr2.settle.save', $mine), [])->status());
    }

    public function test_assistant_cannot_edit_or_settle_another_agents_dealv2(): void
    {
        $this->grant('access_deal_register_v2');
        $this->grant('deals_v2.edit');
        $this->grant('deals_v2.view', 'own');
        $mine   = $this->makeDealV2($this->agentA->id);
        $theirs = $this->makeDealV2($this->agentB->id);
        $this->reset();

        $this->actingAs($this->assistant)
            ->put(route('deals-v2.update', $theirs), [])
            ->assertForbidden();
        $this->actingAs($this->assistant)
            ->post(route('deals-v2.settlement.save', $theirs), [])
            ->assertForbidden();

        $this->assertNotSame(403, $this->actingAs($this->assistant)
            ->put(route('deals-v2.update', $mine), [])->status());
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

    private function makeDealV2(int $listingAgentId): DealV2
    {
        return DealV2::create([
            'reference'         => 'DV2-' . uniqid(),
            'deal_type'         => 'bond',
            'listing_agent_id'  => $listingAgentId,
            'purchase_price'    => 1_000_000,
            'commission_amount' => 50_000,
            'commission_vat'    => 7_500,
            'offer_date'        => '2026-06-01',
            'branch_id'         => $this->branch->id,
            'agency_id'         => $this->agency->id,
            'created_by_id'     => $listingAgentId,
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\AssistantLinkedAgent;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Role;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-267 multi-agent addendum, Prompt C — the BranchScope extension.
 *
 * Highest-blast-radius prompt in the addendum: BranchScope is consulted on every branch-scoped
 * query for every user in every agency. This file's first two tests exist specifically to prove
 * the change is a no-op for everyone except an assistant with a genuinely cross-branch linked
 * Sub-Agent — that is the entire safety argument for touching this file at all.
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §4
 */
final class AssistantLinkedSubAgentBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::forceProductionPosture();
    }

    public function test_a_non_assistant_is_completely_unaffected(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $margateAgent = $this->agent($agency, $margate, 'agent');

        $shepstoneProperty = $this->propertyIn($agency, $shepstone, $margateAgent->id);
        BranchScope::flushCache();

        $this->actingAs($margateAgent);
        $this->assertFalse(
            Property::whereKey($shepstoneProperty->id)->exists(),
            'A plain agent must be completely unaffected by this addendum.'
        );
    }

    public function test_an_assistant_with_no_linked_sub_agents_is_completely_unaffected(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $agency->update(['assistants_enabled' => true]);
        $mainAgent = $this->agent($agency, $margate, 'agent');
        $assistant = $this->assistantFor($agency, $margate, $mainAgent);

        $shepstoneProperty = $this->propertyIn($agency, $shepstone, $mainAgent->id);
        BranchScope::flushCache();

        $this->actingAs($assistant);
        $this->assertFalse(
            Property::whereKey($shepstoneProperty->id)->exists(),
            'An assistant with zero linked Sub-Agents must be byte-identical to today\'s behaviour.'
        );
    }

    public function test_an_assistant_with_a_same_branch_sub_agent_is_unaffected(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $agency->update(['assistants_enabled' => true]);
        $mainAgent = $this->agent($agency, $margate, 'agent');
        $subAgent  = $this->agent($agency, $margate, 'agent'); // SAME branch as main agent
        $assistant = $this->assistantFor($agency, $margate, $mainAgent);

        $this->linkSubAgent($assistant, $subAgent);

        $shepstoneProperty = $this->propertyIn($agency, $shepstone, $mainAgent->id);
        BranchScope::flushCache();

        $this->actingAs($assistant);
        $this->assertFalse(
            Property::whereKey($shepstoneProperty->id)->exists(),
            'A same-branch Sub-Agent must not widen anything — there is nothing new to widen.'
        );
    }

    public function test_an_assistant_sees_a_cross_branch_sub_agents_property(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $agency->update(['assistants_enabled' => true]);
        $mainAgent = $this->agent($agency, $margate, 'agent');
        $subAgent  = $this->agent($agency, $shepstone, 'agent'); // DIFFERENT branch
        $assistant = $this->assistantFor($agency, $margate, $mainAgent);

        $this->linkSubAgent($assistant, $subAgent);

        $subAgentsProperty = $this->propertyIn($agency, $shepstone, $subAgent->id);
        BranchScope::flushCache();

        $this->actingAs(User::find($assistant->id));
        $this->assertTrue(
            Property::whereKey($subAgentsProperty->id)->exists(),
            'The BranchScope extension must let the assistant reach a cross-branch linked '
            . 'Sub-Agent\'s property (M5) — otherwise dataIdentityIds() widening is silently inert.'
        );
    }

    public function test_a_third_branchs_property_stays_hidden_from_the_assistant(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $thirdBranch = Branch::create(['agency_id' => $agency->id, 'name' => 'Port Edward']);
        $agency->update(['assistants_enabled' => true]);
        $mainAgent = $this->agent($agency, $margate, 'agent');
        $subAgent  = $this->agent($agency, $shepstone, 'agent');
        $thirdAgent = $this->agent($agency, $thirdBranch, 'agent');
        $assistant = $this->assistantFor($agency, $margate, $mainAgent);

        $this->linkSubAgent($assistant, $subAgent);

        $thirdBranchProperty = $this->propertyIn($agency, $thirdBranch, $thirdAgent->id);
        BranchScope::flushCache();

        $this->actingAs(User::find($assistant->id));
        $this->assertFalse(
            Property::whereKey($thirdBranchProperty->id)->exists(),
            'The widening is bounded to exactly the linked Sub-Agent\'s own branch — never the whole agency.'
        );
    }

    public function test_split_branches_off_is_unaffected_by_this_addendum(): void
    {
        [$agency, $margate, $shepstone] = $this->agencyWithTwoBranches();
        $agency->update(['assistants_enabled' => true, 'split_branches_enabled' => false]);
        $mainAgent = $this->agent($agency, $margate, 'agent');
        $subAgent  = $this->agent($agency, $shepstone, 'agent');
        $assistant = $this->assistantFor($agency, $margate, $mainAgent);
        $this->linkSubAgent($assistant, $subAgent);

        $thirdAgent = $this->agent($agency, $shepstone, 'agent');
        $anyProperty = $this->propertyIn($agency, $shepstone, $thirdAgent->id);
        BranchScope::flushCache();

        // With Split OFF, BranchScope short-circuits before this addendum's code ever runs —
        // everything in the agency is visible, exactly as it was pre-addendum.
        $this->actingAs(User::find($assistant->id));
        $this->assertTrue(Property::whereKey($anyProperty->id)->exists());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function agencyWithTwoBranches(): array
    {
        $agency = Agency::create([
            'name' => 'Coastal ' . Str::random(5),
            'slug' => 'coastal-' . Str::random(8),
            'split_branches_enabled' => true,
        ]);

        return [
            $agency,
            Branch::create(['agency_id' => $agency->id, 'name' => 'Margate']),
            Branch::create(['agency_id' => $agency->id, 'name' => 'Port Shepstone']),
        ];
    }

    private function agent(Agency $agency, Branch $branch, string $role): User
    {
        Role::firstOrCreate(['name' => $role, 'agency_id' => $agency->id], ['label' => ucfirst($role)]);

        return User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => $role, 'is_active' => true,
        ]);
    }

    private function assistantFor(Agency $agency, Branch $branch, User $mainAgent): User
    {
        Role::firstOrCreate(['name' => 'assistant', 'agency_id' => $agency->id], ['label' => 'Assistant']);

        $assistant = User::factory()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'role' => 'assistant', 'is_active' => true, 'is_assistant' => true,
        ]);

        $assignment = AssistantAssignment::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'assistant_user_id' => $assistant->id, 'agent_user_id' => $mainAgent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        AssistantAssignmentPermission::create([
            'agency_id' => $agency->id, 'assistant_assignment_id' => $assignment->id,
            'permission_key' => 'properties.view', 'granted' => true, 'scope' => 'own',
        ]);
        \App\Models\RolePermission::create([
            'role' => 'agent', 'permission_key' => 'properties.view',
            'agency_id' => $agency->id, 'scope' => 'own',
        ]);

        User::flushAssistantsEnabledCache();

        return $assistant;
    }

    private function linkSubAgent(User $assistant, User $subAgent): AssistantLinkedAgent
    {
        $assignment = $assistant->assistantAssignment()->first()
            ?? AssistantAssignment::where('assistant_user_id', $assistant->id)->active()->firstOrFail();

        return AssistantLinkedAgent::create([
            'agency_id' => $assistant->agency_id,
            'assistant_assignment_id' => $assignment->id,
            'agent_user_id' => $subAgent->id,
        ]);
    }

    private function propertyIn(Agency $agency, Branch $branch, int $agentId): Property
    {
        return Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . Str::random(8),
            'title'       => $branch->name . ' Test Property',
            'address'     => '1 Test Street, ' . $branch->name,
            'agent_id'    => $agentId,
            'branch_id'   => $branch->id,
            'agency_id'   => $agency->id,
        ]));
    }
}

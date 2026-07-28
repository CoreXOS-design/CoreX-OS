<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\AssistantLinkedAgent;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 multi-agent addendum, Prompt B — the dataIdentityIds() widening.
 *
 * This is the mechanism the whole addendum leans on: every scopeVisibleTo() 'own' branch and
 * all three per-record authorize traits (AuthorizesPropertyAccess, AuthorizesDealAccess,
 * AuthorizesContactAccess) already resolve 'own' through User::dataIdentityIds(). Widening what
 * that one method returns is therefore sufficient to let an assistant edit a linked Sub-Agent's
 * records — with ZERO changes to any of those traits. This file proves that end to end via the
 * contacts edit path (mirrors AssistantContactEditScopingTest's pattern), plus the live-filter
 * edge cases (deactivated / promoted / converted / unlinked Sub-Agent) and the invariant that
 * linking a Sub-Agent never widens the permission CEILING (M4).
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §2.4, §5, §9 (E7a/E7b/E7c)
 */
final class AssistantLinkedSubAgentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $mainAgent;
    private User $subAgent;
    private User $thirdAgent;   // never linked — the control
    private User $assistant;
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

        $this->mainAgent  = $this->makeUser('Sarah Nkosi', 'agent');
        $this->subAgent   = $this->makeUser('Pieter van Wyk', 'agent');
        $this->thirdAgent = $this->makeUser('Willem Botha', 'agent');
        $this->assistant  = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'assistant_user_id' => $this->assistant->id, 'agent_user_id' => $this->mainAgent->id,
            'status' => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $this->grant('access_contacts');
        $this->grant('contacts.view', 'all');

        $this->reset();
    }

    // ── dataIdentityIds() shape ─────────────────────────────────────────────

    public function test_an_assistant_with_no_linked_sub_agents_is_unaffected(): void
    {
        $assistant = User::find($this->assistant->id);

        $this->assertSame(
            [$this->mainAgent->id, $this->assistant->id],
            $assistant->dataIdentityIds(),
            'Zero linked Sub-Agents must reproduce EXACTLY the pre-addendum shape.'
        );
        $this->assertSame([], $assistant->activeLinkedSubAgentIds());
    }

    public function test_dataIdentityIds_includes_an_active_linked_sub_agent(): void
    {
        $this->linkSubAgent($this->subAgent);

        $assistant = User::find($this->assistant->id);

        $this->assertEqualsCanonicalizing(
            [$this->mainAgent->id, $this->assistant->id, $this->subAgent->id],
            $assistant->dataIdentityIds()
        );
        $this->assertNotContains($this->thirdAgent->id, $assistant->dataIdentityIds());
    }

    // ── The proof: editing a Sub-Agent's contact requires zero trait changes ──

    public function test_assistant_can_now_edit_a_linked_sub_agents_contact(): void
    {
        $subAgentsContact = $this->makeContact($this->subAgent->id, 'Bob', 'Buyer');

        // BEFORE linking: exactly today's behaviour — view yes, edit no (base spec §7.2).
        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $subAgentsContact))
            ->assertForbidden();
        $this->assertNotSoftDeleted('contacts', ['id' => $subAgentsContact->id]);

        // Admin links the Sub-Agent.
        $this->linkSubAgent($this->subAgent);

        // AFTER linking: the assistant can now edit the Sub-Agent's contact — with no code
        // change to AuthorizesContactAccess at all.
        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $subAgentsContact))
            ->assertRedirect();
        $this->assertSoftDeleted('contacts', ['id' => $subAgentsContact->id]);
    }

    public function test_an_unlinked_third_agents_contact_stays_protected(): void
    {
        $this->linkSubAgent($this->subAgent);

        $thirdAgentsContact = $this->makeContact($this->thirdAgent->id, 'Willem', 'Botha');

        $this->actingAs($this->assistant)
            ->delete(route('corex.contacts.destroy', $thirdAgentsContact))
            ->assertForbidden();
        $this->assertNotSoftDeleted('contacts', ['id' => $thirdAgentsContact->id]);
    }

    // ── Live filter — E7a/E7b (edge cases) ─────────────────────────────────

    public function test_a_deactivated_sub_agent_drops_out_live(): void
    {
        $link = $this->linkSubAgent($this->subAgent);

        $this->subAgent->update(['is_active' => false]);
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertNotContains($this->subAgent->id, $assistant->dataIdentityIds());

        // Reactivating restores it — no admin cleanup of the link row required (E7a).
        $this->subAgent->update(['is_active' => true]);
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertContains($this->subAgent->id, $assistant->dataIdentityIds());
        $this->assertNotNull($link->fresh());
    }

    public function test_a_sub_agent_promoted_to_owner_drops_out_live(): void
    {
        $this->linkSubAgent($this->subAgent);

        Role::create(['name' => 'test-owner', 'label' => 'Owner', 'agency_id' => null, 'is_owner' => true]);
        $this->subAgent->update(['role' => 'test-owner']);
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertNotContains(
            $this->subAgent->id,
            $assistant->dataIdentityIds(),
            'An owner-role Sub-Agent must never be reachable through an assistant (E7b, mirrors base spec E6).'
        );
    }

    public function test_a_sub_agent_converted_to_an_assistant_drops_out_live(): void
    {
        $this->linkSubAgent($this->subAgent);

        $this->subAgent->update(['is_assistant' => true]);
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertNotContains(
            $this->subAgent->id,
            $assistant->dataIdentityIds(),
            'A Sub-Agent who becomes an assistant themselves must drop out (E7b, mirrors base spec E5).'
        );
    }

    public function test_unlinking_removes_access_and_relinking_restores_it(): void
    {
        $link = $this->linkSubAgent($this->subAgent);

        $link->delete(); // admin "remove" action — soft delete
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertNotContains($this->subAgent->id, $assistant->dataIdentityIds());

        $link->restore();
        $this->reset();

        $assistant = User::find($this->assistant->id);
        $this->assertContains($this->subAgent->id, $assistant->dataIdentityIds());
    }

    // ── The permission ceiling is untouched (M4) ────────────────────────────

    public function test_linking_a_sub_agent_never_widens_the_permission_ceiling(): void
    {
        // The Sub-Agent holds a permission the Main Agent does not.
        Role::create(['name' => 'branch_manager', 'label' => 'Branch Manager', 'agency_id' => $this->agency->id]);
        $this->subAgent->update(['role' => 'branch_manager']);
        RolePermission::create([
            'role' => 'branch_manager', 'permission_key' => 'branches.view_all',
            'agency_id' => $this->agency->id,
        ]);

        $this->linkSubAgent($this->subAgent);
        $this->reset();

        $this->assertTrue(
            PermissionService::userHasPermission(User::find($this->subAgent->id), 'branches.view_all'),
            'Sanity: the Sub-Agent really does hold the permission.'
        );
        $this->assertFalse(
            PermissionService::userHasPermission(User::find($this->mainAgent->id), 'branches.view_all'),
            'Sanity: the Main Agent does not.'
        );
        $this->assertFalse(
            \App\Services\Assistants\AssistantPermissionResolver::allows(
                User::find($this->assistant->id),
                'branches.view_all'
            ),
            'A Sub-Agent\'s wider permission must NEVER leak into the assistant\'s ceiling — '
            . 'the ceiling stays keyed to the Main Agent exclusively (M4).'
        );
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

    private function linkSubAgent(User $agent): AssistantLinkedAgent
    {
        return AssistantLinkedAgent::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $this->assignment->id,
            'agent_user_id'           => $agent->id,
            'added_by_user_id'        => null,
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

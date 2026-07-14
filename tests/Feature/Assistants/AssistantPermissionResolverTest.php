<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\Assistants\AssistantPermissionResolver;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — Assistants, Prompt C: the resolver.
 *
 *     assistant_can(key) = matrix[key] AND assigned_agent_can(key) AND key ∉ LOCKED_SET
 *
 * Every test here is a way that equation could be got wrong, and each one would be a real
 * privilege escalation if it were:
 *
 *  - If the matrix alone decided, an agent could hand over something they don't have.
 *  - If the SNAPSHOT decided, an agent demoted today would still have an assistant acting
 *    with yesterday's powers.
 *  - If the role decided, an assistant would inherit `users.role` — which DEFAULTS TO
 *    'agent'. A mis-set role column would silently make an assistant a full agent.
 *  - If the lock were only in the matrix, a hand-crafted row would defeat it.
 *  - If any unclear state fell back to a default, the default would be agent permissions.
 *
 * These tests run against the PRODUCTION posture (forceProductionPosture) with a REAL,
 * populated grants table. Under the suite's default unseeded posture every role gets
 * allow-all, which would make most of the assertions below pass for the wrong reason.
 *
 * Paths proven: no assignment · suspended assignment · deactivated agent · agency kill
 * switch · locked key beats a granted matrix row · locked key beats an agent who HAS it ·
 * matrix off + agent on · matrix on + agent off (the live ceiling) · matrix on + agent on ·
 * agent LOSES a permission mid-life · the assistant's own role is never consulted · data
 * scope clamps to the agent's · data scope null when the agent has none · data scope null
 * when the matrix withheld the module.
 */
final class AssistantPermissionResolverTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $assistant;
    private AssistantAssignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name'               => 'Home Finders Coastal',
            'slug'               => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        // The test DB ships with no roles (see AssistantRoleIsZeroGrantTest) — stand up the
        // two we need, exactly as live has them.
        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent = User::factory()->create([
            'name'      => 'Sarah Nkosi',
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
            'is_active' => true,
        ]);

        $this->assistant = User::factory()->create([
            'name'         => 'Thandi Mokoena',
            'agency_id'    => $this->agency->id,
            'branch_id'    => $this->branch->id,
            'role'         => 'assistant',
            'is_assistant' => true,
        ]);

        $this->assignment = AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $this->assistant->id,
            'agent_user_id'     => $this->agent->id,
        ]);

        // A REAL grants table. Without this the suite's unseeded allow-all would hand every
        // role every permission, and half these tests would prove nothing.
        $this->agentHolds('contacts.view', 'own');
        $this->agentHolds('contacts.create');
        $this->agentHolds('properties.create'); // the agent CAN create listings. The assistant may not.

        $this->reset();
        PermissionService::forceProductionPosture();
    }

    /** Grant a permission to the agent's role, for real. */
    private function agentHolds(string $key, ?string $scope = null): void
    {
        RolePermission::create([
            'role'           => 'agent',
            'permission_key' => $key,
            'agency_id'      => $this->agency->id,
            'scope'          => $scope,
        ]);
    }

    private function agentLoses(string $key): void
    {
        RolePermission::where('role', $key === '' ? 'agent' : 'agent')
            ->where('permission_key', $key)
            ->where('agency_id', $this->agency->id)
            ->forceDelete();
    }

    /** Put a key in the assistant's matrix. */
    private function matrix(string $key, bool $granted = true, ?string $scope = null, bool $locked = false): void
    {
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => $granted, 'scope' => $scope, 'is_locked' => $locked],
        );
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }

    private function assistant(): User
    {
        return User::find($this->assistant->id);
    }

    // ---------------------------------------------------------------
    // Fail closed — every unclear state is a denial
    // ---------------------------------------------------------------

    public function test_an_assistant_with_no_assignment_can_do_nothing(): void
    {
        $this->matrix('contacts.view', scope: 'own');
        $this->assignment->forceDelete();
        $this->reset();

        // NOT "falls back to their role" — their role is 'assistant' and grants nothing,
        // but even if it were mis-set to 'agent', the resolver never looks.
        $this->assertFalse($this->assistant()->hasPermission('contacts.view'));
        $this->assertNull(PermissionService::getDataScope($this->assistant(), 'contacts'));
    }

    public function test_a_suspended_assignment_grants_nothing(): void
    {
        $this->matrix('contacts.view', scope: 'own');
        $this->assignment->update(['status' => AssistantAssignment::STATUS_SUSPENDED]);
        $this->reset();

        $this->assertFalse($this->assistant()->hasPermission('contacts.view'));
    }

    public function test_a_deactivated_agent_freezes_their_assistant(): void
    {
        $this->matrix('contacts.view', scope: 'own');
        $this->matrix('contacts.create');
        $this->reset();
        $this->assertTrue($this->assistant()->hasPermission('contacts.create')); // working...

        // E1: the agent is deactivated. The assistant keeps their login and loses everything —
        // they cannot keep acting for someone whose own access has been withdrawn.
        $this->agent->update(['is_active' => false]);
        $this->reset();

        $this->assertFalse($this->assistant()->hasPermission('contacts.create'));
        $this->assertFalse($this->assistant()->hasPermission('contacts.view'));
        $this->assertNull(PermissionService::getDataScope($this->assistant(), 'contacts'));
    }

    public function test_the_agency_kill_switch_grants_nothing(): void
    {
        $this->matrix('contacts.create');
        $this->reset();
        $this->assertTrue($this->assistant()->hasPermission('contacts.create'));

        $this->agency->update(['assistants_enabled' => false]);
        $this->reset();

        $this->assertFalse($this->assistant()->hasPermission('contacts.create'));
    }

    // ---------------------------------------------------------------
    // The property-upload hard lock
    // ---------------------------------------------------------------

    public function test_a_locked_key_is_denied_even_when_the_matrix_grants_it_and_the_agent_has_it(): void
    {
        // The agent CAN create properties (granted in setUp). Force a granted matrix row for
        // the locked key straight into the DB, bypassing the model's saving() guard — i.e.
        // simulate the worst case, a row that should be impossible.
        AssistantAssignmentPermission::withoutEvents(fn () => AssistantAssignmentPermission::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $this->assignment->id,
            'permission_key'          => 'properties.create',
            'granted'                 => true,
            'is_locked'               => false,
        ]));
        $this->reset();

        // Both halves of the intersection say yes. The lock still says no. That is the point:
        // no assistant creates a listing, whatever any row anywhere says.
        $this->assertTrue($this->agent->fresh()->hasPermission('properties.create'));
        $this->assertFalse($this->assistant()->hasPermission('properties.create'));
    }

    public function test_every_key_in_the_locked_set_is_denied(): void
    {
        foreach (AssistantPermissionResolver::lockedSet() as $key) {
            AssistantAssignmentPermission::withoutEvents(fn () => AssistantAssignmentPermission::create([
                'agency_id'               => $this->agency->id,
                'assistant_assignment_id' => $this->assignment->id,
                'permission_key'          => $key,
                'granted'                 => true,
            ]));
        }
        $this->reset();

        foreach (AssistantPermissionResolver::lockedSet() as $key) {
            $this->assertFalse(
                $this->assistant()->hasPermission($key),
                "[{$key}] is in the locked set and must never resolve true for an assistant."
            );
        }
    }

    public function test_the_cma_upload_key_is_deliberately_not_locked(): void
    {
        // D3, Johan: uploading a CMA / market report is intelligence capture, not putting a
        // listing on the books — and it is exactly the drudge work an assistant should absorb.
        $this->assertFalse(AssistantPermissionResolver::isLocked('mic.upload_reports'));
        $this->assertFalse(AssistantPermissionResolver::isLocked('mic.edit_address'));
    }

    // ---------------------------------------------------------------
    // The intersection
    // ---------------------------------------------------------------

    public function test_matrix_off_and_agent_on_is_denied(): void
    {
        $this->matrix('contacts.create', granted: false);
        $this->reset();

        // The agent has it; they chose not to hand it over.
        $this->assertTrue($this->agent->fresh()->hasPermission('contacts.create'));
        $this->assertFalse($this->assistant()->hasPermission('contacts.create'));
    }

    public function test_matrix_on_and_agent_off_is_denied(): void
    {
        // The agent does NOT hold deals.create. An agent cannot hand over what they don't have —
        // even if a row in the matrix says otherwise.
        $this->matrix('deals.create', granted: true);
        $this->reset();

        $this->assertFalse($this->agent->fresh()->hasPermission('deals.create'));
        $this->assertFalse($this->assistant()->hasPermission('deals.create'));
    }

    public function test_matrix_on_and_agent_on_is_allowed(): void
    {
        $this->matrix('contacts.create');
        $this->reset();

        $this->assertTrue($this->assistant()->hasPermission('contacts.create'));
    }

    public function test_the_assistant_loses_a_permission_the_moment_the_agent_does(): void
    {
        $this->matrix('contacts.create');
        $this->reset();
        $this->assertTrue($this->assistant()->hasPermission('contacts.create'));

        // The agent is demoted. No re-snapshot, no nightly job, no admin action — the
        // assistant must lose it on the very next request, because the intersection is
        // evaluated live and never cached against a stale copy.
        $this->agentLoses('contacts.create');
        $this->reset();

        $this->assertFalse($this->agent->fresh()->hasPermission('contacts.create'));
        $this->assertFalse($this->assistant()->hasPermission('contacts.create'));

        // ...and the matrix row is untouched, so restoring the agent's permission restores
        // the assistant's without anyone re-ticking a box.
        $this->agentHolds('contacts.create');
        $this->reset();
        $this->assertTrue($this->assistant()->hasPermission('contacts.create'));
    }

    // ---------------------------------------------------------------
    // The role column is never consulted — the trap that motivated the whole design
    // ---------------------------------------------------------------

    public function test_an_assistants_own_role_grants_them_nothing(): void
    {
        // The nightmare scenario: an assistant's `users.role` is somehow set to a powerful
        // role — by a bad import, a careless admin edit, or simply the NOT NULL DEFAULT
        // 'agent' on a code path that forgets to set it.
        Role::create(['name' => 'admin', 'label' => 'Admin', 'agency_id' => $this->agency->id]);
        RolePermission::create([
            'role'           => 'admin',
            'permission_key' => 'deals.create',
            'agency_id'      => $this->agency->id,
        ]);

        // A real admin has to exist independently, or User::saving()'s last-admin guard
        // refuses to let us move the assistant back off the admin role afterwards. (That
        // guard firing here is itself reassuring — it means the agency can never be left
        // adminless — but it is not what this test is about.)
        User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);

        $this->assistant->update(['role' => 'admin']);
        $this->reset();

        // The resolver never looks at the role. The assistant is still bounded by their
        // matrix ∩ their agent — and the agent does not hold deals.create.
        $this->assertFalse($this->assistant()->hasPermission('deals.create'));

        // The likelier trap, and the reason `is_assistant` exists as a separate flag at all:
        // `users.role` is NOT NULL DEFAULT 'agent'. Any code path that forgets to set the
        // role produces a full agent. It must still grant the assistant nothing.
        $this->assistant->update(['role' => 'agent']);
        $this->reset();

        $this->assertFalse($this->assistant()->hasPermission('contacts.create'));  // not in the matrix
        $this->assertFalse($this->assistant()->hasPermission('properties.create')); // locked
        $this->assertFalse($this->assistant()->hasPermission('contacts.view'));     // not in the matrix
    }

    // ---------------------------------------------------------------
    // Data scope — how wide
    // ---------------------------------------------------------------

    public function test_the_data_scope_clamps_to_the_agents_own_scope(): void
    {
        // The agent's contacts scope is 'own'. The matrix asks for 'all'. The assistant may
        // not out-see the agent, so it clamps down.
        $this->matrix('contacts.view', scope: 'all');
        $this->reset();

        $this->assertSame('own', PermissionService::getDataScope($this->assistant(), 'contacts'));
    }

    public function test_the_data_scope_is_null_when_the_agent_has_no_access_to_the_module(): void
    {
        $this->matrix('deals.view', scope: 'all');
        $this->reset();

        // The agent cannot see deals at all, so neither can the assistant. null = "no rows"
        // to every scopeVisibleTo().
        $this->assertNull(PermissionService::getDataScope($this->assistant(), 'deals'));
    }

    public function test_the_data_scope_is_null_when_the_matrix_withheld_the_module(): void
    {
        // The agent CAN see contacts, but did not hand the module over.
        $this->reset();

        $this->assertSame('own', PermissionService::getDataScope($this->agent->fresh(), 'contacts'));
        $this->assertNull(PermissionService::getDataScope($this->assistant(), 'contacts'));
    }

    public function test_a_granted_view_key_yields_the_agents_scope(): void
    {
        $this->matrix('contacts.view', scope: 'own');
        $this->reset();

        $this->assertSame('own', PermissionService::getDataScope($this->assistant(), 'contacts'));
        $this->assertTrue($this->assistant()->hasPermission('contacts.view'));
    }
}

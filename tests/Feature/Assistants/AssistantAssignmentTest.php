<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — Assistants, Prompt A: the data model's guarantees.
 *
 * This file proves the things the SCHEMA is responsible for, not the resolver (Prompt C).
 * Each test below corresponds to a promise the spec makes that would be a silent
 * data-integrity hole if the DB did not enforce it:
 *
 *  - One active Assigned Agent per Assistant, enforced by the DATABASE (a generated column
 *    + unique key), because a controller check can lose a race. Revoked/suspended history
 *    must still be able to pile up beside the live row.
 *  - A soft-deleted assignment takes its matrix with it and brings it back on restore —
 *    this is what lets the soft-deleted assignment BE the reassignment archive (§6.4), so
 *    there is no second table to keep in sync.
 *  - `is_locked` (the property-upload set) forces `granted = false` at the model layer —
 *    layer 4 of the four-layer lock (§9). A hand-crafted write cannot grant a locked key.
 *  - `grants()` fails CLOSED on a missing row. An unseeded matrix grants nothing.
 *  - The data-identity helpers (§7.2): an assistant's 'own' means the AGENT's own, and a
 *    record they create is OWNED by the agent. Without this the feature ships inert.
 *  - The kill switch (`agencies.assistants_enabled`) returns the system to EXACTLY current
 *    behaviour — an assistant resolves as a plain user with no inherited anything.
 */
final class AssistantAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create([
            'name'               => 'Home Finders Coastal',
            'slug'               => 'hfc-' . uniqid(),
            'assistants_enabled' => true,
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Margate']);

        User::flushAssistantsEnabledCache();
    }

    private function agent(string $name = 'Sarah Nkosi'): User
    {
        return User::factory()->create([
            'name'      => $name,
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'agent',
        ]);
    }

    private function assistantUser(string $name = 'Thandi Mokoena'): User
    {
        return User::factory()->create([
            'name'         => $name,
            'agency_id'    => $this->agency->id,
            'branch_id'    => $this->branch->id,
            'role'         => 'assistant',
            'is_assistant' => true,
        ]);
    }

    private function assign(User $assistant, User $agent, string $status = AssistantAssignment::STATUS_ACTIVE): AssistantAssignment
    {
        return AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $assistant->id,
            'agent_user_id'     => $agent->id,
            'status'            => $status,
        ]);
    }

    // ---------------------------------------------------------------
    // One active agent per assistant — enforced by the database
    // ---------------------------------------------------------------

    public function test_an_assistant_cannot_hold_two_active_assignments(): void
    {
        $assistant = $this->assistantUser();
        $this->assign($assistant, $this->agent('Sarah Nkosi'));

        // A second LIVE assignment for the same person is a database error, not a
        // controller decision. Two agents cannot both own the same assistant's work.
        $this->expectException(QueryException::class);
        $this->assign($assistant, $this->agent('Pieter van Wyk'));
    }

    public function test_a_revoked_assignment_does_not_block_a_new_active_one(): void
    {
        $assistant = $this->assistantUser();
        $oldAgent  = $this->agent('Sarah Nkosi');
        $newAgent  = $this->agent('Pieter van Wyk');

        $old = $this->assign($assistant, $oldAgent);
        $old->update(['status' => AssistantAssignment::STATUS_REVOKED, 'revoked_at' => now()]);

        // Reassignment must work, and the revoked row must survive as the audit trail.
        $new = $this->assign($assistant, $newAgent);

        $this->assertTrue($new->isActive());
        $this->assertDatabaseHas('assistant_assignments', [
            'id'     => $old->id,
            'status' => AssistantAssignment::STATUS_REVOKED,
        ]);
        $this->assertSame(2, AssistantAssignment::withTrashed()
            ->where('assistant_user_id', $assistant->id)->count());
    }

    public function test_a_soft_deleted_assignment_does_not_block_a_new_active_one(): void
    {
        $assistant = $this->assistantUser();
        $this->assign($assistant, $this->agent('Sarah Nkosi'))->delete();

        $new = $this->assign($assistant, $this->agent('Pieter van Wyk'));

        $this->assertTrue($new->exists);
    }

    public function test_one_agent_may_have_many_assistants(): void
    {
        $agent = $this->agent();
        $this->assign($this->assistantUser('Thandi Mokoena'), $agent);
        $this->assign($this->assistantUser('Rajesh Naidoo'), $agent);

        $this->assertSame(2, $agent->assistantAssignments()->count());
        $this->assertTrue($agent->hasAssistants());
    }

    // ---------------------------------------------------------------
    // The matrix travels with the assignment (the reassignment archive)
    // ---------------------------------------------------------------

    public function test_soft_deleting_an_assignment_takes_its_matrix_with_it_and_restore_brings_it_back(): void
    {
        $assignment = $this->assign($this->assistantUser(), $this->agent());
        $this->grant($assignment, 'contacts.view', scope: 'own');
        $this->grant($assignment, 'command_center.tasks.create');

        $assignment->delete();

        $this->assertSame(0, AssistantAssignmentPermission::where('assistant_assignment_id', $assignment->id)->count());
        $this->assertSame(2, AssistantAssignmentPermission::withTrashed()
            ->where('assistant_assignment_id', $assignment->id)->count());

        $assignment->restore();

        // The archive is usable: the matrix comes back exactly as it was.
        $this->assertSame(2, AssistantAssignmentPermission::where('assistant_assignment_id', $assignment->id)->count());
    }

    // ---------------------------------------------------------------
    // The property-upload lock — layer 4 (model backstop)
    // ---------------------------------------------------------------

    public function test_a_locked_permission_cannot_be_granted_even_by_a_direct_write(): void
    {
        $assignment = $this->assign($this->assistantUser(), $this->agent());

        // Simulate a hand-crafted POST / a careless future writer trying to switch on a
        // locked key. The model must refuse to persist it as granted.
        $row = AssistantAssignmentPermission::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $assignment->id,
            'permission_key'          => 'properties.create',
            'granted'                 => true,
            'scope'                   => 'all',
            'is_locked'               => true,
        ]);

        $this->assertFalse($row->fresh()->granted);
        $this->assertNull($row->fresh()->scope);
        $this->assertFalse($assignment->fresh()->load('permissions')->grants('properties.create'));
    }

    public function test_the_matrix_fails_closed_on_a_key_it_has_never_seen(): void
    {
        $assignment = $this->assign($this->assistantUser(), $this->agent())->load('permissions');

        // An unseeded matrix grants nothing. Absence is a denial, never a default.
        $this->assertFalse($assignment->grants('contacts.view'));
        $this->assertNull($assignment->scopeFor('contacts.view'));
    }

    public function test_an_ungranted_row_is_a_denial(): void
    {
        $assignment = $this->assign($this->assistantUser(), $this->agent());
        $this->grant($assignment, 'deals.view', granted: false, scope: 'own');

        $assignment = $assignment->fresh()->load('permissions');

        $this->assertFalse($assignment->grants('deals.view'));
        $this->assertNull($assignment->scopeFor('deals.view'));
    }

    public function test_a_granted_view_key_exposes_its_scope(): void
    {
        $assignment = $this->assign($this->assistantUser(), $this->agent());
        $this->grant($assignment, 'contacts.view', scope: 'own');

        $assignment = $assignment->fresh()->load('permissions');

        $this->assertTrue($assignment->grants('contacts.view'));
        $this->assertSame('own', $assignment->scopeFor('contacts.view'));
    }

    // ---------------------------------------------------------------
    // Data identity (§7.2) — the reason the feature is not inert
    // ---------------------------------------------------------------

    public function test_a_normal_user_sees_and_owns_only_their_own_records(): void
    {
        $agent = $this->agent();

        $this->assertSame([$agent->id], $agent->dataIdentityIds());
        $this->assertSame($agent->id, $agent->ownershipUserId());
        $this->assertFalse($agent->isAssistant());
    }

    public function test_an_assistant_sees_the_agents_records_and_the_agent_owns_what_they_create(): void
    {
        $agent     = $this->agent();
        $assistant = $this->assistantUser();
        $this->assign($assistant, $agent);

        $assistant = $assistant->fresh();

        $this->assertTrue($assistant->isAssistant());
        $this->assertSame($agent->id, $assistant->assignedAgent()->id);

        // 'own' scope must resolve to the AGENT's book, or the assistant sees an empty list.
        $this->assertEqualsCanonicalizing([$agent->id, $assistant->id], $assistant->dataIdentityIds());

        // A deal captured by the assistant is the AGENT's deal — commission depends on it.
        $this->assertSame($agent->id, $assistant->ownershipUserId());
    }

    public function test_a_stale_is_assistant_flag_with_no_assignment_inherits_nothing(): void
    {
        // The flag alone must never be enough. A user flagged as an assistant with no live
        // assignment is a user with NO permissions (the resolver fails closed) — never one
        // who falls back to agent defaults.
        $orphan = $this->assistantUser();

        $this->assertFalse($orphan->isAssistant());
        $this->assertNull($orphan->assignedAgent());
        $this->assertSame([$orphan->id], $orphan->dataIdentityIds());
        $this->assertSame($orphan->id, $orphan->ownershipUserId());
    }

    public function test_a_suspended_assignment_inherits_nothing(): void
    {
        $agent     = $this->agent();
        $assistant = $this->assistantUser();
        $this->assign($assistant, $agent, AssistantAssignment::STATUS_SUSPENDED);

        $assistant = $assistant->fresh();

        // E1: the agent was deactivated. The assistant keeps their login and loses everything.
        $this->assertFalse($assistant->isAssistant());
        $this->assertSame([$assistant->id], $assistant->dataIdentityIds());
    }

    // ---------------------------------------------------------------
    // The kill switch
    // ---------------------------------------------------------------

    public function test_the_kill_switch_returns_the_assistant_to_a_plain_user(): void
    {
        $agent     = $this->agent();
        $assistant = $this->assistantUser();
        $this->assign($assistant, $agent);

        $this->agency->update(['assistants_enabled' => false]);
        User::flushAssistantsEnabledCache();

        $assistant = $assistant->fresh();

        // Flipping the toggle off must return the system to EXACTLY current behaviour:
        // no inherited permissions, no inherited visibility, no inherited ownership.
        $this->assertFalse($assistant->isAssistant());
        $this->assertNull($assistant->assignedAgent());
        $this->assertSame([$assistant->id], $assistant->dataIdentityIds());
        $this->assertSame($assistant->id, $assistant->ownershipUserId());

        // ...and the assignment itself is untouched, so flipping back on restores it.
        $this->assertDatabaseHas('assistant_assignments', [
            'assistant_user_id' => $assistant->id,
            'status'            => AssistantAssignment::STATUS_ACTIVE,
        ]);
    }

    public function test_flipping_the_kill_switch_back_on_restores_the_assistant(): void
    {
        $agent     = $this->agent();
        $assistant = $this->assistantUser();
        $this->assign($assistant, $agent);

        $this->agency->update(['assistants_enabled' => false]);
        User::flushAssistantsEnabledCache();
        $this->assertFalse($assistant->fresh()->isAssistant());

        $this->agency->update(['assistants_enabled' => true]);
        User::flushAssistantsEnabledCache();

        $this->assertTrue($assistant->fresh()->isAssistant());
    }

    private function grant(
        AssistantAssignment $assignment,
        string $key,
        bool $granted = true,
        ?string $scope = null,
        bool $locked = false,
    ): AssistantAssignmentPermission {
        return AssistantAssignmentPermission::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $assignment->id,
            'permission_key'          => $key,
            'granted'                 => $granted,
            'scope'                   => $scope,
            'is_locked'               => $locked,
        ]);
    }
}

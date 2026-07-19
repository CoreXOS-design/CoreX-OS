<?php

declare(strict_types=1);

namespace Tests\Feature\Assistants;

use App\Models\Agency;
use App\Models\AssistantAssignment;
use App\Models\AssistantAssignmentPermission;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AT-267 — Assistants, Prompt D: THE test the whole feature stands or falls on.
 *
 * The build brief solved permissions ("may I edit a contact?") and stopped there. But
 * permissions are not visibility. Every scopeVisibleTo() in CoreX resolves an 'own' data
 * scope as `where(<actor column>, $user->id)` — so an assistant granted contacts.view at
 * scope 'own' would have seen ZERO of their agent's contacts. Only ones they created
 * themselves. Which, on day one, is none.
 *
 * The feature would have shipped, passed every permission test, and been INERT: the agent
 * would open it, see an empty list, conclude it was broken, and go back to sharing their
 * password — which is the exact problem Assistants exists to solve.
 *
 * So this file asserts the thing that actually matters: an assistant sees THE AGENT'S BOOK.
 * And its mirror image — a normal agent's view of the world is completely unchanged, and one
 * agent's assistant cannot see another agent's records.
 *
 * Paths proven: assistant sees the agent's properties · assistant sees the agent's contacts ·
 * assistant does NOT see a THIRD party's records · a normal agent is unaffected · a second
 * agent's assistant sees only their own agent's book · a revoked assistant sees nothing ·
 * the agent's private Ellie conversations stay private.
 */
final class AssistantSeesTheAgentsBookTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;
    private User $otherAgent;
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

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent      = $this->makeUser('Sarah Nkosi', 'agent');
        $this->otherAgent = $this->makeUser('Pieter van Wyk', 'agent');
        $this->assistant  = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $this->assistant->id,
            'agent_user_id'     => $this->agent->id,
        ]);

        // The agent's real permissions — scope 'own', the normal agent default.
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'agency_id' => $this->agency->id, 'scope' => 'own']);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'contacts.view',   'agency_id' => $this->agency->id, 'scope' => 'own']);

        // The agent hands both modules to their assistant.
        $this->matrix('properties.view', 'own');
        $this->matrix('contacts.view', 'own');

        $this->reset();
    }

    private function makeUser(string $name, string $role, bool $isAssistant = false): User
    {
        return User::factory()->create([
            'name'         => $name,
            'agency_id'    => $this->agency->id,
            'branch_id'    => $this->branch->id,
            'role'         => $role,
            'is_active'    => true,
            'is_assistant' => $isAssistant,
        ]);
    }

    private function matrix(string $key, ?string $scope = null): void
    {
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

    private function contactFor(User $agent, string $first, string $last): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id'          => $this->agency->id,
            'branch_id'          => $this->branch->id,
            'first_name'         => $first,
            'last_name'          => $last,
            'phone'              => '083 555 0142',
            'created_by_user_id' => $agent->id,
        ]);
    }

    private function propertyFor(User $agent, string $street): Property
    {
        return Property::create([
            'agency_id'     => $this->agency->id,
            'branch_id'     => $this->branch->id,
            'agent_id'      => $agent->id,
            'title'         => $street,
            'street_name'   => $street,
            'street_number' => '14',
            'suburb'        => 'Margate',
            'city'          => 'Margate',
            'status'        => 'active',
        ]);
    }

    // ---------------------------------------------------------------

    public function test_an_assistant_sees_their_agents_properties(): void
    {
        $hers   = $this->propertyFor($this->agent, 'Marine Drive');
        $theirs = $this->propertyFor($this->otherAgent, 'Panorama Parade');

        $visible = Property::visibleTo(User::find($this->assistant->id))->pluck('id');

        // THE assertion. Before Prompt D this returned an empty collection and the entire
        // feature was inert.
        $this->assertTrue($visible->contains($hers->id), "The assistant must see their agent's listing.");

        // ...and no further. An assistant is scoped to ONE agent's book.
        $this->assertFalse($visible->contains($theirs->id), "The assistant must NOT see another agent's listing.");
    }

    /**
     * Contacts do NOT use scopeVisibleTo — they use the global ContactScope. So the sweep had
     * to reach into that scope too, and it is exercised here by simply logging in as the
     * assistant and querying, exactly as a controller would.
     */
    public function test_an_assistant_sees_their_agents_contacts(): void
    {
        $hers   = $this->contactFor($this->agent, 'Nomsa', 'Dlamini');
        $theirs = $this->contactFor($this->otherAgent, 'Willem', 'Botha');

        $this->actingAs(User::find($this->assistant->id));
        $this->reset();

        $visible = Contact::pluck('id');

        $this->assertTrue($visible->contains($hers->id), "The assistant must see their agent's contact.");
        $this->assertFalse($visible->contains($theirs->id), "The assistant must NOT see another agent's contact.");
    }

    /**
     * THE FAIL-OPEN. ContactScope treats a null data scope as "no restriction" — for a normal
     * role that is pre-existing behaviour, but for an assistant a null scope means their agent
     * WITHHELD the module (or the assignment is dead). Falling open there would have shown the
     * assistant every contact in the agency: more than their own agent can see.
     *
     * An assistant who can out-see their agent is the one outcome this feature may never produce.
     */
    public function test_an_assistant_whose_agent_withheld_contacts_sees_no_contacts_at_all(): void
    {
        $hers   = $this->contactFor($this->agent, 'Nomsa', 'Dlamini');
        $theirs = $this->contactFor($this->otherAgent, 'Willem', 'Botha');

        // The agent takes contacts back off the matrix.
        AssistantAssignmentPermission::where('assistant_assignment_id', $this->assignment->id)
            ->where('permission_key', 'contacts.view')
            ->update(['granted' => false]);

        $this->actingAs(User::find($this->assistant->id));
        $this->reset();

        $this->assertCount(0, Contact::pluck('id'), 'A withheld module must show NOTHING, not everything.');
    }

    public function test_a_normal_agent_is_completely_unaffected(): void
    {
        $hers   = $this->propertyFor($this->agent, 'Marine Drive');
        $theirs = $this->propertyFor($this->otherAgent, 'Panorama Parade');

        // dataIdentityIds() returns [self] for a non-assistant, so the sweep across 19 models
        // must be a no-op for every ordinary user in the system. This is the assertion that
        // protects the other 99% of users from Prompt D.
        $sarahSees = Property::visibleTo(User::find($this->agent->id))->pluck('id');
        $this->assertTrue($sarahSees->contains($hers->id));
        $this->assertFalse($sarahSees->contains($theirs->id));

        $pieterSees = Property::visibleTo(User::find($this->otherAgent->id))->pluck('id');
        $this->assertTrue($pieterSees->contains($theirs->id));
        $this->assertFalse($pieterSees->contains($hers->id));
    }

    public function test_each_assistant_is_confined_to_their_own_agents_book(): void
    {
        $sarahs  = $this->propertyFor($this->agent, 'Marine Drive');
        $pieters = $this->propertyFor($this->otherAgent, 'Panorama Parade');

        // Pieter gets his own assistant.
        $pietersAssistant = $this->makeUser('Rajesh Naidoo', 'assistant', isAssistant: true);
        $pietersAssignment = AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $pietersAssistant->id,
            'agent_user_id'     => $this->otherAgent->id,
        ]);
        AssistantAssignmentPermission::create([
            'agency_id'               => $this->agency->id,
            'assistant_assignment_id' => $pietersAssignment->id,
            'permission_key'          => 'properties.view',
            'granted'                 => true,
            'scope'                   => 'own',
        ]);
        $this->reset();

        $sees = Property::visibleTo(User::find($pietersAssistant->id))->pluck('id');

        $this->assertTrue($sees->contains($pieters->id), "Pieter's assistant must see Pieter's listing.");
        $this->assertFalse($sees->contains($sarahs->id), "Pieter's assistant must NOT see Sarah's listing.");
    }

    public function test_a_revoked_assistant_sees_nothing(): void
    {
        $hers = $this->propertyFor($this->agent, 'Marine Drive');

        $this->assignment->update(['status' => AssistantAssignment::STATUS_REVOKED, 'revoked_at' => now()]);
        $this->reset();

        // No live assignment → no data scope at all → whereRaw('1 = 0').
        $visible = Property::visibleTo(User::find($this->assistant->id))->pluck('id');

        $this->assertFalse($visible->contains($hers->id));
        $this->assertCount(0, $visible);
    }

    public function test_the_kill_switch_hides_the_agents_book_again(): void
    {
        $hers = $this->propertyFor($this->agent, 'Marine Drive');

        $this->agency->update(['assistants_enabled' => false]);
        $this->reset();

        $this->assertCount(0, Property::visibleTo(User::find($this->assistant->id))->pluck('id'));
    }

    public function test_the_agents_private_ellie_conversations_stay_private(): void
    {
        // PRIVATE_TO_SELF. An agent talks to Ellie the way they'd think out loud. The
        // assistant inherits the agent's BOOK, not the agent's head.
        $assistant = User::find($this->assistant->id);

        $this->assertSame([$this->agent->id, $this->assistant->id], $assistant->dataIdentityIds());

        $sql = \App\Models\AiConversation::visibleTo($assistant)->toSql();
        $bindings = \App\Models\AiConversation::visibleTo($assistant)->getBindings();

        $this->assertStringContainsString('user_id', $sql);
        $this->assertContains($this->assistant->id, $bindings, 'Ellie must be scoped to the ASSISTANT themselves.');
        $this->assertNotContains($this->agent->id, $bindings, "The agent's Ellie history must never be in an assistant's query.");
    }
}

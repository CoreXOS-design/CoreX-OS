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
use Tests\TestCase;

/**
 * AT-267 Finding 3 — assistant-created records must be OWNED BY THE AGENT (the actor stays the
 * assistant). Spec §7.2 + `.ai/audits/assistants-finding3-ownership-remediation.md`.
 *
 * The foundation test passes today (the `ownershipUserId()` helper is correct). The two
 * integration tests are the TDD targets for the ownership-routing work; they are skipped until
 * that lands (the writers do not yet call `ownershipUserId()`), so the suite stays green — delete
 * the `markTestSkipped` line to activate each when you implement the surface.
 */
final class AssistantOwnershipTest extends TestCase
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
            'status'            => AssistantAssignment::STATUS_ACTIVE,
        ]);

        $this->reset();
    }

    /**
     * Foundation — passes today. The whole of Finding 3 is "route the owner column through this".
     */
    public function test_ownershipUserId_resolves_to_the_agent_for_an_assistant(): void
    {
        $this->assertSame(
            $this->agent->id,
            $this->assistant->ownershipUserId(),
            'an assistant owns nothing of their own — their created records belong to the agent'
        );

        $this->assertSame(
            $this->agent->id,
            $this->agent->ownershipUserId(),
            'a normal user owns their own records'
        );

        // The read side is symmetric with this: the agent sees a record owned by them, so routing
        // owner→agent needs no dataIdentityIds() change.
        $this->assertContains($this->agent->id, $this->assistant->dataIdentityIds());
    }

    /**
     * THE MONEY PATH. An assistant creating a deal: commission-bearing owner = the agent.
     * DealV2Controller::store defaults listing_agent_id to auth()->id() today (DealV2Controller.php:325).
     */
    public function test_an_assistant_created_deal_attributes_to_the_agent(): void
    {
        $this->markTestSkipped(
            'Finding 3 (ownership routing) not yet implemented — see '
            . '.ai/audits/assistants-finding3-ownership-remediation.md. '
            . 'Remove this line when DealV2Controller::store routes owner columns through ownershipUserId().'
        );

        // @phpstan-ignore-next-line — activates when the skip is removed.
        $this->grant('deals_v2.create');
        $this->grant('deals_v2.capture_own');

        // Full valid deal payload per the DR2 create form — fill when activating (see ticket).
        $payload = [/* deal_type, purchase_price, listing(s), … */];

        $this->actingAs($this->assistant)->post(route('deals-v2.store'), $payload);

        $deal = \App\Models\DealV2\DealV2::withoutGlobalScopes()->latest('id')->first();

        $this->assertSame($this->agent->id, (int) $deal->listing_agent_id,
            'the deal (and its commission) must land on the AGENT, not the assistant');
        $this->assertSame($this->assistant->id, (int) $deal->created_by_id,
            'the assistant is still recorded as the actor who captured it');

        // Guardrail: an assistant cannot assign ownership to a different agent via form input.
        $this->actingAs($this->assistant)->post(route('deals-v2.store'),
            $payload + ['listing_agent_id' => $this->otherAgent->id]);
        $deal2 = \App\Models\DealV2\DealV2::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame($this->agent->id, (int) $deal2->listing_agent_id,
            'a submitted listing_agent_id from an assistant must be ignored in favour of their own agent');
    }

    /**
     * A contact an assistant captures is the AGENT's contact (agent_id), assistant is the actor.
     */
    public function test_an_assistant_created_contact_is_owned_by_the_agent(): void
    {
        $this->markTestSkipped(
            'Finding 3 (ownership routing) not yet implemented — see '
            . '.ai/audits/assistants-finding3-ownership-remediation.md. '
            . 'Remove this line when CoreX\\ContactController::store sets agent_id via ownershipUserId().'
        );

        // @phpstan-ignore-next-line
        $this->grant('contacts.create');
        // POST the contact store route (routes/web.php:3073) with a valid payload — see ticket.
        // Assert: contacts.agent_id === $this->agent->id AND contacts.created_by_user_id === $this->assistant->id.
    }

    // ── helpers ──────────────────────────────────────────────────────────────

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

    private function grant(string $key, ?string $scope = null): void
    {
        RolePermission::firstOrCreate(
            ['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id],
            ['scope' => $scope],
        );
        AssistantAssignmentPermission::updateOrCreate(
            ['assistant_assignment_id' => $this->assignment->id, 'permission_key' => $key],
            ['agency_id' => $this->agency->id, 'granted' => true, 'scope' => $scope],
        );
        $this->reset();
    }

    private function reset(): void
    {
        PermissionService::clearCache();
        Role::clearCache();
        User::flushAssistantsEnabledCache();
        PermissionService::forceProductionPosture();
    }
}

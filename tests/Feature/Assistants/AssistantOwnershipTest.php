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
 * ACTIVATED 2026-07-26. The two integration tests were written as skipped TDD targets and the
 * routing they were waiting for shipped in `d8f0b68a` — but the `markTestSkipped` lines were never
 * removed, so for five days the money path had a test that proved nothing while reading green.
 * A skipped test is not a passing test; the post-ship audit unskipped both and filled in the
 * payloads the placeholders promised.
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
        $this->grant('deals_v2.create');
        $this->grant('access_deal_register_v2');

        [$property, $template] = $this->dealFixtures();

        // No listing_agents[] — this is the branch AT-267 changed: with no explicit agent the
        // owner falls back to the capturer, and for an assistant that must resolve to the AGENT.
        $payload = [
            'property_id'              => $property->id,
            'deal_type'                => 'bond',
            'pipeline_template_id'     => $template->id,
            'purchase_price'           => 1_000_000,
            'total_commission_inc_vat' => 115_000,
            'commission_percentage'    => 7.5,
            'offer_date'               => '2026-07-01',
            'listing_split_percent'    => 100,
            'selling_split_percent'    => 0,
            'branch_id'                => $this->branch->id,
        ];

        $this->actingAs($this->assistant)->post(route('deals-v2.store'), $payload);

        $deal = \App\Models\DealV2\DealV2::withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($deal, 'the assistant must be able to capture a deal at all');
        $this->assertSame($this->agent->id, (int) $deal->listing_agent_id,
            'the deal (and its commission) must land on the AGENT, not the assistant');
        $this->assertSame($this->assistant->id, (int) $deal->created_by_id,
            'the assistant is still recorded as the actor who captured it');
    }

    /**
     * A contact an assistant captures is the AGENT's contact (agent_id), assistant is the actor.
     */
    public function test_an_assistant_created_contact_is_owned_by_the_agent(): void
    {
        $this->grant('contacts.create');
        $this->grant('access_contacts');

        // A contact must carry at least one type, and only the six FIXED parents qualify —
        // ContactType::scopeParents() matches on the canonical name+esign_role pair, so a
        // bare 'Buyer' with no esign_role is not a parent. Reference data a real DB is seeded
        // with; created here because the test schema starts empty.
        $buyerTypeId = (int) \App\Models\ContactType::create([
            'name'       => 'Buyer',
            'esign_role' => 'buyer',
            'is_active'  => true,
        ])->id;

        $this->actingAs($this->assistant)->post(route('corex.contacts.store'), [
            'first_name'      => 'Nomsa',
            'last_name'       => 'Dlamini',
            'phone'           => '0835550142',
            'parent_type_ids' => [$buyerTypeId],
        ])->assertSessionHasNoErrors();

        $contact = \App\Models\Contact::withoutGlobalScopes()->latest('id')->first();

        $this->assertNotNull($contact, 'the assistant must be able to capture a contact at all');
        $this->assertSame($this->agent->id, (int) $contact->agent_id,
            "the contact sits on the AGENT's book, not the assistant's");
        $this->assertSame($this->assistant->id, (int) $contact->created_by_user_id,
            'the assistant is still recorded as the actor who captured it');
    }

    /**
     * Fixtures for the deal capture. Built OUTSIDE the assistant's session on purpose: an
     * assistant may never create a Property (Property::creating aborts for them), which is
     * itself the AT-267 hard lock working.
     */
    private function dealFixtures(): array
    {
        $property = \App\Models\Property::withoutEvents(fn () => \App\Models\Property::withoutGlobalScopes()->create([
            'external_id' => 'T-' . \Illuminate\Support\Str::random(8),
            'title'       => '9 Forest Walk, Southbroom',
            'address'     => '9 Forest Walk, Southbroom',
            'agent_id'    => $this->agent->id,
            'branch_id'   => $this->branch->id,
            'agency_id'   => $this->agency->id,
        ]));

        app(\App\Services\DealV2\DealPipelineTemplateProvisioner::class)
            ->provisionDefaultsForAgency($this->agency->id, $this->agent->id);

        $template = \App\Models\DealV2\DealPipelineTemplate::withoutGlobalScopes()
            ->where('agency_id', $this->agency->id)->where('deal_type', 'bond')->first();

        return [$property, $template];
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

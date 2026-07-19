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
 * AT-267 §10 — the assistant's own My-Portal is stripped to identity + FICA.
 *
 * The commit that added the @unless($isAssistant) gates could not be rendered in the QA2 dev
 * lane (no HTTP/test runner there). This test renders /my-portal for an assistant and asserts
 * the financial + practitioner surfaces are gone, with a normal-agent control proving the gates
 * are inert for everyone else.
 */
final class AssistantProfileStripTest extends TestCase
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

        Role::create(['name' => 'agent', 'label' => 'Agent', 'agency_id' => $this->agency->id]);
        Role::create(['name' => 'assistant', 'label' => 'Assistant', 'agency_id' => $this->agency->id]);

        $this->agent     = $this->makeUser('Sarah Nkosi', 'agent');
        $this->assistant = $this->makeUser('Thandi Mokoena', 'assistant', isAssistant: true);

        $this->assignment = AssistantAssignment::create([
            'agency_id'         => $this->agency->id,
            'branch_id'         => $this->branch->id,
            'assistant_user_id' => $this->assistant->id,
            'agent_user_id'     => $this->agent->id,
            'status'            => AssistantAssignment::STATUS_ACTIVE,
        ]);

        // The agent holds My-Portal access; the assistant is granted it in the matrix (the
        // resolver still intersects with the agent's live permission).
        RolePermission::create(['role' => 'agent', 'permission_key' => 'access_my_portal', 'agency_id' => $this->agency->id, 'scope' => 'all']);
        $this->matrix('access_my_portal');

        $this->reset();
    }

    public function test_an_assistant_portal_hides_financial_and_practitioner_surfaces(): void
    {
        $response = $this->actingAs($this->assistant)->get(route('agent.portal'));

        $response->assertOk();

        // Financial + practitioner sections genuinely removed server-side (@unless). Each needle
        // is unique to the hidden PORTAL section — deliberately NOT labels like "My Earnings" or
        // "FFC Number" that also appear in the sidebar nav or the (not-yet-reduced) compliance
        // status rows; those are covered by test_compliance_items_are_reduced_for_an_assistant.
        foreach ([
            'Cap Progress',           // the earnings-card cap bar (overview) — sidebar has no such string
            'Public Website Profile', // agent public page (profile)
            'PPRA Status',            // practitioner status (profile)
            'Delete Account',         // password tab, admin-only for assistants
        ] as $hidden) {
            $response->assertDontSee($hidden);
        }

        // Identity surfaces the assistant SHOULD keep.
        $response->assertSee('Profile Photo');
        $response->assertSee('ID Copy');
    }

    /**
     * Finding 4a RESIDUAL — the compliance overview card + Compliance tab still list practitioner
     * items (FFC / PI / Tax) for an assistant because computeComplianceStatus() is not yet reduced
     * (audit Finding 4a, deferred to the render lane). Skip-guarded until that lands.
     */
    public function test_compliance_items_are_reduced_for_an_assistant(): void
    {
        $this->markTestSkipped(
            'Finding 4a residual — computeComplianceStatus() FFC/PI/Tax/PPRA reduction not yet '
            . 'built (see .ai/audits/assistants-feature-audit-2026-07-19.md). Remove this line when '
            . 'the compliance surfaces stop listing practitioner items for assistants.'
        );

        // @phpstan-ignore-next-line — activates when the skip is removed.
        $response = $this->actingAs($this->assistant)->get(route('agent.portal'));
        $response->assertDontSee('PI Insurance');
        $response->assertDontSee('Tax Clearance');
    }

    public function test_a_normal_agent_still_sees_everything(): void
    {
        $response = $this->actingAs($this->agent)->get(route('agent.portal'));

        $response->assertOk();
        // The gates are inert for a normal agent — the financial surfaces are still there.
        $response->assertSee('My Earnings');
        $response->assertSee('FFC Number');
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
}

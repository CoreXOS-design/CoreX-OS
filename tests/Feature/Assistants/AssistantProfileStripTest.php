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
     * Finding 4a RESIDUAL — closed 2026-07-26 (post-ship audit F9).
     *
     * An assistant is not a property practitioner: no FFC, no professional indemnity cover, no
     * practitioner tax clearance. Listing those items resolved every one of them 'red' against a
     * requirement the person can never satisfy, which pinned `overall` red and `issues_count`
     * above zero for the life of the account — an always-red card is noise, not a warning.
     *
     * Asserts the DATA, not the markup: computeComplianceStatus() is the source the card, the
     * Compliance tab, `overall` and `issues_count` all read, so reducing it in Blade alone would
     * have left the counters lying.
     */
    public function test_compliance_items_are_reduced_for_an_assistant(): void
    {
        $status = $this->complianceStatusFor($this->assistant);

        foreach (['ffc_certificate', 'pi_insurance', 'tax_clearance', 'ffc_number', 'ffc_expiry'] as $practitionerOnly) {
            $this->assertArrayNotHasKey($practitionerOnly, $status,
                "{$practitionerOnly} is a practitioner licensing item — an assistant can never hold it");
        }

        // What an assistant IS still accountable for: FICA identity, and the obligations of anyone
        // employed around client money and documents.
        $this->assertArrayHasKey('id_copy', $status, 'FICA identity still applies to an assistant');
        $this->assertArrayHasKey('rmcp_acknowledged', $status);
        $this->assertArrayHasKey('employee_screening', $status);
    }

    /** A normal agent keeps every practitioner item — the reduction must not leak sideways. */
    public function test_a_normal_agent_keeps_the_practitioner_compliance_items(): void
    {
        $status = $this->complianceStatusFor($this->agent);

        foreach (['ffc_certificate', 'pi_insurance', 'tax_clearance', 'ffc_number', 'ffc_expiry'] as $practitionerOnly) {
            $this->assertArrayHasKey($practitionerOnly, $status,
                "{$practitionerOnly} must still be assessed for a practitioner");
        }
    }

    /**
     * computeComplianceStatus() is private, so read the array the way the page does — off the
     * rendered view's data. Asserting the data rather than the HTML keeps this test honest about
     * what it proves: `overall` and `issues_count` are computed from these keys, so a Blade-only
     * hide would still leave the counters wrong and this test would still (correctly) fail.
     */
    private function complianceStatusFor(\App\Models\User $user): array
    {
        $status = $this->actingAs($user)
            ->get(route('agent.portal'))
            ->assertSuccessful()
            ->viewData('complianceStatus');

        $this->assertIsArray($status, 'the portal must expose complianceStatus to the view');

        return $status;
    }

    public function test_a_normal_agent_still_sees_everything(): void
    {
        $response = $this->actingAs($this->agent)->get(route('agent.portal'));

        $response->assertOk();
        // The gates are inert for a normal agent — the financial surfaces are still there.
        $response->assertSee('My Earnings');
        $response->assertSee('FFC Number');
    }

    /**
     * The agent-personal MY EARNINGS surface — the sidebar nav gap. Defense in depth: the route
     * is deny_assistant-guarded AND the nav is @unless-hidden, so what an assistant is shown and
     * what they can reach agree (no gap).
     */
    public function test_an_assistant_is_blocked_from_the_commission_and_revenue_surfaces(): void
    {
        // Both ungated finance routes (no feature dependency) — deny_assistant redirects the
        // assistant regardless of matrix. (commission.index/principal add deny_assistant behind
        // their feature: gate too — same middleware, proven here.)
        foreach (['commission.dashboard', 'revenue-share.calculator'] as $routeName) {
            $this->actingAs($this->assistant)
                ->get(route($routeName))
                ->assertRedirect(route('agent.portal'));
        }

        // A normal agent is NOT blocked by the middleware (it only fires for is_assistant).
        $agentResponse = $this->actingAs($this->agent)->get(route('commission.dashboard'));
        $this->assertFalse(
            $agentResponse->isRedirect(route('agent.portal')),
            'a normal agent must still reach their own My Earnings'
        );
    }

    public function test_the_sidebar_hides_the_my_earnings_link_from_an_assistant(): void
    {
        $this->actingAs($this->assistant)
            ->get(route('agent.portal'))
            ->assertDontSee(route('commission.dashboard'));
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

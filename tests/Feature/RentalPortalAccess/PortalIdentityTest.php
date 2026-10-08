<?php

declare(strict_types=1);

namespace Tests\Feature\RentalPortalAccess;

use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\RentalFaultReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * Johan, 8 Oct 2026 - "the owner link opens the tenant's information". Two separate sessions on one lease: the owner's session is
 * the owner's (role, tabs data, faults, decision) and the tenant's is the tenant's - nothing of the other side crosses over - and
 * the page itself says who is signed in, offers the Tenant / Owner switch, and has the card for a link made for somebody else.
 */
final class PortalIdentityTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private ClientUser $tenantLogin;
    private ClientUser $ownerLogin;
    private RentalFaultReport $fault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        $this->tenant->forceFill(['phone' => '0731110001'])->saveQuietly();
        $this->landlord->forceFill(['phone' => '0829990002'])->saveQuietly();
        $this->tenantLogin = $this->clientUserFor($this->tenant);
        $this->ownerLogin = $this->clientUserFor($this->landlord);

        // The tenant reports it in her own words; the agent writes the owner's version and a private note, and sends it.
        $this->fault = $this->faultReport(['title' => 'Geyser not heating', 'description' => 'TENANT-ORIGINAL-WORDS no hot water since Monday']);
        $this->fault->saveOwnerVersion(['owner_title' => 'Geyser fault', 'owner_description' => 'AGENT-VERSION for the owner', 'owner_agent_note' => 'AGENT-PRIVATE-NOTE electrician needed'], $this->admin);
        $this->fault->fresh()->requestApproval($this->admin);
    }

    private function as(ClientUser $login): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($login->fresh(), ['client']);
    }

    public function test_the_tenants_session_is_the_tenants_and_the_owners_session_is_the_owners(): void
    {
        $this->as($this->tenantLogin);
        $this->assertCount(1, $this->getJson('/api/v1/client/rentals/leases')->assertOk()->json('leases'));
        $this->assertCount(0, $this->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->json('properties'), 'a tenant is not an owner');
        $this->assertCount(0, $this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->json('fault_reports'));
        $this->assertSame(0, $this->getJson('/api/v1/client/rentals/landlord/overview')->assertOk()->json('decisions_waiting') ?? 0);
        $this->assertSame('tenant', $this->getJson('/api/v1/client/rentals/overview')->assertOk()->json('role'));

        $this->as($this->ownerLogin);
        $this->assertCount(0, $this->getJson('/api/v1/client/rentals/leases')->assertOk()->json('leases'), 'an owner is not a tenant');
        $this->assertCount(1, $this->getJson('/api/v1/client/rentals/landlord/properties')->assertOk()->json('properties'));
        $this->assertCount(0, $this->getJson('/api/v1/client/rentals/fault-reports')->assertOk()->json('fault_reports'), 'no "My faults" for an owner');
        $this->assertSame('landlord', $this->getJson('/api/v1/client/rentals/landlord/overview')->assertOk()->json('role'));
    }

    public function test_the_owner_sees_the_fault_waiting_for_a_decision_and_the_tenant_does_not_see_the_decision(): void
    {
        $this->as($this->ownerLogin);
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/fault-reports')->assertOk()->json('fault_reports'))->firstWhere('id', $this->fault->id);
        $this->assertTrue($row['needs_decision']);
        $detail = $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}")->assertOk()->json('fault_report');
        $this->assertTrue($detail['awaiting_decision']);
        $this->assertSame('AGENT-VERSION for the owner', $detail['description']);
        $this->assertSame('AGENT-PRIVATE-NOTE electrician needed', $detail['agent_note'], 'the agent\'s note is for the owner, as written');

        $this->as($this->tenantLogin);
        $this->getJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}")->assertNotFound();
        $this->getJson("/api/v1/client/rentals/landlord/decisions")->assertOk()->assertJsonCount(0, 'fault_reports');
        $this->postJson("/api/v1/client/rentals/landlord/fault-reports/{$this->fault->id}/decision", ['decision' => 'approve', 'handled_by' => 'agency'])->assertStatus(404);
    }

    public function test_nothing_of_the_other_side_crosses_over_in_either_direction(): void
    {
        $this->as($this->ownerLogin);
        $ownerBody = '';
        foreach (['client/me', 'client/rentals/landlord/overview', 'client/rentals/landlord/properties', 'client/rentals/landlord/fault-reports', "client/rentals/landlord/fault-reports/{$this->fault->id}",
                  'client/rentals/landlord/decisions', 'client/rentals/landlord/work-orders', 'client/rentals/landlord/documents', 'client/rentals/leases', 'client/rentals/fault-reports'] as $ep) {
            $ownerBody .= $this->getJson('/api/v1/' . $ep)->getContent();
        }
        $this->assertStringNotContainsString('TENANT-ORIGINAL-WORDS', $ownerBody, 'the tenant\'s own words never reach the owner');
        $this->assertStringNotContainsString('0731110001', $ownerBody, 'no tenant phone');
        $this->assertStringNotContainsString($this->tenant->email, $ownerBody, 'no tenant email');

        $this->as($this->tenantLogin);
        $tenantBody = '';
        foreach (['client/me', 'client/rentals/overview', 'client/rentals/leases', 'client/rentals/fault-reports', "client/rentals/fault-reports/{$this->fault->id}", 'client/rentals/work-orders', 'client/rentals/documents',
                  'client/rentals/landlord/properties', 'client/rentals/landlord/fault-reports', 'client/rentals/landlord/decisions'] as $ep) {
            $tenantBody .= $this->getJson('/api/v1/' . $ep)->getContent();
        }
        $this->assertStringNotContainsString('AGENT-PRIVATE-NOTE', $tenantBody, 'the agent\'s note to the owner never reaches the tenant');
        $this->assertStringNotContainsString('AGENT-VERSION', $tenantBody, 'nor the owner\'s version');
        $this->assertStringNotContainsString('0829990002', $tenantBody, 'no owner phone');
        $this->assertStringNotContainsString($this->landlord->email, $tenantBody, 'no owner email');
    }

    public function test_the_page_has_the_who_line_the_switch_and_the_card_for_a_link_made_for_somebody_else(): void
    {
        $html = $this->get('/portal?email=' . rawurlencode('somebody@example.com'))->assertOk()->getContent();

        $this->assertStringContainsString('data-portal-who', $html);
        $this->assertStringContainsString('Signed in as', $html);
        $this->assertStringContainsString('data-who-name', $html);
        $this->assertStringContainsString('data-who-role', $html);
        $this->assertStringContainsString('data-role-switch', $html);
        $this->assertStringContainsString('data-role-tenant>Tenant<', $html);
        $this->assertStringContainsString('data-role-owner>Owner<', $html);
        $this->assertStringContainsString('data-link-mismatch', $html);
        $this->assertStringContainsString('This link is for', $html);
        $this->assertStringContainsString('You are signed in as', $html);
        $this->assertStringContainsString('Sign out and sign in as', $html);
        // The old, vague tab names are gone.
        $this->assertStringNotContainsString('My Tenancy', $html);
        $this->assertStringNotContainsString('My Properties', $html);
    }
}

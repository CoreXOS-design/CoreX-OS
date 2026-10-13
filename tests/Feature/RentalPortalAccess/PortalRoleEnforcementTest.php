<?php

namespace Tests\Feature\RentalPortalAccess;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\RentalFaultReport;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA demo tabs (.ai/specs/rental-portal-access.md "QA demo sign-in"): owner and tenant are two tabs of ONE browser on ONE login. Each tab sends
 * `X-Portal-Role`; the server refuses a call that belongs to the other side, so a tenant tab can never be handed owner-only data and an owner tab
 * can never act as the tenant. A request with no header (the mobile app, every normal tab) behaves exactly as before.
 */
final class PortalRoleEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private ClientUser $login;
    private Contact $tenant;
    private Contact $owner;
    private RentalFaultReport $fault;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Role Agency', 'slug' => 'ro-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main', 'code' => 'M-' . $agency->id, 'is_active' => true]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate(['agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'title' => 'RO Unit', 'status' => 'active', 'listing_type' => 'rental']);
        $lease = Lease::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000, 'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual']);
        $this->login = ClientUser::create(['email' => 'both-' . uniqid() . '@example.invalid', 'current_agency_id' => $agency->id, 'created_by_agency_id' => $agency->id]);
        $this->tenant = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Tess', 'last_name' => 'Tenant', 'email' => $this->login->email, 'client_user_id' => $this->login->id]);
        $this->owner = Contact::withoutGlobalScope(AgencyScope::class)->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'Olly', 'last_name' => 'Owner', 'email' => $this->login->email, 'client_user_id' => $this->login->id]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $this->tenant->id, 'is_primary' => true]);
        $property->contacts()->attach($this->owner->id, ['role' => 'landlord']);
        $this->fault = RentalFaultReport::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id, 'lease_id' => $lease->id, 'reported_by_type' => 'tenant', 'reported_by_contact_id' => $this->tenant->id,
            'reported_channel' => 'app', 'title' => 'Tap', 'description' => 'drips', 'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(), 'created_by_user_id' => $agent->id,
        ]);
        Sanctum::actingAs($this->login, ['client']);
    }

    private const OWNER_PATHS = ['/landlord/work-orders', '/landlord/fault-reports', '/landlord/decisions', '/landlord/properties'];
    private const TENANT_PATHS = ['/work-orders', '/fault-reports', '/leases', '/overview'];

    public function test_a_tenant_tab_is_refused_every_owner_call_and_still_gets_its_own(): void
    {
        foreach (self::OWNER_PATHS as $p) {
            $this->getJson('/api/v1/client/rentals' . $p, ['X-Portal-Role' => 'tenant'])->assertStatus(403)->assertJsonPath('role_mismatch', true);
        }
        foreach (self::TENANT_PATHS as $p) {
            $this->getJson('/api/v1/client/rentals' . $p, ['X-Portal-Role' => 'tenant'])->assertOk();
        }
    }

    public function test_an_owner_tab_is_refused_every_tenant_call_and_still_gets_its_own(): void
    {
        foreach (self::TENANT_PATHS as $p) {
            $this->getJson('/api/v1/client/rentals' . $p, ['X-Portal-Role' => 'owner'])->assertStatus(403)->assertJsonPath('role_mismatch', true);
        }
        foreach (self::OWNER_PATHS as $p) {
            $this->getJson('/api/v1/client/rentals' . $p, ['X-Portal-Role' => 'owner'])->assertOk();
        }
    }

    public function test_the_two_sides_of_one_login_are_two_different_people(): void
    {
        $tenantView = $this->getJson('/api/v1/client/rentals/fault-reports', ['X-Portal-Role' => 'tenant'])->assertOk();
        $this->assertSame([$this->fault->id], collect($tenantView->json('fault_reports'))->pluck('id')->all());
        // the unsent fault is the tenant's alone: the owner tab does not see it (owner-visibility rule)
        $ownerView = $this->getJson('/api/v1/client/rentals/landlord/fault-reports', ['X-Portal-Role' => 'owner'])->assertOk();
        $this->assertSame([], collect($ownerView->json('fault_reports'))->pluck('id')->all());
    }

    public function test_neutral_calls_work_for_either_side_and_no_header_means_no_change(): void
    {
        foreach (['tenant', 'owner'] as $role) {
            $this->getJson('/api/v1/client/rentals/branding', ['X-Portal-Role' => $role])->assertOk();
        }
        foreach (array_merge(self::OWNER_PATHS, self::TENANT_PATHS) as $p) {
            $this->getJson('/api/v1/client/rentals' . $p)->assertOk();   // no header: exactly as before
        }
        // a header that is not a side is ignored
        $this->getJson('/api/v1/client/rentals/landlord/work-orders', ['X-Portal-Role' => 'nonsense'])->assertOk();
    }

    public function test_the_shell_sends_the_role_and_keeps_it_per_tab(): void
    {
        $shell = file_get_contents(base_path('resources/views/rentals/portal/shell.blade.php'));
        $this->assertStringContainsString("headers['X-Portal-Role']", $shell);
        $this->assertStringContainsString("xhr.setRequestHeader('X-Portal-Role'", $shell);
        // a demo tab keeps its side in sessionStorage (per tab) and never writes it to localStorage
        $this->assertStringContainsString("sessionStorage.setItem('portal.demo.role'", $shell);
        $this->assertStringContainsString('rememberRole(role) { try { if (this.demoTab) return;', $shell);
        // and asks only about its own side
        $this->assertStringContainsString("only === 'owner' ? { ok: false }", $shell);
    }
}

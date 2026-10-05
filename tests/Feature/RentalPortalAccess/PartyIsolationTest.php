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
 * AT-445 — .ai/specs/rental-portal-access.md §6/§11. Party isolation
 * through the real HTTP routes: a tenant never resolves another tenant's
 * records by id or by list, a landlord never resolves another landlord's
 * property, and cross-agency access 404s — never a bare Model::find().
 */
class PartyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $name): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . '-' . uniqid())]);
        Branch::create(['agency_id' => $agency->id, 'name' => 'Main', 'code' => 'M-' . $agency->id, 'is_active' => true]);

        return $agency;
    }

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branchId,
            'first_name' => 'Test', 'last_name' => 'Contact', 'email' => 'test+' . uniqid() . '@example.com',
        ], $overrides));
    }

    private function makeProperty(Agency $agency, User $agent, string $title): Property
    {
        return Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => Branch::where('agency_id', $agency->id)->value('id'),
            'title' => $title, 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function makeLease(Agency $agency, Property $property): Lease
    {
        return Lease::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'branch_id' => $property->branch_id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 9000, 'deposit_amount' => 9000,
            'start_date' => now()->subMonth(), 'is_month_to_month' => true, 'lease_type' => 'residential', 'source' => 'manual',
        ]);
    }

    private function clientUserFor(Contact $contact): ClientUser
    {
        $clientUser = ClientUser::create(['email' => $contact->email, 'current_agency_id' => $contact->agency_id]);
        $contact->forceFill(['client_user_id' => $clientUser->id])->saveQuietly();

        return $clientUser;
    }

    public function test_tenant_cannot_see_another_tenants_lease_by_id_or_in_list(): void
    {
        $agency = $this->makeAgency('Isolation Agency A');
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);

        $propertyA = $this->makeProperty($agency, $agent, 'Unit A');
        $propertyB = $this->makeProperty($agency, $agent, 'Unit B');
        $leaseA = $this->makeLease($agency, $propertyA);
        $leaseB = $this->makeLease($agency, $propertyB);

        $tenantA = $this->makeContact($agency, ['first_name' => 'Tina']);
        $tenantB = $this->makeContact($agency, ['first_name' => 'Bob']);
        LeaseTenant::create(['lease_id' => $leaseA->id, 'contact_id' => $tenantA->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $leaseB->id, 'contact_id' => $tenantB->id, 'is_primary' => true]);

        $clientA = $this->clientUserFor($tenantA);
        Sanctum::actingAs($clientA, ['client']);

        $list = $this->getJson('/api/v1/client/rentals/leases');
        $list->assertOk();
        $ids = collect($list->json('leases'))->pluck('id')->all();
        $this->assertContains($leaseA->id, $ids);
        $this->assertNotContains($leaseB->id, $ids, 'Tenant A\'s lease list leaked tenant B\'s lease.');

        $this->getJson('/api/v1/client/rentals/leases/' . $leaseA->id)->assertOk();
        $this->getJson('/api/v1/client/rentals/leases/' . $leaseB->id)->assertStatus(404);
    }

    public function test_tenant_cannot_see_another_tenants_fault_report_by_id_or_in_list(): void
    {
        $agency = $this->makeAgency('Isolation Agency B');
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $propertyA = $this->makeProperty($agency, $agent, 'Unit A');
        $propertyB = $this->makeProperty($agency, $agent, 'Unit B');
        $leaseA = $this->makeLease($agency, $propertyA);
        $leaseB = $this->makeLease($agency, $propertyB);
        $tenantA = $this->makeContact($agency, ['first_name' => 'Tina']);
        $tenantB = $this->makeContact($agency, ['first_name' => 'Bob']);
        LeaseTenant::create(['lease_id' => $leaseA->id, 'contact_id' => $tenantA->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $leaseB->id, 'contact_id' => $tenantB->id, 'is_primary' => true]);

        $faultA = RentalFaultReport::create([
            'agency_id' => $agency->id, 'branch_id' => $propertyA->branch_id, 'property_id' => $propertyA->id, 'lease_id' => $leaseA->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenantA->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Tenant A leak', 'description' => 'leak',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);
        $faultB = RentalFaultReport::create([
            'agency_id' => $agency->id, 'branch_id' => $propertyB->branch_id, 'property_id' => $propertyB->id, 'lease_id' => $leaseB->id,
            'reported_by_type' => RentalFaultReport::REPORTED_BY_TENANT, 'reported_by_contact_id' => $tenantB->id,
            'reported_channel' => RentalFaultReport::CHANNEL_APP, 'title' => 'Tenant B leak', 'description' => 'leak',
            'status' => RentalFaultReport::STATUS_REPORTED, 'owner_approval_status' => RentalFaultReport::APPROVAL_NOT_REQUIRED, 'reported_at' => now(),
        ]);

        Sanctum::actingAs($this->clientUserFor($tenantA), ['client']);

        $list = $this->getJson('/api/v1/client/rentals/fault-reports');
        $ids = collect($list->json('fault_reports'))->pluck('id')->all();
        $this->assertContains($faultA->id, $ids);
        $this->assertNotContains($faultB->id, $ids);

        $this->getJson('/api/v1/client/rentals/fault-reports/' . $faultA->id)->assertOk();
        $this->getJson('/api/v1/client/rentals/fault-reports/' . $faultB->id)->assertStatus(404);
    }

    public function test_landlord_cannot_see_another_landlords_property_by_id_or_in_list(): void
    {
        $agency = $this->makeAgency('Isolation Agency C');
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $propertyA = $this->makeProperty($agency, $agent, 'Landlord A Unit');
        $propertyB = $this->makeProperty($agency, $agent, 'Landlord B Unit');

        $landlordA = $this->makeContact($agency, ['first_name' => 'Lenny']);
        $landlordB = $this->makeContact($agency, ['first_name' => 'Larry']);
        $propertyA->contacts()->attach($landlordA->id, ['role' => 'landlord']);
        $propertyB->contacts()->attach($landlordB->id, ['role' => 'landlord']);

        Sanctum::actingAs($this->clientUserFor($landlordA), ['client']);

        $list = $this->getJson('/api/v1/client/rentals/landlord/properties');
        $list->assertOk();
        $ids = collect($list->json('properties'))->pluck('id')->all();
        $this->assertContains($propertyA->id, $ids);
        $this->assertNotContains($propertyB->id, $ids, 'Landlord A\'s property list leaked landlord B\'s property.');

        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $propertyA->id)->assertOk();
        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $propertyB->id)->assertStatus(404);
    }

    public function test_cross_agency_contact_gets_404_not_another_agencys_lease(): void
    {
        $agencyA = $this->makeAgency('Isolation Agency D1');
        $agencyB = $this->makeAgency('Isolation Agency D2');
        $agent = User::factory()->create(['agency_id' => $agencyA->id, 'role' => 'admin']);
        $property = $this->makeProperty($agencyA, $agent, 'Agency A Unit');
        $lease = $this->makeLease($agencyA, $property);
        $tenantA = $this->makeContact($agencyA, ['first_name' => 'Tina']);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantA->id, 'is_primary' => true]);

        // A contact who only exists in agency B, with their own ClientUser.
        $contactB = $this->makeContact($agencyB, ['first_name' => 'Foreign']);
        $clientB = ClientUser::create(['email' => $contactB->email, 'current_agency_id' => $agencyB->id]);
        $contactB->forceFill(['client_user_id' => $clientB->id])->saveQuietly();

        Sanctum::actingAs($clientB, ['client']);

        $this->getJson('/api/v1/client/rentals/leases/' . $lease->id)->assertStatus(404);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/v1/client/rentals/leases')->assertStatus(401);
    }

    /** §15 (AT-447 follow-up) — a landlord can only raise work on a property they own. */
    public function test_landlord_cannot_request_work_on_a_property_they_do_not_own(): void
    {
        $agency = $this->makeAgency('Isolation Agency E');
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $propertyA = $this->makeProperty($agency, $agent, 'Landlord A Unit');
        $propertyB = $this->makeProperty($agency, $agent, 'Landlord B Unit');

        $landlordA = $this->makeContact($agency, ['first_name' => 'Lenny']);
        $landlordB = $this->makeContact($agency, ['first_name' => 'Larry']);
        $propertyA->contacts()->attach($landlordA->id, ['role' => 'landlord']);
        $propertyB->contacts()->attach($landlordB->id, ['role' => 'landlord']);

        Sanctum::actingAs($this->clientUserFor($landlordA), ['client']);

        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $propertyA->id . '/fault-reports', [
            'title' => 'My own roof leak',
        ])->assertStatus(201);

        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $propertyB->id . '/fault-reports', [
            'title' => 'Trying to raise work on a property I do not own',
        ])->assertStatus(404);

        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->where('property_id', $propertyB->id)->count(), 'The rejected request must not have created a fault report on property B.');
    }

    /** §15 (AT-447, portal frontend follow-up) — the fault-type picker behind "Request work" carries the same ownership gate. */
    public function test_landlord_cannot_load_fault_types_for_a_property_they_do_not_own(): void
    {
        $agency = $this->makeAgency('Isolation Agency G');
        $agent = User::factory()->create(['agency_id' => $agency->id, 'role' => 'admin']);
        $propertyA = $this->makeProperty($agency, $agent, 'Landlord A Unit');
        $propertyB = $this->makeProperty($agency, $agent, 'Landlord B Unit');

        $landlordA = $this->makeContact($agency, ['first_name' => 'Lenny']);
        $landlordB = $this->makeContact($agency, ['first_name' => 'Larry']);
        $propertyA->contacts()->attach($landlordA->id, ['role' => 'landlord']);
        $propertyB->contacts()->attach($landlordB->id, ['role' => 'landlord']);

        Sanctum::actingAs($this->clientUserFor($landlordA), ['client']);

        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $propertyA->id . '/fault-types')->assertOk();
        $this->getJson('/api/v1/client/rentals/landlord/properties/' . $propertyB->id . '/fault-types')->assertStatus(404);
    }

    /** §11 — cross-agency: a landlord contact in agency B must never reach agency A's property. */
    public function test_cross_agency_landlord_cannot_request_work_on_another_agencys_property(): void
    {
        $agencyA = $this->makeAgency('Isolation Agency F1');
        $agencyB = $this->makeAgency('Isolation Agency F2');
        $agentA = User::factory()->create(['agency_id' => $agencyA->id, 'role' => 'admin']);
        $propertyA = $this->makeProperty($agencyA, $agentA, 'Agency A Unit');

        $landlordB = $this->makeContact($agencyB, ['first_name' => 'Foreign']);
        $clientB = ClientUser::create(['email' => $landlordB->email, 'current_agency_id' => $agencyB->id]);
        $landlordB->forceFill(['client_user_id' => $clientB->id])->saveQuietly();

        Sanctum::actingAs($clientB, ['client']);

        $this->postJson('/api/v1/client/rentals/landlord/properties/' . $propertyA->id . '/fault-reports', [
            'title' => 'Cross-agency attempt',
        ])->assertStatus(404);

        $this->assertSame(0, RentalFaultReport::withoutGlobalScopes()->where('property_id', $propertyA->id)->count());
    }
}

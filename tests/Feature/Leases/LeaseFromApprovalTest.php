<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md §1.3a — conductor ruling 2026-09-15, "C": linking an
 * approved tenant to a property now also creates the Lease record, in the
 * same request, capturing rent/deposit/dates. Explicit carve-out: property
 * status is never touched — that ruling stays pending with Johan.
 */
final class LeaseFromApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_linking_an_approved_tenant_creates_and_activates_a_lease(): void
    {
        [$user, $application, $property] = $this->makeApprovedApplication();
        $originalStatus = $property->status;

        $response = $this->actingAs($user)->post(
            route('corex.rental-applications.link-tenant-property', $application),
            [
                'property_id' => $property->id,
                'rental_amount' => 9500,
                'deposit_amount' => 9500,
                'lease_start_date' => '2026-06-01',
                'lease_end_date' => '2027-05-31',
            ]
        );

        $response->assertRedirect();

        $lease = Lease::where('rental_application_id', $application->id)->first();
        self::assertNotNull($lease, 'Expected a Lease to be created for the approved application.');
        self::assertSame($property->id, $lease->property_id);
        self::assertSame('active', $lease->status);
        self::assertSame('rental_application', $lease->source);
        self::assertSame('9500.00', $lease->rental_amount);
        self::assertSame('9500.00', $lease->deposit_amount);
        self::assertSame('2026-06-01', $lease->start_date->toDateString());
        self::assertSame('2027-05-31', $lease->end_date->toDateString());
        self::assertCount(1, $lease->tenants);
        self::assertSame($application->contact_id, $lease->tenants->first()->contact_id);

        // The carve-out: property status must be completely untouched.
        self::assertSame($originalStatus, $property->fresh()->status);
    }

    public function test_relinking_the_same_application_does_not_create_a_second_lease(): void
    {
        [$user, $application, $property] = $this->makeApprovedApplication();

        $payload = [
            'property_id' => $property->id,
            'rental_amount' => 8000,
            'lease_start_date' => '2026-01-01',
        ];

        $this->actingAs($user)->post(route('corex.rental-applications.link-tenant-property', $application), $payload);
        $this->actingAs($user)->delete(route('corex.rental-applications.unlink-tenant-property', $application));
        $this->actingAs($user)->post(route('corex.rental-applications.link-tenant-property', $application), $payload);

        self::assertSame(1, Lease::where('rental_application_id', $application->id)->count());
    }

    public function test_missing_rental_amount_is_rejected_when_start_date_was_given(): void
    {
        [$user, $application, $property] = $this->makeApprovedApplication();

        $this->actingAs($user)->post(route('corex.rental-applications.link-tenant-property', $application), [
            'property_id' => $property->id,
            'lease_start_date' => '2026-01-01',
        ])->assertSessionHasErrors('rental_amount');

        self::assertNull(Lease::where('rental_application_id', $application->id)->first());
    }

    /**
     * 2026-09-16 REGRESSION, found by cc1's baseline check — the first
     * version of this feature made rental_amount/lease_start_date required
     * on this endpoint, silently breaking every pre-existing caller that
     * only ever sent property_id (see
     * RentalApplicationTenantPropertyLinkTest, the pre-existing suite this
     * fix restores to passing unweakened). This is the explicit "without
     * lease terms" shape the fix requires: the tenant link must still work,
     * and no Lease may be created.
     */
    public function test_linking_without_any_lease_terms_links_the_tenant_and_creates_no_lease(): void
    {
        [$user, $application, $property] = $this->makeApprovedApplication();

        $response = $this->actingAs($user)->post(
            route('corex.rental-applications.link-tenant-property', $application),
            ['property_id' => $property->id]
        );

        $response->assertRedirect();
        self::assertTrue(
            $application->contact->properties()->wherePivot('role', 'tenant')->where('properties.id', $property->id)->exists(),
            'The tenant link itself must still work with no lease terms submitted.'
        );
        self::assertNull(Lease::where('rental_application_id', $application->id)->first());
    }

    /** @return array{0: User, 1: RentalApplication, 2: Property} */
    private function makeApprovedApplication(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Approved', 'last_name' => 'Tenant',
            'email' => 'tenant-' . uniqid() . '@example.test',
        ]);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $application = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'contact_id' => $contact->id, 'created_by_user_id' => $user->id,
            'status' => 'approved', 'approved_rental_amount' => 9500.00,
            'current_generation' => 1, 'token' => 'test-token-' . uniqid(),
        ]);

        return [$user, $application, $property];
    }
}

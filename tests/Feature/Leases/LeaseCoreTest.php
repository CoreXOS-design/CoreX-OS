<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Lease;
use App\Models\LeaseEscalation;
use App\Models\LeaseTenant;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\LeaseActivationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * .ai/specs/leases.md — leases as the spine of rentals. Covers the core
 * checklist Johan named explicitly: N-party tenants, escalation as a rate,
 * no overlapping active leases, renewal as a new linked row, and agency
 * scoping.
 */
final class LeaseCoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_lease_can_have_multiple_joint_tenants(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();

        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        $tenantOne = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'A', 'last_name' => 'One', 'email' => 'a-' . uniqid() . '@example.test']);
        $tenantTwo = Contact::create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'first_name' => 'B', 'last_name' => 'Two', 'email' => 'b-' . uniqid() . '@example.test']);

        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantOne->id, 'is_primary' => true]);
        LeaseTenant::create(['lease_id' => $lease->id, 'contact_id' => $tenantTwo->id, 'is_primary' => false]);

        $lease->refresh();
        self::assertCount(2, $lease->tenants);
        self::assertStringContainsString('A One', $lease->tenantNames());
        self::assertStringContainsString('B Two', $lease->tenantNames());
    }

    public function test_escalation_stores_a_computed_rate_alongside_the_amount(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, ['rental_amount' => 10000]));

        $rate = LeaseEscalation::computeRatePercent(10000, 10800);
        self::assertSame(8.0, $rate);

        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => now()->toDateString(),
            'previous_rental_amount' => 10000,
            'new_rental_amount' => 10800,
            'escalation_rate_percent' => $rate,
            'created_by_user_id' => null,
        ]);

        $escalation = $lease->escalations()->first();
        self::assertNotNull($escalation);
        self::assertEquals(8.0, (float) $escalation->escalation_rate_percent);
        self::assertEquals(10000, (float) $escalation->previous_rental_amount);
        self::assertEquals(10800, (float) $escalation->new_rental_amount);
    }

    public function test_activating_a_second_lease_on_the_same_property_is_refused(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();

        $leaseOne = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        app(LeaseActivationService::class)->activate($leaseOne);
        self::assertSame(Lease::STATUS_ACTIVE, $leaseOne->fresh()->status);

        $leaseTwo = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'start_date' => now()->addMonth()->toDateString(),
        ]));

        $this->expectException(ValidationException::class);
        app(LeaseActivationService::class)->activate($leaseTwo);
    }

    public function test_renewal_expires_the_previous_lease_and_chains_the_pointers(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();

        $original = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));
        app(LeaseActivationService::class)->activate($original);

        $renewal = Lease::create($this->baseLeaseAttributes($agency, $branch, $property, [
            'start_date' => now()->addYear()->toDateString(),
            'previous_lease_id' => $original->id,
        ]));

        app(LeaseActivationService::class)->activate($renewal);

        self::assertSame(Lease::STATUS_EXPIRED, $original->fresh()->status);
        self::assertSame($renewal->id, $original->fresh()->renewed_lease_id);
        self::assertSame(Lease::STATUS_ACTIVE, $renewal->fresh()->status);
        self::assertSame($original->id, $renewal->fresh()->previous_lease_id);
    }

    public function test_a_lease_with_escalation_history_is_not_deletable(): void
    {
        [$agency, $branch, $property] = $this->makeAgencyBranchProperty();
        $lease = Lease::create($this->baseLeaseAttributes($agency, $branch, $property));

        self::assertTrue($lease->isDeletable());

        LeaseEscalation::create([
            'lease_id' => $lease->id,
            'effective_date' => now()->toDateString(),
            'previous_rental_amount' => 8000,
            'new_rental_amount' => 8500,
            'escalation_rate_percent' => LeaseEscalation::computeRatePercent(8000, 8500),
        ]);

        self::assertFalse($lease->fresh()->isDeletable());
    }

    public function test_lease_is_scoped_to_its_own_agency(): void
    {
        [$agencyA, $branchA, $propertyA] = $this->makeAgencyBranchProperty();
        [$agencyB, $branchB, $propertyB] = $this->makeAgencyBranchProperty();

        Lease::create($this->baseLeaseAttributes($agencyA, $branchA, $propertyA));
        Lease::create($this->baseLeaseAttributes($agencyB, $branchB, $propertyB));

        $userA = User::factory()->create(['agency_id' => $agencyA->id, 'branch_id' => $branchA->id, 'role' => 'admin']);

        $this->actingAs($userA);
        self::assertSame(1, Lease::count());
    }

    /** @return array{0: Agency, 1: Branch, 2: Property} */
    private function makeAgencyBranchProperty(): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(),
            'status' => 'active', 'listing_type' => 'rental',
        ]);

        return [$agency, $branch, $property];
    }

    private function baseLeaseAttributes(Agency $agency, Branch $branch, Property $property, array $overrides = []): array
    {
        return array_merge([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'property_id' => $property->id,
            'status' => Lease::STATUS_DRAFT,
            'rental_amount' => 9000,
            'start_date' => now()->toDateString(),
            'source' => 'manual',
        ], $overrides);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Leases;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/leases.md — full CRUD floor: deposit/end date/lease type must
 * be editable after creation. Two real bugs were caught only by walking the
 * actual edit form in a browser-equivalent HTTP flow, not by code review or
 * this test suite alone: (1) 'after:start_date' referenced a request field
 * the edit form never submits; (2) `$validated['x'] ?? $old` silently
 * ignored an explicit clear (empty deposit, unchecked month-to-month).
 * Both are covered explicitly below.
 */
final class LeaseEditTest extends TestCase
{
    use RefreshDatabase;

    public function test_deposit_end_date_and_lease_type_can_be_updated(): void
    {
        [$user, $lease] = $this->makeUserAndActiveLease();

        $this->actingAs($user)->put(route('corex.leases.update', $lease), [
            'deposit_amount' => 8500,
            'end_date' => '2027-01-01',
            'lease_type' => 'Gross',
            'is_month_to_month' => '1',
        ])->assertRedirect(route('corex.leases.show', $lease));

        $lease->refresh();
        self::assertSame('8500.00', $lease->deposit_amount);
        self::assertSame('2027-01-01', $lease->end_date->toDateString());
        self::assertSame('Gross', $lease->lease_type);
        self::assertTrue($lease->is_month_to_month);
    }

    public function test_clearing_the_deposit_actually_clears_it(): void
    {
        [$user, $lease] = $this->makeUserAndActiveLease(['deposit_amount' => 5000]);

        $this->actingAs($user)->put(route('corex.leases.update', $lease), [
            'deposit_amount' => '',
            'end_date' => '2027-01-01',
        ]);

        self::assertNull($lease->refresh()->deposit_amount);
    }

    public function test_unchecking_month_to_month_actually_unchecks_it(): void
    {
        [$user, $lease] = $this->makeUserAndActiveLease(['is_month_to_month' => true]);

        $this->actingAs($user)->put(route('corex.leases.update', $lease), [
            // is_month_to_month omitted entirely, as an unchecked HTML checkbox would be.
        ]);

        self::assertFalse($lease->refresh()->is_month_to_month);
    }

    public function test_end_date_before_start_date_is_rejected(): void
    {
        [$user, $lease] = $this->makeUserAndActiveLease(['start_date' => '2026-06-01']);

        $this->actingAs($user)->put(route('corex.leases.update', $lease), [
            'end_date' => '2026-01-01',
        ])->assertSessionHasErrors('end_date');
    }

    /** @return array{0: User, 1: Lease} */
    private function makeUserAndActiveLease(array $leaseOverrides = []): array
    {
        $agency = Agency::create(['name' => 'Agency ' . uniqid(), 'slug' => 'agency-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Branch A']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $user->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'listing_type' => 'rental',
        ]);

        $lease = Lease::create(array_merge([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => 'active', 'rental_amount' => 8000, 'start_date' => '2026-01-01', 'source' => 'manual',
        ], $leaseOverrides));

        return [$user, $lease];
    }
}

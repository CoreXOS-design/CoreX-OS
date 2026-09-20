<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 3 verification for .ai/specs/rental-work-orders.md §3.4b/§8 —
 * settled 2026-09-26, Johan's own ruling: an agency-level default, with the
 * override on the LEASE (not the property this spec had recommended).
 * `RentalWorkOrderSetting::thresholdFor()` is the one resolver anything
 * gating a spend decision should ever call — this proves its precedence.
 */
final class RentalWorkOrderSettingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RWO Settings Agency', 'slug' => 'rwo-settings-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
        ]);
    }

    private function lease(array $attrs = []): Lease
    {
        return Lease::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->admin->id,
        ], $attrs));
    }

    public function test_settings_default_to_five_hundred_when_no_row_exists(): void
    {
        $this->assertSame(500.0, RentalWorkOrderSetting::spendThresholdFor($this->agency->id));
    }

    public function test_agency_setting_overrides_the_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 1200]);

        $this->assertSame(1200.0, RentalWorkOrderSetting::spendThresholdFor($this->agency->id));
    }

    public function test_lease_with_no_override_inherits_the_agency_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 800]);
        $lease = $this->lease();

        $this->assertSame(800.0, RentalWorkOrderSetting::thresholdFor($lease));
    }

    public function test_lease_override_takes_precedence_over_the_agency_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 800]);
        $lease = $this->lease(['rental_no_approval_spend_threshold' => 2500]);

        $this->assertSame(2500.0, RentalWorkOrderSetting::thresholdFor($lease));
    }

    public function test_a_new_lease_starts_with_no_override_even_if_a_previous_lease_on_the_same_property_had_one(): void
    {
        // §3.4b's own argument against a property-level override, proven the
        // other way round: a lease's own override never leaks to a sibling
        // lease on the same property.
        $this->lease(['rental_no_approval_spend_threshold' => 5000, 'status' => Lease::STATUS_CANCELLED]);
        $newLease = $this->lease();

        $this->assertNull($newLease->rental_no_approval_spend_threshold);
        $this->assertSame(500.0, RentalWorkOrderSetting::thresholdFor($newLease));
    }

    public function test_settings_are_saved_through_the_dedicated_page(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.update'), [
            'no_approval_spend_threshold' => 350,
        ])->assertRedirect();

        $this->assertSame(350.0, RentalWorkOrderSetting::spendThresholdFor($this->agency->id));
    }

    public function test_lease_override_is_saved_through_the_lease_update_endpoint(): void
    {
        $lease = $this->lease();

        $this->actingAs($this->admin)->put(route('corex.leases.update', $lease), [
            'deposit_amount' => $lease->deposit_amount,
            'end_date' => null,
            'is_month_to_month' => 1,
            'rental_no_approval_spend_threshold' => 3000,
        ])->assertRedirect();

        $this->assertSame(3000.0, (float) $lease->fresh()->rental_no_approval_spend_threshold);
    }
}

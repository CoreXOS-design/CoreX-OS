<?php

declare(strict_types=1);

namespace Tests\Feature\RentalWorkOrders;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stage 7 verification for .ai/specs/rental-work-orders.md §3.4b/§8 —
 * settled 2026-09-29, superseding the 2026-09-26 lease ruling. Johan, live,
 * looking at the lease screen: "per property, populated to the leases
 * screen." `RentalWorkOrderSetting::thresholdFor()` is the one resolver
 * anything gating a spend decision should ever call — this proves its
 * precedence now resolves against the PROPERTY, not the lease.
 */
final class RentalWorkOrderSettingTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Illuminate\Support\Facades\Auth::logout();
        $this->agency = Agency::create(['name' => 'RWO Settings Agency', 'slug' => 'rwo-settings-' . uniqid()]);
        $this->branch = Branch::forceCreate(['name' => 'Ramsgate', 'agency_id' => $this->agency->id]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function property(array $attrs = []): Property
    {
        return Property::forceCreate(array_merge([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id,
            'title' => '1 Test Street', 'status' => 'active', 'listing_type' => 'rental',
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

    public function test_property_with_no_override_inherits_the_agency_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 800]);
        $property = $this->property();

        $this->assertSame(800.0, RentalWorkOrderSetting::thresholdFor($property));
    }

    public function test_property_override_takes_precedence_over_the_agency_default(): void
    {
        RentalWorkOrderSetting::create(['agency_id' => $this->agency->id, 'no_approval_spend_threshold' => 800]);
        $property = $this->property(['rental_no_approval_spend_threshold' => 2500]);

        $this->assertSame(2500.0, RentalWorkOrderSetting::thresholdFor($property));
    }

    public function test_settings_are_saved_through_the_dedicated_page(): void
    {
        $this->actingAs($this->admin)->post(route('corex.settings.rental-work-orders.update'), [
            'no_approval_spend_threshold' => 350,
        ])->assertRedirect();

        $this->assertSame(350.0, RentalWorkOrderSetting::spendThresholdFor($this->agency->id));
    }

    public function test_property_override_is_saved_through_the_rental_details_endpoint(): void
    {
        $property = $this->property();

        $this->actingAs($this->admin)->put(route('corex.properties.rental-details.update', $property), [
            'rental_no_approval_spend_threshold' => 3000,
        ])->assertRedirect();

        $this->assertSame(3000.0, (float) $property->fresh()->rental_no_approval_spend_threshold);
    }

    /** BUILD_STANDARD §2 — optional-and-empty must clear gracefully, not 500 or silently keep a stale value. */
    public function test_property_override_can_be_cleared_back_to_the_agency_default(): void
    {
        $property = $this->property(['rental_no_approval_spend_threshold' => 3000]);

        $this->actingAs($this->admin)->put(route('corex.properties.rental-details.update', $property), [
            'rental_no_approval_spend_threshold' => '',
        ])->assertRedirect();

        $this->assertNull($property->fresh()->rental_no_approval_spend_threshold);
        $this->assertSame(500.0, RentalWorkOrderSetting::thresholdFor($property->fresh()));
    }

    public function test_lease_show_screen_reads_through_to_the_property_value(): void
    {
        $property = $this->property(['rental_no_approval_spend_threshold' => 1750]);
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(),
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))
            ->assertOk()
            ->assertSee('No-approval spend threshold')
            ->assertSee('1,750.00');
    }
}

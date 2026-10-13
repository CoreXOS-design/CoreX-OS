<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\Rentals\RentalOwnerFaultSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.6.2 as changed by rentals-faults-work-orders.md §22.6/§23.7: the repair LIMIT lives in ONE place - the owner's
 * "How the owner wants faults handled" - and the "Work terms agreed with the owner" panel (whose only remaining field was the variation tolerance %) is
 * GONE: the authorised amount is a hard lock, so nothing reads or edits a tolerance any more. Stored values are kept as history.
 */
final class WorkTermsTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Terms');
    }

    private function save(array $data, ?Property $property = null, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin)->put(route('corex.properties.rental-work-terms.update', $property ?? $this->property), $data);
    }

    private function ownerLimit(float $limit, ?Property $property = null): void
    {
        app(RentalOwnerFaultSettingsService::class)->capture($property ?? $this->property, $this->admin, ['repair_limit' => $limit, 'emergency_limit' => 0, 'callout_mode' => 'no'], 'phone_call', now(), 'Owner phoned.');
    }

    public function test_the_tolerance_panel_and_its_route_are_gone_and_the_property_screen_has_no_tolerance_input(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('corex.properties.rental-work-terms.update'));
        $this->assertFalse(view()->exists('corex.properties._rental-work-terms'));

        $html = $this->actingAs($this->admin)->get(route('corex.properties.show', $this->property))->assertOk()->getContent();

        $this->assertStringNotContainsString('Work terms agreed with the owner', $html);
        $this->assertStringNotContainsString('name="variation_tolerance"', $html);
        $this->assertStringNotContainsString('Variation tolerance', $html);
        $this->assertStringNotContainsString('No-Approval Spend Threshold (R)', $html);
        $this->assertStringNotContainsString('name="rental_no_approval_spend_threshold"', $html);
        $this->assertStringNotContainsString('name="no_approval_limit"', $html);
    }

    public function test_the_lease_screen_shows_the_limit_from_the_owners_own_settings_and_no_tolerance_line(): void
    {
        $this->ownerLimit(1750);
        $this->property->forceFill(['rental_variation_tolerance_percent' => 12])->save();   // stored data from the old panel: must not show
        $lease = \App\Models\Lease::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id,
            'status' => \App\Models\Lease::STATUS_ACTIVE, 'rental_amount' => 9500, 'start_date' => now(), 'created_by_user_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)->get(route('corex.leases.show', $lease))->assertOk()
            ->assertSee("Owner's repair limit", false)->assertSee('1,750.00')
            ->assertDontSee('Extra work passed without asking')->assertDontSee('% above the approved amount');
    }

    // ── ONE place for the repair limit: every reader follows the owner's own settings ──

    public function test_every_reader_of_the_repair_limit_follows_the_owners_own_settings_once_they_have_one(): void
    {
        $this->setting(['no_approval_spend_threshold' => 800]);
        $gate = app(\App\Services\Rentals\RentalApprovalGateService::class);
        $property = fn () => $this->property->fresh();

        // no owner answer yet: the old fallback chain, unchanged (the agency default)
        $this->assertSame(800.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame(800.0, $gate->termsFor($property())->noApprovalLimit);

        // the owner's own settings win - in the gate AND in every screen that asks the setting
        $this->ownerLimit(1500);
        $this->assertSame(1500.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame([1500.0, 'property'], [$gate->termsFor($property())->noApprovalLimit, $gate->termsFor($property())->limitSource]);

        // a dispute = must ask for everything = R0 for the gate
        $row = \App\Models\RentalPropertyFaultSetting::withoutGlobalScopes()->where('property_id', $this->property->id)->first();
        $contact = \App\Models\Contact::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'first_name' => 'Own', 'last_name' => 'Er', 'email' => 'owner+' . uniqid() . '@example.invalid']);
        app(RentalOwnerFaultSettingsService::class)->dispute($this->property, $contact, (int) $row->version, null);
        $this->assertSame(0.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
        $this->assertSame(0.0, $gate->termsFor($property())->noApprovalLimit);

        // the old column is a fallback only: it is not consulted once the owner has an answer
        $this->property->forceFill(['rental_no_approval_spend_threshold' => 99999])->saveQuietly();
        $this->assertSame(0.0, \App\Models\RentalWorkOrderSetting::thresholdFor($property()));
    }

    public function test_a_sale_property_and_a_second_agency_are_not_affected_by_the_removed_tolerance(): void
    {
        $other = Agency::create(['name' => 'Cape Rentals', 'slug' => 'cape-' . uniqid()]);
        \App\Models\RentalWorkOrderSetting::withoutGlobalScopes()->create(['agency_id' => $other->id, 'no_approval_spend_threshold' => 2500, 'variation_tolerance_percent' => 20]);
        $this->setting(['no_approval_spend_threshold' => 100]);

        $this->assertSame(2500.0, \App\Models\RentalWorkOrderSetting::spendThresholdFor($other->id));
        $this->assertSame(100.0, \App\Models\RentalWorkOrderSetting::spendThresholdFor($this->agency->id));
        $this->assertFalse(method_exists(\App\Models\RentalWorkOrderSetting::class, 'variationTolerancePercentFor'));
    }
}

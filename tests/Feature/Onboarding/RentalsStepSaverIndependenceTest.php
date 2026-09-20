<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalInspectionSetting;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/agency-onboarding-rentals-step.md §4 — proof that expanding the
 * 'leases' wizard step to also carry the two rental-inspection windows, and
 * now (rental-work-orders.md §3.4b/§8, Stage 3) the work-order spend
 * threshold, cannot repeat the saver-precondition incident named in
 * agency-onboarding-setup.md §6.1.
 *
 * This step's shape is different from that incident's steps in one important
 * way, worth stating explicitly rather than assumed: the original incident
 * was a step rendering FEWER fields than an EXISTING multi-field saver it
 * reused required, so the saver force-defaulted the fields it never saw.
 * Here, the wizard step's own rendered form always posts all three fields
 * together (they are all controls on the one step), so a genuine partial
 * post through `corex.agency-setup.step.save` is not a real scenario for
 * this step the way it was for the original incident's steps — and forcing
 * one in a test would just trip each saver's OWN `required` validation
 * (both fields are legitimately required on their own dedicated forms),
 * which is a different failure mode entirely, not the corruption this
 * exists to guard against.
 *
 * The real, live partial-post path for this step is each domain's OWN
 * dedicated settings page (`/corex/settings/leases`,
 * `/corex/settings/rental-inspections`) — each posts only its own complete
 * field set. THAT is where independence actually needs proving, because
 * both pages call into savers that now live side-by-side on the same
 * wizard step, and nothing about adding the wizard step should let one
 * page's save reach into the other domain's data.
 */
final class RentalsStepSaverIndependenceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_saving_the_combined_rentals_step_persists_all_four_fields(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 45,
                'fault_report_window_days' => 10,
                'out_inspection_signing_window_days' => 14,
                'no_approval_spend_threshold' => 750,
            ])
            ->assertRedirect();

        $this->assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
        $this->assertSame(10, RentalInspectionSetting::faultReportWindowDaysFor($agency->id));
        $this->assertSame(14, RentalInspectionSetting::signingWindowDaysFor($agency->id));
        $this->assertSame(750.0, RentalWorkOrderSetting::spendThresholdFor($agency->id));
    }

    public function test_saving_the_dedicated_lease_settings_page_never_touches_rental_inspection_settings(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        // An agency already running with non-default inspection windows.
        RentalInspectionSetting::create([
            'agency_id' => $agency->id,
            'fault_report_window_days' => 21,
            'out_inspection_signing_window_days' => 30,
        ]);
        RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'no_approval_spend_threshold' => 1000]);

        $this->actingAs($admin)
            ->post(route('corex.settings.leases.update'), ['expiry_notice_window_days' => 90])
            ->assertRedirect();

        $this->assertSame(90, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'the field this page owns must save');
        $this->assertSame(21, RentalInspectionSetting::faultReportWindowDaysFor($agency->id), 'untouched by the leases page');
        $this->assertSame(30, RentalInspectionSetting::signingWindowDaysFor($agency->id), 'untouched by the leases page');
        $this->assertSame(1000.0, RentalWorkOrderSetting::spendThresholdFor($agency->id), 'untouched by the leases page');
    }

    public function test_saving_the_dedicated_rental_inspection_settings_page_never_touches_lease_or_work_order_settings(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        // An agency already running with a non-default lease expiry window
        // and spend threshold.
        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 120]);
        RentalWorkOrderSetting::create(['agency_id' => $agency->id, 'no_approval_spend_threshold' => 1000]);

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-inspections.update'), [
                'fault_report_window_days' => 5,
                'out_inspection_signing_window_days' => 5,
            ])
            ->assertRedirect();

        $this->assertSame(5, RentalInspectionSetting::faultReportWindowDaysFor($agency->id), 'the fields this page owns must save');
        $this->assertSame(120, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'untouched by the rental-inspections page');
        $this->assertSame(1000.0, RentalWorkOrderSetting::spendThresholdFor($agency->id), 'untouched by the rental-inspections page');
    }

    /**
     * .ai/specs/rental-work-orders.md §3.4b/§8, Stage 3 — the third saver on
     * this step, proven the same way the first two already are.
     */
    public function test_saving_the_dedicated_rental_work_order_settings_page_never_touches_lease_or_inspection_settings(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 120]);
        RentalInspectionSetting::create([
            'agency_id' => $agency->id,
            'fault_report_window_days' => 21,
            'out_inspection_signing_window_days' => 30,
        ]);

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-work-orders.update'), ['no_approval_spend_threshold' => 250])
            ->assertRedirect();

        $this->assertSame(250.0, RentalWorkOrderSetting::spendThresholdFor($agency->id), 'the field this page owns must save');
        $this->assertSame(120, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'untouched by the rental-work-orders page');
        $this->assertSame(21, RentalInspectionSetting::faultReportWindowDaysFor($agency->id), 'untouched by the rental-work-orders page');
        $this->assertSame(30, RentalInspectionSetting::signingWindowDaysFor($agency->id), 'untouched by the rental-work-orders page');
    }
}

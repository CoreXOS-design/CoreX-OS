<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgencyOnboardingSetup;
use App\Models\Branch;
use App\Models\LeaseSetting;
use App\Models\RentalInspectionSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/agency-onboarding-rentals-step.md §3.2/§6 — proof against a REAL
 * agency that already completed the wizard before this change existed, not
 * just a fresh one. The step's key deliberately did not change (still
 * 'leases') specifically so this scenario is safe — this test is what proves
 * that reasoning rather than just asserting it.
 */
final class RentalsStepExistingAgencyTest extends TestCase
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

    /** An agency that finished onboarding months ago, back when 'leases' only asked one question. */
    private function preExistingCompletedAgency(): array
    {
        $agency = Agency::create(['name' => 'Established Realty', 'slug' => 'established-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        LeaseSetting::create(['agency_id' => $agency->id, 'expiry_notice_window_days' => 45]);

        $setup = new AgencyOnboardingSetup();
        $setup->agency_id = $agency->id;
        $setup->token = AgencyOnboardingSetup::generateToken();
        $setup->slug = AgencyOnboardingSetup::generateSlug($agency->name, $agency->id);
        $setup->created_by = $admin->id;
        $setup->admin_user_id = $admin->id;
        $setup->current_step = AgencyOnboardingSetup::totalSteps();
        $setup->completed_steps = AgencyOnboardingSetup::STEPS; // completed everything, including 'leases'
        $setup->expires_at = now()->addDays(30);
        $setup->completed_at = now()->subMonths(3);
        $setup->save();

        return [$agency, $admin, $setup];
    }

    public function test_progress_does_not_regress_for_an_agency_that_already_completed_the_old_leases_step(): void
    {
        [$agency, , $setup] = $this->preExistingCompletedAgency();

        // Expanding the step's CONTENT must not change what counts as done —
        // the key is unchanged, so the literal string 'leases' this agency
        // already has in completed_steps still matches.
        $this->assertSame(100, $setup->progressPercent($agency), 'a fully-completed agency must still read 100% after the step gained new fields');
        $this->assertContains('leases', $setup->completed_steps);
    }

    public function test_re_opening_the_guide_shows_the_new_fields_with_real_or_default_values_not_blanks(): void
    {
        [$agency, $admin] = $this->preExistingCompletedAgency();
        // This agency never configured inspection windows — no row exists yet.
        $this->assertNull(RentalInspectionSetting::where('agency_id', $agency->id)->first());

        $response = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']));

        $response->assertOk();
        // The lease field they already set survives and is shown, not reset.
        $response->assertSee('45');
        // The new fields are shown with the documented default, never blank.
        $response->assertSee('7');
    }

    public function test_re_opening_the_guide_and_saving_updates_both_domains_without_disturbing_completion(): void
    {
        [$agency, $admin, $setup] = $this->preExistingCompletedAgency();

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 45, // unchanged, re-submitted as the form would
                'fault_report_window_days' => 14,
                'out_inspection_signing_window_days' => 21,
            ])
            ->assertRedirect();

        $this->assertSame(14, RentalInspectionSetting::faultReportWindowDaysFor($agency->id));
        $this->assertSame(21, RentalInspectionSetting::signingWindowDaysFor($agency->id));
        $this->assertSame(45, LeaseSetting::expiryNoticeWindowDaysFor($agency->id), 'the pre-existing lease value must be unaffected');

        // Still exactly one 'leases' entry — markStepComplete() is idempotent,
        // re-saving an already-completed step must not duplicate it or move
        // the resume pointer backwards.
        $setup->refresh();
        $this->assertSame(1, count(array_keys($setup->completed_steps, 'leases', true)));
    }

    public function test_the_settings_hub_link_reaches_the_same_data_without_touching_the_wizard_at_all(): void
    {
        [$agency, $admin] = $this->preExistingCompletedAgency();

        // Discoverable without ever re-opening the wizard — the primary path
        // per §6 of the spec.
        $this->actingAs($admin)
            ->get(route('corex.settings.rental-inspections.edit'))
            ->assertOk()
            ->assertSee('7'); // the default, since this agency hasn't set it
    }
}

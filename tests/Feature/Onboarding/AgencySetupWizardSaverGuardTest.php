<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\AgentMentor;
use App\Models\Branch;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\CommissionSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\CommissionCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Saver-precondition guards — the wizard reuses the settings page's canonical
 * savers, but its step forms carry a SUBSET of each saver's fields. Any saver
 * that coerces an absent checkbox to false ("my form always carries this
 * field") silently wipes settings it was never shown.
 *
 * BUILD_STANDARD §2 (the array_filter class) and §6 (fix the class, not the
 * instance). CompanySettingsController@update already solves this correctly
 * with `_present` markers + $request->has() guards; these savers did not.
 *
 * Each test configures an agency the way a real one would be AFTER go-live,
 * then re-opens the Setup Guide from Settings — a supported path
 * (AgencySetupWizardController::resolveOrCreateSetup exists precisely for it)
 * — and asserts the untouched settings survive the save.
 */
class AgencySetupWizardSaverGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Role::clearCache();
        parent::tearDown();
    }

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        return User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'admin',
            'is_active' => true,
        ]);
    }

    private function agency(): Agency
    {
        return Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
    }

    /**
     * The notifications step renders 10 of updateAgencyDashboardSettings'
     * 12 boolean fields. It never renders weekend_visible or
     * open_hours_enabled — so saving the step must LEAVE THEM ALONE, not
     * coerce them to false.
     */
    public function test_notifications_step_does_not_disable_quiet_hours_or_weekend_visibility(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        // An agency that has been running a while: quiet hours on, weekends
        // visible on the calendar.
        AgencyDashboardSetting::create([
            'agency_id'          => $agency->id,
            'open_hours_enabled' => true,
            'open_hours_start'   => '08:00',
            'open_hours_end'     => '17:00',
            'weekend_visible'    => true,
            'notify_in_app'      => true,
            'notify_email'       => true,
        ]);

        // The principal re-opens the Setup Guide and saves the notifications
        // step, posting exactly what that step's form carries.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'notifications']), [
                'dashboard_settings_mode' => 'agency',
                'idle_alerts_enabled'     => '1',
                'doc_reminders_enabled'   => '1',
                'lease_expiry_reminders'  => '0',
                'fica_reminders'          => '1',
                'ffc_reminders'           => '1',
                'task_due_reminders'      => '1',
                'overdue_daily_digest'    => '0',
                'notify_in_app'           => '1',
                'notify_email'            => '1',
                'notify_push'             => '0',
            ])
            ->assertRedirect();

        $d = AgencyDashboardSetting::where('agency_id', $agency->id)->first();

        // The fields the step DID carry are saved.
        $this->assertTrue((bool) $d->fica_reminders, 'a rendered toggle should save');
        $this->assertFalse((bool) $d->overdue_daily_digest, 'a rendered toggle turned off should save off');

        // The fields the step did NOT carry must be untouched.
        $this->assertTrue((bool) $d->open_hours_enabled, 'quiet hours must survive a notifications-step save');
        $this->assertTrue((bool) $d->weekend_visible, 'weekend visibility must survive a notifications-step save');
    }

    /**
     * The presentations step declares 6 controls; ss_show_complex_section is
     * not one of them. updatePresentations coerces it unconditionally, on the
     * stated assumption that "the presentations form always carries this
     * field" — an assumption the wizard breaks.
     */
    public function test_presentations_step_does_not_disable_the_complex_section(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $agency->update(['ss_show_complex_section' => true]);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'presentations']), [
                'presentations_coverage_rich_threshold'     => 12,
                'presentations_coverage_moderate_threshold' => 6,
                'presentations_coverage_thin_threshold'     => 3,
                'presentations_default_period_months'       => 12,
                'presentations_default_comp_scope'          => 'radius_all',
                'presentations_default_radius_m'            => 1000,
            ])
            ->assertRedirect();

        $this->assertTrue(
            (bool) $agency->fresh()->ss_show_complex_section,
            'the complex section must survive a presentations-step save that never rendered it'
        );
    }

    /**
     * The settings page still owns these fields, and unchecking there must
     * still turn them off. The guard must not break the canonical form.
     */
    public function test_settings_page_can_still_turn_the_fields_off(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        AgencyDashboardSetting::create([
            'agency_id'          => $agency->id,
            'open_hours_enabled' => true,
            'weekend_visible'    => true,
        ]);
        $agency->update(['ss_show_complex_section' => true]);

        // The settings page's dashboard form carries every toggle, each with a
        // hidden "0" companion — so an unchecked box arrives as "0", present.
        $this->actingAs($admin)
            ->put(route('corex.settings.dashboard.agency'), [
                'open_hours_enabled' => '0',
                'weekend_visible'    => '0',
                'notify_in_app'      => '1',
            ])
            ->assertRedirect();

        $d = AgencyDashboardSetting::where('agency_id', $agency->id)->first();
        $this->assertFalse((bool) $d->open_hours_enabled, 'settings page must still be able to turn quiet hours off');
        $this->assertFalse((bool) $d->weekend_visible, 'settings page must still be able to turn weekends off');

        // Same for the presentations form, which always carries the checkbox.
        $this->actingAs($admin)
            ->post(route('corex.settings.presentations.update'), [
                'presentations_coverage_rich_threshold'     => 12,
                'presentations_coverage_moderate_threshold' => 6,
                'presentations_coverage_thin_threshold'     => 3,
                'presentations_default_period_months'       => 12,
                'ss_show_complex_section'                   => '0',
            ])
            ->assertRedirect();

        $this->assertFalse(
            (bool) $agency->fresh()->ss_show_complex_section,
            'settings page must still be able to turn the complex section off'
        );
    }

    /**
     * The mentor programme is now an explicit on/off switch (2026-07-11), from
     * both the settings page and the wizard's commission step. Default is ON, so
     * agencies already running a mentor programme see no change in payout.
     */
    public function test_mentor_programme_toggle_saves_from_the_wizard(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $this->assertTrue(
            (bool) CommissionSetting::forAgency($agency->id)->mentor_program_enabled,
            'defaults ON so existing mentored agents keep their current payout'
        );

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'commission']), $this->commissionPayload([
                'mentor_program_enabled' => '0',
            ]))
            ->assertRedirect();

        $this->assertFalse((bool) CommissionSetting::forAgency($agency->id)->fresh()->mentor_program_enabled);

        // And back on again.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'commission']), $this->commissionPayload([
                'mentor_program_enabled' => '1',
            ]))
            ->assertRedirect();

        $this->assertTrue((bool) CommissionSetting::forAgency($agency->id)->fresh()->mentor_program_enabled);
    }

    /**
     * The one that actually matters: switching the programme OFF must stop the
     * mentor fee being charged, not merely hide the fields. A mentee with a live
     * AgentMentor row must pay no mentor fee once the agency switches it off.
     */
    public function test_mentor_programme_off_charges_no_mentor_fee(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $mentee = User::factory()->create([
            'agency_id' => $agency->id, 'role' => 'agent', 'is_active' => true,
        ]);
        $mentorAgent = User::factory()->create([
            'agency_id' => $agency->id, 'role' => 'agent', 'is_active' => true,
        ]);
        AgentMentor::create([
            'agency_id'      => $agency->id,
            'mentee_user_id' => $mentee->id,
            'mentor_user_id' => $mentorAgent->id,
            'is_active'      => true,
            'assigned_at'    => now(),
        ]);

        $settings = CommissionSetting::forAgency($agency->id);

        // R115 000 gross incl. VAT, R15 000 VAT => R100 000 excl. At the default
        // 20% mentor split that is a R20 000 mentor fee.
        $on = CommissionCalculationService::calculateDealCommission(
            $mentee->id, '115000.00', '15000.00', 'sale', 'Mentored sale — programme ON'
        );
        $this->assertTrue(
            bccomp((string) $on->mentor_fee, '0.00', 2) > 0,
            'with the programme on, a mentored agent pays a mentor fee'
        );

        // Programme OFF: same agent, same live AgentMentor row, no fee.
        $settings->update(['mentor_program_enabled' => false]);

        $off = CommissionCalculationService::calculateDealCommission(
            $mentee->id, '115000.00', '15000.00', 'sale', 'Mentored sale — programme OFF'
        );
        $this->assertSame(
            '0.00',
            (string) $off->mentor_fee,
            'switching the programme off must stop the fee, not just hide the fields'
        );
    }

    // ── Feature-switchboard savers (switchboard spec §3.4) ───────────────────
    // These four bare-boolean savers became multi-callers when the switchboard
    // began fanning to them. Each must now: leave the value ALONE when its field
    // is absent from the request, and still save false when the field arrives as
    // a present "0" (the hidden-companion path). "Fix the class" — BUILD_STANDARD §6.

    public function test_marketing_enabled_saver_ignores_absent_field_but_honours_present_zero(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        \App\Models\PerformanceSetting::updateOrCreate(['key' => 'marketing_enabled'], ['value' => 1]);

        // Field absent → leave alone.
        $this->actingAs($admin)->post(route('corex.settings.marketing-enabled'), [])->assertRedirect();
        $this->assertSame(1, (int) \App\Models\PerformanceSetting::get('marketing_enabled'), 'absent field must not wipe the setting');

        // Field present as "0" → save false.
        $this->actingAs($admin)->post(route('corex.settings.marketing-enabled'), ['marketing_enabled' => '0'])->assertRedirect();
        $this->assertSame(0, (int) \App\Models\PerformanceSetting::get('marketing_enabled'), 'present "0" must turn it off');
    }

    public function test_syndication_portals_saver_ignores_absent_fields_but_honours_present_zero(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        \App\Models\PerformanceSetting::updateOrCreate(['key' => 'syndication_p24_enabled'], ['value' => 1]);
        \App\Models\PerformanceSetting::updateOrCreate(['key' => 'syndication_pp_enabled'], ['value' => 1]);

        // Only PP posted → P24 must be left alone, PP turned off.
        $this->actingAs($admin)->post(route('corex.settings.syndication-portals'), ['syndication_pp_enabled' => '0'])->assertRedirect();
        $this->assertSame(1, (int) \App\Models\PerformanceSetting::get('syndication_p24_enabled'), 'unposted P24 must survive');
        $this->assertSame(0, (int) \App\Models\PerformanceSetting::get('syndication_pp_enabled'), 'present "0" must turn PP off');
    }

    public function test_matches_enabled_saver_ignores_absent_field_but_honours_present_zero(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        \App\Models\PerformanceSetting::updateOrCreate(['key' => 'matches_enabled'], ['value' => 1]);

        $this->actingAs($admin)->post(route('corex.settings.matches-enabled'), [])->assertRedirect();
        $this->assertSame(1, (int) \App\Models\PerformanceSetting::get('matches_enabled'), 'absent field must not wipe Core Matches');

        $this->actingAs($admin)->post(route('corex.settings.matches-enabled'), ['matches_enabled' => '0'])->assertRedirect();
        $this->assertSame(0, (int) \App\Models\PerformanceSetting::get('matches_enabled'), 'present "0" must turn Core Matches off');
    }

    public function test_split_branches_saver_ignores_absent_field_but_honours_present_zero(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $agency->update(['split_branches_enabled' => true]);

        $this->actingAs($admin)->put(route('corex.settings.split-branches'), [])->assertRedirect();
        $this->assertTrue((bool) $agency->fresh()->split_branches_enabled, 'absent field must not wipe branch isolation');

        $this->actingAs($admin)->put(route('corex.settings.split-branches'), ['split_branches_enabled' => '0'])->assertRedirect();
        $this->assertFalse((bool) $agency->fresh()->split_branches_enabled, 'present "0" must turn branch isolation off');
    }

    /** The commission form's full field set, with overrides. */
    private function commissionPayload(array $overrides = []): array
    {
        return array_merge([
            'commission_split_agent'     => 70,
            'annual_cap'                 => 1000000,
            'post_cap_transaction_fee'   => 500,
            'post_cap_fee_cap'           => 5000,
            'post_cap_reduced_fee'       => 250,
            'monthly_platform_fee'       => 1000,
            'risk_management_fee'        => 300,
            'risk_management_cap'        => 3000,
            'mentor_program_enabled'     => '1',
            'mentor_extra_split'         => 20,
            'mentor_transactions'        => 3,
            'revenue_share_enabled'      => '0',
            'revenue_share_pool_percent' => 10,
            'tier_1_percent'             => 5, 'tier_2_percent' => 4, 'tier_3_percent' => 3,
            'tier_4_percent'             => 2, 'tier_5_percent' => 1, 'tier_6_percent' => 1,
            'tier_7_percent'             => 1,
            'tier_4_flqa_requirement'    => 5,  'tier_5_flqa_requirement' => 10,
            'tier_6_flqa_requirement'    => 15, 'tier_7_flqa_requirement' => 20,
        ], $overrides);
    }
}

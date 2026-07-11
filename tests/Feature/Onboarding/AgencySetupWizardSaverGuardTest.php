<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\CommandCenter\AgencyDashboardSetting;
use App\Models\Role;
use App\Models\User;
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
     * The properties step used to tell the admin their portal credentials had to
     * be "saved against the agency" and then give them nowhere to type them.
     * They now save inline — through a NARROW saver, deliberately not
     * AgencyController@update, which requires `name` and force-defaults
     * is_active + the brand colours and would therefore deactivate the agency
     * and undo the branding step.
     */
    public function test_properties_step_saves_portal_credentials_without_collateral_damage(): void
    {
        $agency = $this->agency();
        $agency->update([
            'is_active'     => true,
            'sidebar_color' => '#123456',
            'button_color'  => '#456789',
        ]);
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'properties']), [
                'properties_per_page'     => 24,
                'marketing_enabled'       => '1',
                'syndication_p24_enabled' => '1',
                'syndication_pp_enabled'  => '0',
                'p24_username'            => 'hfc_p24',
                'p24_password'            => 'sekrit24',
                'p24_agency_id'           => '9931',
                'pp_username'             => 'hfc_pp',
                'pp_password'             => 'sekritPP',
                'pp_branch_guid'          => 'abc-guid',
            ])
            ->assertRedirect();

        $a = $agency->fresh();
        $this->assertSame('hfc_p24', $a->p24_username);
        $this->assertSame('sekrit24', $a->p24_password);
        $this->assertSame('hfc_pp', $a->pp_username);

        // The collateral damage AgencyController@update would have caused:
        $this->assertTrue((bool) $a->is_active, 'the agency must not be deactivated');
        $this->assertSame('#123456', $a->sidebar_color, 'brand colours must not be reset');
        $this->assertSame('#456789', $a->button_color, 'brand colours must not be reset');
    }

    /**
     * A password field cannot echo the stored secret back, so it renders blank
     * on every revisit. Blank must mean "leave it alone", never "erase it" —
     * otherwise re-saving the step to change the per-page count would silently
     * break syndication.
     */
    public function test_blank_password_keeps_the_stored_credential(): void
    {
        $agency = $this->agency();
        $agency->update(['p24_username' => 'hfc_p24', 'p24_password' => 'sekrit24']);
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'properties']), [
                'properties_per_page'     => 50,
                'marketing_enabled'       => '1',
                'syndication_p24_enabled' => '1',
                'syndication_pp_enabled'  => '0',
                'p24_username'            => 'hfc_p24',
                'p24_password'            => '',   // rendered blank, submitted blank
            ])
            ->assertRedirect();

        $this->assertSame('sekrit24', $agency->fresh()->p24_password);
    }

    /**
     * The team step — an agency that finishes onboarding with zero agents cannot
     * list a property or run a deal. Invites go through the canonical
     * UserManagementController@store, which creates the user with an
     * INVITE_PENDING sentinel password and emails them to set their own.
     */
    public function test_team_step_invites_an_agent(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);
        $branch = Branch::where('agency_id', $agency->id)->first();

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.collection.add', ['collection' => 'user']), [
                'name'      => 'Thandeka',
                'surname'   => 'Mokoena',
                'email'     => 'thandeka.mokoena@hfcoastal.co.za',
                'cell'      => '082 555 1234',
                'role'      => 'agent',
                'branch_id' => $branch->id,
            ])
            ->assertRedirect(route('corex.agency-setup.step', ['step' => 'team']));

        $invited = User::where('email', 'thandeka.mokoena@hfcoastal.co.za')->first();

        $this->assertNotNull($invited, 'the agent should exist');
        $this->assertSame($agency->id, $invited->agency_id);
        $this->assertSame((int) $branch->id, (int) $invited->branch_id);
        $this->assertNull($invited->email_verified_at, 'the invite is pending until they accept');
        // password is cast => hashed, so the sentinel is stored bcrypted.
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('INVITE_PENDING', $invited->password),
            'the agent sets their own password from the invite email'
        );
    }

    /**
     * The lazy-but-valid shortcut and the messy paths (BUILD_STANDARD §2/§5):
     * a half-filled invite must be rejected with a message, never a 500, and a
     * duplicate email must not create a second account.
     */
    public function test_invite_rejects_incomplete_and_duplicate_entries(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        // Missing the required surname/cell/role — rejected, nothing created.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.collection.add', ['collection' => 'user']), [
                'name'  => 'Thandeka',
                'email' => 'thandeka.mokoena@hfcoastal.co.za',
            ])
            ->assertSessionHasErrors(['surname', 'cell', 'role']);

        $this->assertNull(User::where('email', 'thandeka.mokoena@hfcoastal.co.za')->first());

        // A real invite, then the same email again.
        $payload = [
            'name' => 'Thandeka', 'surname' => 'Mokoena',
            'email' => 'thandeka.mokoena@hfcoastal.co.za',
            'cell' => '082 555 1234', 'role' => 'agent',
        ];
        $this->actingAs($admin)->post(route('corex.agency-setup.collection.add', ['collection' => 'user']), $payload);
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.collection.add', ['collection' => 'user']), $payload)
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::where('email', 'thandeka.mokoena@hfcoastal.co.za')->count());
    }

    /**
     * Deleting a team member reroutes their printed QR codes, so it stays on the
     * User Management page with its confirmation UI. The wizard must 404 rather
     * than fall through and flash a "Removed." that never happened.
     */
    public function test_team_members_cannot_be_removed_from_the_wizard(): void
    {
        $agency = $this->agency();
        $admin  = $this->admin($agency);

        $this->actingAs($admin)
            ->delete(route('corex.agency-setup.collection.remove', [
                'collection' => 'user', 'id' => $admin->id,
            ]))
            ->assertNotFound();

        $this->assertNotNull($admin->fresh(), 'the admin must still exist');
    }
}

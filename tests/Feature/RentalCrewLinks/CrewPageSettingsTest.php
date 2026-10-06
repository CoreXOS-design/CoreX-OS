<?php

namespace Tests\Feature\RentalCrewLinks;

use App\Models\Agency;
use App\Models\RentalPortalSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalPortalFixtures;
use Tests\TestCase;

/**
 * rental-work-orders.md §14.27.3 / §14.29 — Build 2's agency settings.
 *
 * Input paths proven: every default with no settings row; saved value read
 * back; unknown stored value falls back to the default; per-agency isolation;
 * the Settings → Rental Portal saver (valid / invalid rejected untouched /
 * absent from the post leaves the stored value alone); the setup wizard
 * declares the control, wires its saver, and reads the SAVED value back (not
 * the hardcoded default); the page renders the control with the saved value
 * selected; the three crew-page savers (ranges 1-365 / 0-30 / 1-60, a blank
 * expiry clears it to "until revoked", a blank window leaves the stored value,
 * out-of-range and non-numeric are refused untouched, absent from the post
 * leaves everything alone) and all four controls are in the setup wizard.
 */
class CrewPageSettingsTest extends TestCase
{
    use BuildsRentalPortalFixtures;
    use RefreshDatabase;

    public function test_every_build_2_default_with_no_settings_row(): void
    {
        $agency = $this->makeAgency();

        $this->assertSame('in_progress_and_completed', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
        $this->assertNull(RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));
        $this->assertSame(7, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id));
        $this->assertSame(14, RentalPortalSetting::crewPageUpcomingDaysFor($agency->id));
        // No agency at all (a link whose agency was removed) never throws.
        $this->assertSame('in_progress_and_completed', RentalPortalSetting::crewPhotosVisibleToClientsFor(null));
        $this->assertSame(14, RentalPortalSetting::crewPageUpcomingDaysFor(null));
    }

    public function test_saved_values_are_read_back_and_a_zero_recent_window_is_kept_not_defaulted(): void
    {
        $agency = $this->makeAgency();
        RentalPortalSetting::withoutGlobalScopes()->create([
            'agency_id' => $agency->id,
            'crew_photos_visible_to_clients' => 'completed_only',
            'crew_standing_link_expiry_days' => 90,
            'crew_page_recent_completed_days' => 0,
            'crew_page_upcoming_days' => 30,
        ]);

        $this->assertSame('completed_only', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
        $this->assertSame(90, RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));
        $this->assertSame(0, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id), '0 means "hide the list", it must not fall back to 7');
        $this->assertSame(30, RentalPortalSetting::crewPageUpcomingDaysFor($agency->id));
    }

    public function test_an_unknown_stored_visibility_value_falls_back_to_the_default(): void
    {
        $agency = $this->makeAgency();
        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_photos_visible_to_clients' => 'everything']);

        $this->assertSame('in_progress_and_completed', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
    }

    public function test_settings_are_per_agency(): void
    {
        $a = $this->makeAgency('Agency A');
        $b = $this->makeAgency('Agency B');
        $this->setCrewPhotoVisibility($a, 'completed_only');

        $this->assertSame('completed_only', RentalPortalSetting::crewPhotosVisibleToClientsFor($a->id));
        $this->assertSame('in_progress_and_completed', RentalPortalSetting::crewPhotosVisibleToClientsFor($b->id));
    }

    public function test_the_settings_saver_saves_a_valid_choice(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-portal.crew-photos-visible-to-clients'), ['crew_photos_visible_to_clients' => 'completed_only'])
            ->assertRedirect(route('corex.settings.rental-portal.edit'))
            ->assertSessionHasNoErrors();

        $this->assertSame('completed_only', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
    }

    public function test_the_settings_saver_rejects_an_invalid_choice_and_leaves_the_stored_value(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        $this->setCrewPhotoVisibility($agency, 'completed_only');

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-portal.crew-photos-visible-to-clients'), ['crew_photos_visible_to_clients' => 'all_of_it'])
            ->assertSessionHasErrors('crew_photos_visible_to_clients');
        $this->actingAs($admin)
            ->post(route('corex.settings.rental-portal.crew-photos-visible-to-clients'), ['crew_photos_visible_to_clients' => ''])
            ->assertSessionHasErrors('crew_photos_visible_to_clients');

        $this->assertSame('completed_only', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
    }

    public function test_a_post_without_the_field_leaves_the_stored_value_alone(): void
    {
        // agency-onboarding-setup.md §6.1 — the wizard step posts a SUBSET of fields;
        // absent means "leave it alone", never "reset to the default".
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        $this->setCrewPhotoVisibility($agency, 'completed_only');

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-portal.crew-photos-visible-to-clients'), [])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed_only', RentalPortalSetting::crewPhotosVisibleToClientsFor($agency->id));
    }

    public function test_saving_this_setting_does_not_disturb_the_other_portal_settings(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'tenant_portal_enabled' => false, 'contractor_secure_link_expiry_days' => 30]);

        $this->actingAs($admin)
            ->post(route('corex.settings.rental-portal.crew-photos-visible-to-clients'), ['crew_photos_visible_to_clients' => 'completed_only']);

        $this->assertFalse(RentalPortalSetting::tenantPortalEnabledFor($agency->id));
        $this->assertSame(30, RentalPortalSetting::contractorSecureLinkExpiryDaysFor($agency->id));
    }

    public function test_the_settings_page_shows_the_control_with_the_saved_value_selected(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        $this->setCrewPhotoVisibility($agency, 'completed_only');

        $html = $this->actingAs($admin)->get(route('corex.settings.rental-portal.edit'))->assertOk()->getContent();

        $this->assertStringContainsString('Crew page &amp; client visibility', $html);
        $this->assertMatchesRegularExpression('/<option value="completed_only"\s+selected/', $html);
        $this->assertDoesNotMatchRegularExpression('/<option value="in_progress_and_completed"\s+selected/', $html);
    }

    public function test_the_setup_wizard_declares_the_control_wires_its_saver_and_reads_the_saved_value_back(): void
    {
        $config = require config_path('agency-onboarding-copy.php');

        $found = null;
        $saverWired = false;
        foreach ($config as $step) {
            foreach (($step['controls'] ?? []) as $control) {
                if (($control['key'] ?? null) === 'crew_photos_visible_to_clients') {
                    $found = $control;
                    $controlStep = $step;
                }
            }
        }
        $this->assertNotNull($found, 'the wizard must surface crew_photos_visible_to_clients (non-negotiable #10a)');
        $this->assertSame('rental_portal', $found['source']);
        $this->assertSame('select', $found['type']);
        $this->assertArrayHasKey('completed_only', $found['options']);
        $this->assertNotSame('', trim($found['explain']));
        $this->assertNotSame('', trim($found['affects']));
        foreach ($controlStep['savers'] as $saver) {
            if (($saver['method'] ?? null) === 'updateCrewPhotosVisibleToClients') {
                $saverWired = true;
            }
        }
        $this->assertTrue($saverWired, 'its saver must be wired into the same step');

        // §6.2 — reopening the wizard shows the SAVED value, not the hardcoded default.
        $agency = Agency::create(['name' => 'Wizard Readback ' . uniqid(), 'slug' => 'wiz-' . uniqid()]);
        $this->setCrewPhotoVisibility($agency, 'completed_only');
        RentalPortalSetting::withoutGlobalScopes()->where('agency_id', $agency->id)->update(['tenant_portal_enabled' => false]);

        $controller = new \App\Http\Controllers\CoreX\AgencySetupWizardController();
        $method = new \ReflectionMethod($controller, 'currentValues');
        $method->setAccessible(true);
        $values = $method->invoke($controller, $controlStep, $agency->fresh());

        $this->assertSame('completed_only', $values['crew_photos_visible_to_clients']);
        $this->assertFalse($values['tenant_portal_enabled'], 'the existing portal controls read their saved value too, not the hardcoded default');
    }

    public function test_the_two_screens_are_never_out_of_step_the_wizard_option_labels_match_the_valid_values(): void
    {
        $config = require config_path('agency-onboarding-copy.php');
        foreach ($config as $step) {
            foreach (($step['controls'] ?? []) as $control) {
                if (($control['key'] ?? null) === 'crew_photos_visible_to_clients') {
                    $this->assertEqualsCanonicalizing(RentalPortalSetting::CREW_PHOTO_VISIBILITY_OPTIONS, array_keys($control['options']));
                }
            }
        }
    }

    public function test_the_standing_link_expiry_saver_takes_1_to_365_and_blank_means_until_revoked(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-standing-link-expiry-days'), ['crew_standing_link_expiry_days' => '45'])->assertSessionHasNoErrors();
        $this->assertSame(45, RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));

        // Present but blank = "no expiry, stands until revoked" — clears it.
        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-standing-link-expiry-days'), ['crew_standing_link_expiry_days' => ''])->assertSessionHasNoErrors();
        $this->assertNull(RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));
    }

    public function test_the_standing_link_expiry_saver_refuses_out_of_range_and_non_numeric_and_keeps_the_old_value(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_standing_link_expiry_days' => 45]);

        foreach (['0', '366', '-5', 'soon', '12.5'] as $bad) {
            $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-standing-link-expiry-days'), ['crew_standing_link_expiry_days' => $bad])
                ->assertSessionHasErrors('crew_standing_link_expiry_days');
        }
        $this->assertSame(45, RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));

        // Absent from the post (a wizard step that did not render it) leaves it alone — never clears it.
        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-standing-link-expiry-days'), [])->assertSessionHasNoErrors();
        $this->assertSame(45, RentalPortalSetting::crewStandingLinkExpiryDaysFor($agency->id));
    }

    public function test_the_recently_completed_saver_takes_0_to_30_and_zero_is_a_real_value(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-recent-completed-days'), ['crew_page_recent_completed_days' => '0'])->assertSessionHasNoErrors();
        $this->assertSame(0, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id), '0 hides the list — saved, not treated as blank');

        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-recent-completed-days'), ['crew_page_recent_completed_days' => '30'])->assertSessionHasNoErrors();
        $this->assertSame(30, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id));

        foreach (['31', '-1', 'abc', '2.5'] as $bad) {
            $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-recent-completed-days'), ['crew_page_recent_completed_days' => $bad])
                ->assertSessionHasErrors('crew_page_recent_completed_days');
        }
        $this->assertSame(30, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id));
    }

    public function test_the_upcoming_saver_takes_1_to_60(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);

        foreach (['1', '60', '21'] as $ok) {
            $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-upcoming-days'), ['crew_page_upcoming_days' => $ok])->assertSessionHasNoErrors();
            $this->assertSame((int) $ok, RentalPortalSetting::crewPageUpcomingDaysFor($agency->id));
        }
        foreach (['0', '61', '-3', 'xyz'] as $bad) {
            $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-upcoming-days'), ['crew_page_upcoming_days' => $bad])
                ->assertSessionHasErrors('crew_page_upcoming_days');
        }
        $this->assertSame(21, RentalPortalSetting::crewPageUpcomingDaysFor($agency->id));
    }

    public function test_a_blank_window_field_leaves_the_stored_window_alone(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        RentalPortalSetting::withoutGlobalScopes()->create(['agency_id' => $agency->id, 'crew_page_upcoming_days' => 21, 'crew_page_recent_completed_days' => 3]);

        // A wizard step / older form that posts the field empty must not wipe or fail the save.
        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-upcoming-days'), ['crew_page_upcoming_days' => ''])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('corex.settings.rental-portal.crew-page-recent-completed-days'), [])->assertSessionHasNoErrors();

        $this->assertSame(21, RentalPortalSetting::crewPageUpcomingDaysFor($agency->id));
        $this->assertSame(3, RentalPortalSetting::crewPageRecentCompletedDaysFor($agency->id));
    }

    public function test_the_settings_page_shows_all_four_controls_with_their_saved_values(): void
    {
        $agency = $this->makeAgency();
        $admin = $this->makeAgent($agency);
        RentalPortalSetting::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'crew_standing_link_expiry_days' => 90, 'crew_page_recent_completed_days' => 0, 'crew_page_upcoming_days' => 21,
        ]);

        $html = $this->actingAs($admin)->get(route('corex.settings.rental-portal.edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="crew_standing_link_expiry_days" value="90"/', $html);
        $this->assertMatchesRegularExpression('/name="crew_page_recent_completed_days" value="0"/', $html);
        $this->assertMatchesRegularExpression('/name="crew_page_upcoming_days" value="21"/', $html);
        $this->assertStringContainsString('name="crew_photos_visible_to_clients"', $html);
    }

    public function test_every_build_2_setting_is_in_the_setup_wizard_with_explain_affects_a_saver_and_a_readback(): void
    {
        $config = require config_path('agency-onboarding-copy.php');
        $keys = ['crew_photos_visible_to_clients', 'crew_standing_link_expiry_days', 'crew_page_recent_completed_days', 'crew_page_upcoming_days'];
        $savers = [
            'crew_photos_visible_to_clients' => 'updateCrewPhotosVisibleToClients',
            'crew_standing_link_expiry_days' => 'updateCrewStandingLinkExpiryDays',
            'crew_page_recent_completed_days' => 'updateCrewPageRecentCompletedDays',
            'crew_page_upcoming_days' => 'updateCrewPageUpcomingDays',
        ];

        $stepOf = null;
        $controls = [];
        foreach ($config as $step) {
            foreach (($step['controls'] ?? []) as $c) {
                if (in_array($c['key'], $keys, true)) {
                    $controls[$c['key']] = $c;
                    $stepOf = $step;
                }
            }
        }
        $this->assertCount(4, $controls, 'no Build 2 setting may be withheld from the wizard (non-negotiable #10a)');

        $wired = array_column($stepOf['savers'], 'method');
        foreach ($keys as $key) {
            $this->assertSame('rental_portal', $controls[$key]['source']);
            $this->assertNotSame('', trim($controls[$key]['explain']), "{$key} explain");
            $this->assertNotSame('', trim($controls[$key]['affects']), "{$key} affects");
            $this->assertContains($savers[$key], $wired, "{$key} saver is wired into the step");
        }
        $this->assertSame(7, $controls['crew_page_recent_completed_days']['default']);
        $this->assertSame(14, $controls['crew_page_upcoming_days']['default']);
        $this->assertNull($controls['crew_standing_link_expiry_days']['default'], 'blank = until revoked');

        // §6.2 — reopening the wizard shows the SAVED values, not the hardcoded defaults.
        $agency = \App\Models\Agency::create(['name' => 'Wizard B2 ' . uniqid(), 'slug' => 'wb2-' . uniqid()]);
        RentalPortalSetting::withoutGlobalScopes()->create([
            'agency_id' => $agency->id, 'crew_photos_visible_to_clients' => 'completed_only',
            'crew_standing_link_expiry_days' => 120, 'crew_page_recent_completed_days' => 0, 'crew_page_upcoming_days' => 28,
        ]);
        $controller = new \App\Http\Controllers\CoreX\AgencySetupWizardController();
        $m = new \ReflectionMethod($controller, 'currentValues');
        $m->setAccessible(true);
        $v = $m->invoke($controller, $stepOf, $agency->fresh());

        $this->assertSame('completed_only', $v['crew_photos_visible_to_clients']);
        $this->assertSame(120, $v['crew_standing_link_expiry_days']);
        $this->assertSame(0, $v['crew_page_recent_completed_days']);
        $this->assertSame(28, $v['crew_page_upcoming_days']);
    }
}

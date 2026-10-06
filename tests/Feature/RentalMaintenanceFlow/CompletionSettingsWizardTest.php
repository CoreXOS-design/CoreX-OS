<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalWorkOrderSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.14 / CLAUDE.md non-negotiable #10a — the four completion-check settings
 * (`tenant_completion_check_enabled`, `completion_response_window_days`, `notify_landlord_on_dispute`,
 * `dispute_notify_crew_immediately`) each have a control on Settings → Rental Work Orders AND on the Setup Wizard's Rentals
 * step, saved by ONE narrow, has()-guarded saver, shown back with their saved values (never a stale default), and isolated
 * per agency. Input paths proven: defaults for an agency that never set anything; the settings page save; every field
 * rejected when out of range or not a number; the wizard step carrying all four with explain + affects; a wizard save that
 * omits a field leaves it alone (onboarding spec §6.1) while one that posts "0" turns it off; reopening shows the saved value;
 * the permission key; a second agency keeps its own values.
 */
final class CompletionSettingsWizardTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
    use RefreshDatabase;

    private const KEYS = ['tenant_completion_check_enabled', 'completion_response_window_days', 'notify_landlord_on_dispute', 'dispute_notify_crew_immediately'];

    private function admin(Agency $agency): User
    {
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);

        return User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function agency(string $name = 'Coastal Realty'): Agency
    {
        return Agency::create(['name' => $name, 'slug' => 'coastal-' . uniqid()]);
    }

    private function saveStep(User $admin, Agency $agency, array $overrides, array $remove = []): \Illuminate\Testing\TestResponse
    {
        $fields = array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), $overrides);
        foreach ($remove as $key) {
            unset($fields[$key]);
        }

        return $this->actingAs($admin)->post(route('corex.agency-setup.step.save', ['step' => 'leases']), $fields);
    }

    // ── Defaults ─────────────────────────────────────────────────────────

    public function test_an_agency_that_never_set_anything_gets_the_neutral_defaults(): void
    {
        $agency = $this->agency();

        $this->assertTrue(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($agency->id));
        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        $this->assertTrue(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($agency->id));
        $this->assertFalse(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id));
    }

    // ── The settings page ────────────────────────────────────────────────

    public function test_the_settings_page_has_all_four_controls_with_their_current_values(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], ['completion_response_window_days' => 11, 'dispute_notify_crew_immediately' => true]);

        $html = $this->actingAs($admin)->get(route('corex.settings.rental-work-orders.edit'))->assertOk()->getContent();

        foreach (self::KEYS as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $html, $key);
        }
        $this->assertStringContainsString('value="11"', $html);
        $this->assertMatchesRegularExpression('/name="dispute_notify_crew_immediately" value="1" checked/', $html);
        $this->assertStringContainsString(route('corex.settings.rental-work-orders.completion-check'), $html);
    }

    public function test_the_settings_page_saves_every_field(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.completion-check'), [
            'tenant_completion_check_enabled' => '0', 'completion_response_window_days' => '12',
            'notify_landlord_on_dispute' => '0', 'dispute_notify_crew_immediately' => '1',
        ])->assertRedirect(route('corex.settings.rental-work-orders.edit'))->assertSessionHasNoErrors();

        $this->assertFalse(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($agency->id));
        $this->assertSame(12, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        $this->assertFalse(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($agency->id));
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id));
    }

    public function test_out_of_range_and_malformed_days_are_rejected_and_save_nothing(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        foreach (['0', '31', '-3', 'five', '2.5'] as $bad) {
            $this->actingAs($admin)->from(route('corex.settings.rental-work-orders.edit'))
                ->post(route('corex.settings.rental-work-orders.completion-check'), ['completion_response_window_days' => $bad])
                ->assertSessionHasErrors('completion_response_window_days');
        }
        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id), 'never saved');

        foreach (['1', '30'] as $edge) {
            $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.completion-check'), ['completion_response_window_days' => $edge])->assertSessionHasNoErrors();
            $this->assertSame((int) $edge, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        }
    }

    public function test_a_post_that_omits_fields_leaves_the_others_alone(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], [
            'tenant_completion_check_enabled' => false, 'completion_response_window_days' => 9, 'notify_landlord_on_dispute' => false, 'dispute_notify_crew_immediately' => true,
        ]);

        // only the window arrives (blank toggles are ABSENT, not "off"): nothing else may move
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.completion-check'), ['completion_response_window_days' => '14'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post(route('corex.settings.rental-work-orders.completion-check'), [])->assertSessionHasNoErrors();   // the lazy empty post

        $this->assertFalse(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($agency->id));
        $this->assertSame(14, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        $this->assertFalse(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($agency->id));
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id));
    }

    public function test_the_saver_needs_the_manage_settings_key(): void
    {
        $agency = $this->agency();
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        \App\Models\RolePermission::updateOrCreate(['role' => 'admin', 'permission_key' => 'rental_work_orders.manage_settings', 'agency_id' => $agency->id], ['scope' => 'all']);
        \App\Services\PermissionService::clearCache();

        $this->actingAs($agent)->post(route('corex.settings.rental-work-orders.completion-check'), ['completion_response_window_days' => '20'])->assertStatus(403);

        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
    }

    // ── The Setup Wizard ─────────────────────────────────────────────────

    public function test_each_control_is_in_the_wizard_with_an_explanation_and_a_concrete_consequence(): void
    {
        $controls = collect(config('agency-onboarding-copy.leases.controls'))->where('source', 'rental_work_orders')->keyBy('key');

        foreach (self::KEYS as $key) {
            $control = $controls->get($key);
            $this->assertNotNull($control, "{$key} must be a wizard control");
            $this->assertGreaterThan(60, strlen((string) $control['explain']), "{$key}: explain is a full sentence");
            $this->assertGreaterThan(60, strlen((string) $control['affects']), "{$key}: affects names a concrete consequence");
            $this->assertStringNotContainsStringIgnoringCase('home finders', $control['explain'] . $control['affects'], 'no agency-specific wording');
        }
        $this->assertSame('number', $controls['completion_response_window_days']['type']);
        $this->assertSame(5, $controls['completion_response_window_days']['default']);
        $this->assertSame(1, $controls['dispute_notify_crew_immediately']['default'] ^ 1, 'crew-straight-back is off by default');
    }

    public function test_the_wizard_step_renders_the_controls_and_saves_them(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()->getContent();
        foreach (self::KEYS as $key) {
            $this->assertStringContainsString('name="' . $key . '"', $html, "{$key} is on the wizard page");
        }

        $this->saveStep($admin, $agency, [
            'tenant_completion_check_enabled' => '0', 'completion_response_window_days' => '8', 'notify_landlord_on_dispute' => '0', 'dispute_notify_crew_immediately' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(RentalWorkOrderSetting::tenantCompletionCheckEnabledFor($agency->id));
        $this->assertSame(8, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        $this->assertFalse(RentalWorkOrderSetting::notifyLandlordOnDisputeFor($agency->id));
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id));
    }

    public function test_reopening_the_wizard_shows_the_saved_values_not_the_hardcoded_defaults(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        $this->saveStep($admin, $agency, ['completion_response_window_days' => '17', 'dispute_notify_crew_immediately' => '1', 'notify_landlord_on_dispute' => '0'])->assertSessionHasNoErrors();

        $html = $this->actingAs($admin)->get(route('corex.agency-setup.step', ['step' => 'leases']))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="completion_response_window_days"[^>]*value="17"|value="17"[^>]*name="completion_response_window_days"/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="dispute_notify_crew_immediately"[^>]*checked|<input[^>]*name="dispute_notify_crew_immediately"[^>]*type="checkbox"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="checkbox"[^>]*name="notify_landlord_on_dispute"[^>]*checked|<input[^>]*name="notify_landlord_on_dispute"[^>]*type="checkbox"[^>]*checked/', $html);
    }

    public function test_a_wizard_post_that_omits_a_field_does_not_wipe_it(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], ['completion_response_window_days' => 21, 'dispute_notify_crew_immediately' => true]);

        // a step that did not render these controls: the keys are simply absent from the post
        $this->saveStep($admin, $agency, [], self::KEYS)->assertSessionHasNoErrors();

        $this->assertSame(21, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id), 'absent means leave alone');
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id));
    }

    public function test_the_wizard_refuses_a_bad_window_and_rolls_the_whole_step_back(): void
    {
        $agency = $this->agency();
        $admin = $this->admin($agency);

        $this->saveStep($admin, $agency, ['completion_response_window_days' => '99', 'dispute_notify_crew_immediately' => '1'])->assertSessionHasErrors('completion_response_window_days');

        $this->assertSame(5, RentalWorkOrderSetting::completionResponseWindowDaysFor($agency->id));
        $this->assertFalse(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($agency->id), 'atomic per step — nothing half-saved');
    }

    public function test_a_second_agency_keeps_its_own_values(): void
    {
        $home = $this->agency('Home Lettings');
        $cape = $this->agency('Cape Town Rentals');
        $homeAdmin = $this->admin($home);
        $capeAdmin = $this->admin($cape);

        $this->saveStep($homeAdmin, $home, ['completion_response_window_days' => '3', 'dispute_notify_crew_immediately' => '1'])->assertSessionHasNoErrors();
        $this->saveStep($capeAdmin, $cape, ['completion_response_window_days' => '20', 'dispute_notify_crew_immediately' => '0'])->assertSessionHasNoErrors();

        $this->assertSame(3, RentalWorkOrderSetting::completionResponseWindowDaysFor($home->id));
        $this->assertTrue(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($home->id));
        $this->assertSame(20, RentalWorkOrderSetting::completionResponseWindowDaysFor($cape->id));
        $this->assertFalse(RentalWorkOrderSetting::disputeNotifyCrewImmediatelyFor($cape->id));
    }
}

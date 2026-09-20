<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bug found 2026-09-20 (cc4, while wiring the rental_work_orders display case
 * for Stage 3) — reported by cc1, verified and fixed by cc2:
 * AgencySetupWizardController::currentValues() had no resolution arm at all
 * for the 'rental_inspections' source, so fault_report_window_days and
 * out_inspection_signing_window_days always fell through to
 * `default => $agency->{$key} ?? ($control['default'] ?? null)`. Neither key
 * is an Agency column, so that arm always returned null and the wizard
 * always rendered the hardcoded control default — never the agency's real
 * saved value.
 *
 * Confirmed display-only (the save path is
 * RentalInspectionSettingsController::update, independent of this method) —
 * but not harmless: an agency owner reopening the wizard sees what looks
 * like an unset field and re-saves over their real value, turning a display
 * bug into a real data problem.
 *
 * Verified this is the ONLY control shape with this defect, not assumed:
 * every `source` value actually used anywhere in
 * config/agency-onboarding-copy.php was cross-checked against
 * currentValues()'s match arms. 'agency' (22 controls) needs no arm — it IS
 * the match's own `default` case, and every one of those 22 keys was
 * confirmed to be a real column in database/schema/mysql-schema.sql.
 * 'perf'/'deal_sync'/'proforma'/'mailbox'/'rental_application' resolve
 * generically by $key, so a control name typo or omission is not the same
 * failure mode as this bug (a whole source with zero arm). Only
 * 'rental_inspections' had no arm at all — the mechanism itself is sound;
 * this was two keys wired wrong, not a systemic pattern. (Separately noted,
 * not fixed here as out of scope: 'leases' and 'rental_work_orders' each
 * hardcode a single resolver call rather than dispatching by $key — safe
 * today because each source has exactly one control, but not robust to a
 * second control being added under the same source later.)
 */
final class AgencySetupWizardCurrentValuesTest extends TestCase
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

    /**
     * The exact repro the conductor asked for: save a value, reopen the
     * wizard, assert the SAVED value renders — not the hardcoded default.
     * This fails against the pre-fix code (both fields always render 7,
     * the config's declared default, regardless of what was actually saved).
     */
    public function test_rental_inspection_controls_render_the_saved_value_not_the_hardcoded_default(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty-' . uniqid()]);
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'expiry_notice_window_days' => 60,
                // The two keys this bug affects — deliberately NOT the
                // control's own default of 7, so a fall-through to the
                // default would be caught rather than coincidentally match.
                'fault_report_window_days' => 37,
                'out_inspection_signing_window_days' => 53,
                'no_approval_spend_threshold' => 500,
                // .ai/specs/rental-application-field-config.md joined this
                // same shared step after this test was first written — every
                // field below is something a real page load actually renders
                // (the tick grid defaults every field to shown, the 4
                // toggles and return_gate_method all carry real defaults),
                // so a genuine browser submission sends all of it. Confirmed
                // directly: adding ONLY the return-gate fields is NOT
                // enough — updateFieldDisplayConfig()/updateRequiredFields()/
                // the 4 boolean savers each flash their own "did not save"
                // error via has()-guard when their field is absent, and
                // since none of them throw, the loop keeps running past
                // them — so the request still redirects forward looking
                // like success while a stale flashed error survives into
                // the session, still failing assertSessionHasNoErrors().
                'shown_field_keys' => collect(\App\Models\RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
                'required_field_keys' => [],
                'field_display_submitted' => '1',
                'required_fields_submitted' => '1',
                'return_gate_method' => 'id_number',
                'return_gate_attempt_max' => 6,
                'return_gate_attempt_window_minutes' => 15,
                'lock_property_after_submission' => '1',
                'tag_contact_as_tenant_on_approval' => '1',
                'require_fica_before_authorisation' => '0',
                'document_uploads_open_after_approval' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        // Confirm the save actually landed (would fail loudly, not silently,
        // if it hadn't — distinguishing "didn't save" from "saved but not
        // displayed", which is the actual bug under test).
        $this->assertSame(37, \App\Models\RentalInspectionSetting::faultReportWindowDaysFor($agency->id));
        $this->assertSame(53, \App\Models\RentalInspectionSetting::signingWindowDaysFor($agency->id));

        $response = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk();

        $response->assertSee('value="37"', false);
        $response->assertSee('value="53"', false);
        // The pre-fix bug rendered the control's own hardcoded default (7)
        // for BOTH fields regardless of what was saved.
        $response->assertDontSee('value="7"', false);
    }

    /**
     * Mechanism-wide guard: every `source` a wizard control declares in
     * config/agency-onboarding-copy.php must have a corresponding match arm
     * in currentValues() — otherwise it silently falls through to the
     * agency-column default and always shows the wrong value, exactly like
     * this bug. Catches the NEXT missing arm before it ships, not just this one.
     */
    public function test_every_control_source_in_the_wizard_config_has_a_resolution_arm(): void
    {
        $config = require config_path('agency-onboarding-copy.php');

        $sources = [];
        foreach ($config as $step) {
            foreach (($step['controls'] ?? []) as $control) {
                $sources[$control['source'] ?? 'agency'] = true;
            }
        }
        $this->assertNotEmpty($sources, 'sanity check: the config actually declares controls');

        $controllerSource = file_get_contents(app_path('Http/Controllers/CoreX/AgencySetupWizardController.php'));
        preg_match(
            '/private function currentValues\(.*?\n    \}\n\n    (?:private|public) function/s',
            $controllerSource,
            $m
        );
        $this->assertNotEmpty($m, 'could not locate currentValues() in AgencySetupWizardController — test needs updating, not disabling');
        $method = $m[0];

        foreach (array_keys($sources) as $source) {
            if ($source === 'agency') {
                // The match's own `default` arm IS the agency-column resolver,
                // by design — not a missing case.
                continue;
            }
            $this->assertMatchesRegularExpression(
                "/'" . preg_quote($source, '/') . "'\s*=>/",
                $method,
                "config declares a control with source '{$source}' but currentValues() has no matching arm — "
                . "it will silently fall through to the agency-column default and always show the wrong value."
            );
        }
    }
}

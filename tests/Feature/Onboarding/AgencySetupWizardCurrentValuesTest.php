<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Onboarding\Concerns\PostsWizardStepLikeABrowser;
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
 * SAME BUG CLASS, found again 2026-09-28 (cc2), fixed same day: three more
 * 'rental_inspections' controls (public_link_expiry_days,
 * auto_pair_photos_enabled, auto_send_report_enabled — all added the same
 * day as the shared signed-document distribution feature, commit
 * 2fd77ff40) were declared in config/agency-onboarding-copy.php without this
 * method's match arm ever being extended to cover them — silently falling
 * through to their own hardcoded default, identical failure mode. Auditing
 * this whole method for the same class also found a SECOND, distinct
 * instance this file's own docblock had already flagged but left
 * unfixed: 'leases' was hardcoded to always call
 * expiryNoticeWindowDaysFor(), ignoring $key — so its second control,
 * default_deposit_months, silently displayed the expiry-window value
 * instead of its own. 'rental_work_orders' had the identical single-
 * hardcoded-call shape but no live bug yet (only one control exists under
 * it today) — hardened to an explicit per-key match anyway, since that
 * source's own spec names two more Stage-4 settings landing there later.
 *
 * Every match arm in currentValues() is now an explicit per-key match —
 * one entry per control actually declared under that source — rather than
 * either "no arm" or "one hardcoded call ignoring $key." Verified this is
 * complete, not assumed: every `source` value actually used anywhere in
 * config/agency-onboarding-copy.php was cross-checked against
 * currentValues()'s match arms, AND every explicit-match arm's declared
 * keys were cross-checked against every control actually declared under
 * that source (not just "does an arm exist," but "does the arm cover every
 * key the config names"). 'agency' (22 controls) needs no arm — it IS the
 * match's own `default` case, and every one of those 22 keys was confirmed
 * to be a real column in database/schema/mysql-schema.sql.
 * 'perf'/'deal_sync'/'proforma'/'mailbox'/'rental_application' resolve
 * generically by $key (a real column/method lookup, not a hardcoded
 * per-source value), so a control name typo or omission there is a
 * different, structurally-can't-happen-the-same-way failure mode — checked
 * anyway: every fillable column and resolver method name was confirmed
 * against every declared key under those five sources, no gaps found.
 * 'proforma's `start_number` deliberately resolves to its own hardcoded
 * default always — verified as intentional, not a bug: it is a one-shot
 * "advance the counter to at least this number" action
 * (ProformaSettingsController::update()), not a persisted preference with
 * its own current value, same write-only shape as 'mailbox's `password`.
 */
final class AgencySetupWizardCurrentValuesTest extends TestCase
{
    use PostsWizardStepLikeABrowser;
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

        // 2026-10-06 — the baseline is what the page itself would post (every
        // hidden/checked/typed field, serialised the way a browser does it), with
        // the hand-written values below layered on top as explicit overrides.
        // The hand-maintained list alone went stale each time a new toggle joined
        // this step (the crew-link toggles were the third time): a real browser
        // always sends every toggle — the wizard renders a hidden "0" beside each
        // checkbox — so a payload missing one is a payload no user can produce,
        // and the saver's has()-guard (absent = refuse, never wipe) rightly says
        // "That did not save". Nothing below is loosened; the saver is untouched.
        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), array_replace($this->browserFormFields($admin, 'leases'), $this->alpineListRows($agency), [
                'expiry_notice_window_days' => 60,
                // The two keys this bug affects — deliberately NOT the
                // control's own default of 7, so a fall-through to the
                // default would be caught rather than coincidentally match.
                'fault_report_window_days' => 37,
                'out_inspection_signing_window_days' => 53,
                'no_approval_spend_threshold' => 500,
                // 2026-09-28 — three more has()-guarded toggle savers joined
                // this same step the same day as the shared signed-document
                // distribution feature (commit 2fd77ff40) and the Job 3
                // Inventory follow-up: RentalInspectionSettingsController::
                // updateAutoPairPhotosEnabled()/updateAutoSendReportEnabled()
                // and RentalInventorySettingsController::
                // updateAutoSendReportEnabled(). Each rejects the WHOLE step
                // save with a flashed "did not save" error when its own
                // field is absent (same has()-guard discipline as every
                // other toggle on this step) — a real page load always
                // renders all three, so a genuine browser submission always
                // sends them.
                'auto_pair_photos_enabled' => '1',
                'auto_send_report_enabled' => '1',
                'inventory_auto_send_report_enabled' => '1',
                'public_link_expiry_days' => 90,
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
                // AT-430 — approval_mode is a required radio (rejects the
                // whole save with a validation error, not a has()-guard, if
                // absent); require_checklist_complete is has()-guarded like
                // every other toggle on this step.
                'approval_mode' => 'two_step',
                'require_checklist_complete' => '0',
            ]))
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

        // Asserted against each control's OWN input, not the page as a whole:
        // the pre-fix bug rendered the control's hardcoded default (7) for BOTH
        // fields regardless of what was saved — but other controls on this
        // shared step legitimately default to 7 too (crew page windows,
        // reminder days), so a page-wide "no value=7 anywhere" check can only
        // go stale as settings are added.
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/id="f_fault_report_window_days"[^>]*value="37"/', $html);
        $this->assertMatchesRegularExpression('/id="f_out_inspection_signing_window_days"[^>]*value="53"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="f_fault_report_window_days"[^>]*value="7"/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="f_out_inspection_signing_window_days"[^>]*value="7"/', $html);
    }

    /**
     * 2026-09-28 — the SAME repro pattern as the test above, for the two
     * NEW bugs found auditing every match arm for this same class: 'leases'
     * (default_deposit_months silently showed expiry_notice_window_days's
     * value) and the three 'rental_inspections' controls added alongside
     * the shared signed-document distribution feature. Direct unit coverage
     * of currentValues() itself (via Reflection) rather than another full
     * HTML-render repro — precise for booleans, where asserting an
     * unchecked checkbox via `assertSee`/`assertDontSee` on raw HTML would
     * be fragile (the literal word "checked" can appear elsewhere on the
     * page for unrelated controls). Every value below is deliberately NOT
     * its own control's hardcoded default, so a fall-through would be
     * caught rather than coincidentally matching.
     */
    public function test_currentValues_resolves_every_leases_step_control_to_its_own_saved_value(): void
    {
        $agency = Agency::create(['name' => 'Coastal Realty Extra', 'slug' => 'coastal-realty-extra-' . uniqid()]);

        \App\Models\LeaseSetting::updateOrCreate(['agency_id' => $agency->id], [
            'expiry_notice_window_days' => 44, // control default: 60
            'default_deposit_months' => 3.5,    // control default: 1
        ]);
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $agency->id], [
            'public_link_expiry_days' => 45,   // control default: 90
            'auto_pair_photos_enabled' => false, // control default: 1 (true)
            'auto_send_report_enabled' => false, // control default: 1 (true)
        ]);
        \App\Models\RentalInventorySetting::updateOrCreate(['agency_id' => $agency->id], [
            'auto_send_report_enabled' => false, // control default: 1 (true)
        ]);
        // 2026-10-06 — the two AT-442 pricing toggles and the §43 scheduling
        // notification settings had no arm (config declared them, the match never named them).
        \App\Models\RentalWorkOrderSetting::updateOrCreate(['agency_id' => $agency->id], [
            'capture_prices_on_job_cards' => false,     // control default: 1
            'show_costs_on_printed_job_card' => true,  // control default: 0
        ]);
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $agency->id], [
            'notify_tenant_enabled' => false,   // control default: on
            'minimum_notice_days' => 5,
            'reminder_days_before' => 4,
        ]);

        $config = require config_path('agency-onboarding-copy.php');
        $controller = new \App\Http\Controllers\CoreX\AgencySetupWizardController();
        $method = new \ReflectionMethod($controller, 'currentValues');
        $method->setAccessible(true);
        $values = $method->invoke($controller, $config['leases'], $agency->fresh());

        $this->assertSame(44, $values['expiry_notice_window_days']);
        $this->assertSame(3.5, $values['default_deposit_months']);
        $this->assertSame(45, $values['public_link_expiry_days']);
        $this->assertFalse($values['auto_pair_photos_enabled']);
        $this->assertFalse($values['auto_send_report_enabled']);
        $this->assertFalse($values['inventory_auto_send_report_enabled']);
        $this->assertFalse($values['capture_prices_on_job_cards']);
        $this->assertTrue($values['show_costs_on_printed_job_card']);
        $this->assertFalse($values['notify_tenant_enabled']);
        $this->assertSame(5, $values['minimum_notice_days']);
        $this->assertSame(4, $values['reminder_days_before']);
    }

    /**
     * Mechanism-wide guard: every `source` a wizard control declares in
     * config/agency-onboarding-copy.php must have a corresponding match arm
     * in currentValues() — otherwise it silently falls through to the
     * agency-column default and always shows the wrong value, exactly like
     * this bug. Catches the NEXT missing arm before it ships, not just this one.
     *
     * 2026-09-28 — extended to also check WITHIN each "explicit per-key"
     * arm (leases/rental_work_orders/rental_inspections/rental_inventories,
     * the sources that dispatch via an inner `match ($key)` rather than a
     * generic column/method lookup): every control the config actually
     * declares under that source must appear as an explicit key in that
     * arm's own source text, not just "does the source have an arm at all."
     * This is the exact shape of both new bugs this pass fixed — an arm
     * existed, but a control added later was never added to it.
     */
    public function test_every_control_source_in_the_wizard_config_has_a_resolution_arm(): void
    {
        $config = require config_path('agency-onboarding-copy.php');

        $sources = [];
        $keysBySource = [];
        foreach ($config as $step) {
            foreach (($step['controls'] ?? []) as $control) {
                $source = $control['source'] ?? 'agency';
                $sources[$source] = true;
                $keysBySource[$source][] = $control['key'];
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

        // Sources whose arm dispatches on an inner `match ($key)` — the
        // shape that can silently go stale one control at a time. Sources
        // resolving generically by column/method name (perf/deal_sync/
        // proforma/mailbox/rental_application) can't have this exact defect
        // (a control name typo there is a different failure mode, already
        // checked by hand in this test class's own docblock).
        $explicitPerKeySources = ['leases', 'rental_work_orders', 'rental_inspections', 'rental_inventories', 'rental_portal'];
        foreach ($explicitPerKeySources as $source) {
            if (! isset($keysBySource[$source])) {
                continue;
            }
            preg_match(
                "/'" . preg_quote($source, '/') . "'\s*=>\s*match\s*\(\\\$key\)\s*\{(.*?)\},\n/s",
                $method,
                $armMatch
            );
            $this->assertNotEmpty($armMatch, "could not locate the inner match(\$key) body for source '{$source}' — test needs updating, not disabling");
            $armBody = $armMatch[1];

            foreach ($keysBySource[$source] as $key) {
                $this->assertMatchesRegularExpression(
                    "/'" . preg_quote($key, '/') . "'\s*=>/",
                    $armBody,
                    "config declares key '{$key}' under source '{$source}', but currentValues()'s own "
                    . "match(\$key) arm for that source never names it — it will silently fall through to "
                    . "that control's hardcoded default and always show the wrong value, exactly like the "
                    . "2026-09-28 default_deposit_months/rental_inspections bugs."
                );
            }
        }
    }
}

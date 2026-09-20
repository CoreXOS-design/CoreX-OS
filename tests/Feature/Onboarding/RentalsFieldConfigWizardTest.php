<?php

namespace Tests\Feature\Onboarding;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md — conductor's ruling, 2026-09-20:
 * cc5's single-agency sweep found the per-agency rental application field
 * configuration settings never reached the onboarding wizard (non-negotiable
 * #10a breach). This proves the fix, split exactly along the ruling's own
 * boundary:
 *
 *   - IN the wizard: the shipped-field tick grid (shown/required), driven
 *     through the EXISTING updateFieldDisplayConfig()/updateRequiredFields()
 *     endpoints, plus 5 scalar toggles/select (lock_property_after_submission,
 *     tag_contact_as_tenant_on_approval, require_fica_before_authorisation,
 *     document_uploads_open_after_approval, return_gate_method).
 *   - OUT of the wizard, deliberately: label/help-text overrides, field_order,
 *     marital_status_options, the custom-field editor, identity_gate_enabled
 *     (a universal security decision, not a customisation), and rate-limit
 *     knobs (expert carve-out).
 *
 * The single most important case here is
 * test_saving_the_wizard_tick_grid_never_wipes_an_existing_agencys_label_and_help_text_overrides
 * — this step's own partial never RENDERS those overrides, so without the
 * preserve-and-repost hidden inputs in rentals-field-config.blade.php,
 * saving this step would silently wipe them via updateFieldDisplayConfig()'s
 * own "absent means no overrides" behaviour. Exactly the incident class
 * named in agency-onboarding-setup.md §6.1, freshly reintroduced by this
 * build if not guarded — proven the hard way here, not assumed.
 */
final class RentalsFieldConfigWizardTest extends TestCase
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

    private function newAgency(string $name): Agency
    {
        return Agency::create(['name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid()]);
    }

    /** (a) A fresh agency ticking the shipped-field grid persists shown/required correctly. */
    public function test_saving_the_tick_grid_persists_shown_and_required_for_a_fresh_agency(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $allKeys = collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all();
        // Hide exactly one known field, require exactly one different known field.
        $shown = array_values(array_diff($allKeys, ['spouse_name']));
        $required = ['id_number'];

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'shown_field_keys' => $shown,
                'required_field_keys' => $required,
                'field_display_submitted' => '1',
                'required_fields_submitted' => '1',
                'return_gate_method' => 'id_number',
                'return_gate_attempt_max' => 6,
                'return_gate_attempt_window_minutes' => 15,
                'lock_property_after_submission' => '1',
                'tag_contact_as_tenant_on_approval' => '1',
                'require_fica_before_authorisation' => '0',
                'document_uploads_open_after_approval' => '1',
                'expiry_notice_window_days' => 60,
                'fault_report_window_days' => 7,
                'out_inspection_signing_window_days' => 7,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(['spouse_name'], RentalApplicationQualifyingSetting::hiddenFieldKeysFor($agency->id));
        $this->assertSame(['id_number'], RentalApplicationQualifyingSetting::requiredFieldKeysFor($agency->id));
    }

    /**
     * (b) THE critical case: an agency that already customised labels, help
     * text and field order on the real settings screen must see every one
     * of those survive a wizard save that only renders shown/required.
     */
    public function test_saving_the_wizard_tick_grid_never_wipes_an_existing_agencys_label_and_help_text_overrides(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        RentalApplicationQualifyingSetting::create([
            'agency_id' => $agency->id,
            'field_label_overrides' => ['id_number' => 'Passport or ID number', 'employer_name' => 'Company name'],
            'field_help_text_overrides' => ['id_number' => 'South African ID or passport number.'],
            'field_order' => ['id_number', 'full_name', 'employer_name'],
            'required_field_keys' => ['full_name'],
            'hidden_field_keys' => ['spouse_id'],
        ]);

        $allKeys = collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all();
        $shown = array_values(array_diff($allKeys, ['tpn_consent_signature']));

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'shown_field_keys' => $shown,
                'required_field_keys' => ['full_name', 'id_number'],
                'field_display_submitted' => '1',
                'required_fields_submitted' => '1',
                // Preserve-and-repost — exactly what rentals-field-config.blade.php
                // renders as hidden inputs from the agency's CURRENT overrides.
                'field_labels' => ['id_number' => 'Passport or ID number', 'employer_name' => 'Company name'],
                'field_help_text' => ['id_number' => 'South African ID or passport number.'],
                'field_order' => ['id_number' => 0, 'full_name' => 1, 'employer_name' => 2],
                'return_gate_method' => 'id_number',
                'return_gate_attempt_max' => 6,
                'return_gate_attempt_window_minutes' => 15,
                'lock_property_after_submission' => '1',
                'tag_contact_as_tenant_on_approval' => '1',
                'require_fica_before_authorisation' => '0',
                'document_uploads_open_after_approval' => '1',
                'expiry_notice_window_days' => 60,
                'fault_report_window_days' => 7,
                'out_inspection_signing_window_days' => 7,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['id_number' => 'Passport or ID number', 'employer_name' => 'Company name'],
            RentalApplicationQualifyingSetting::fieldLabelOverridesFor($agency->id),
            'label overrides this step never renders must survive unchanged'
        );
        $this->assertSame(
            ['id_number' => 'South African ID or passport number.'],
            RentalApplicationQualifyingSetting::fieldHelpTextOverridesFor($agency->id),
            'help-text overrides this step never renders must survive unchanged'
        );
        $this->assertSame(
            ['id_number', 'full_name', 'employer_name'],
            RentalApplicationQualifyingSetting::fieldOrderFor($agency->id),
            'field order this step never renders must survive unchanged'
        );
        // And the fields this step DOES own actually changed, proving this
        // isn't just "nothing saved at all".
        $this->assertSame(['tpn_consent_signature'], RentalApplicationQualifyingSetting::hiddenFieldKeysFor($agency->id));
        $this->assertEqualsCanonicalizing(['full_name', 'id_number'], RentalApplicationQualifyingSetting::requiredFieldKeysFor($agency->id));
    }

    /** (c) The 5 new scalar settings persist and don't disturb the pre-existing lease/inspection savers. */
    public function test_saving_the_five_scalar_rental_application_settings_alongside_the_pre_existing_lease_settings(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);

        $this->actingAs($admin)
            ->post(route('corex.agency-setup.step.save', ['step' => 'leases']), [
                'shown_field_keys' => collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all(),
                'required_field_keys' => [],
                'field_display_submitted' => '1',
                'required_fields_submitted' => '1',
                'return_gate_method' => 'email_otp',
                'return_gate_attempt_max' => 10,
                'return_gate_attempt_window_minutes' => 30,
                'lock_property_after_submission' => '0',
                'tag_contact_as_tenant_on_approval' => '0',
                'require_fica_before_authorisation' => '1',
                'document_uploads_open_after_approval' => '0',
                'expiry_notice_window_days' => 45,
                'fault_report_window_days' => 12,
                'out_inspection_signing_window_days' => 9,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertFalse(RentalApplicationQualifyingSetting::lockPropertyAfterSubmissionFor($agency->id));
        $this->assertFalse(RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor($agency->id));
        $this->assertTrue(RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor($agency->id));
        $this->assertFalse(RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor($agency->id));
        $this->assertSame('email_otp', RentalApplicationQualifyingSetting::returnGateMethodFor($agency->id));

        // The pre-existing (Phase C) lease/inspection savers on this same step
        // are untouched by wiring in a 7th/8th/9th... saver.
        $this->assertSame(45, \App\Models\LeaseSetting::expiryNoticeWindowDaysFor($agency->id));
        $this->assertSame(12, \App\Models\RentalInspectionSetting::faultReportWindowDaysFor($agency->id));
        $this->assertSame(9, \App\Models\RentalInspectionSetting::signingWindowDaysFor($agency->id));
    }

    /** (d) An agency that completed the wizard before these settings existed sees real defaults, never blanks. */
    public function test_existing_agency_with_no_rental_application_settings_row_sees_correct_defaults_not_blanks(): void
    {
        $agency = $this->newAgency('Coastal Realty');
        $admin = $this->admin($agency);
        // Deliberately no RentalApplicationQualifyingSetting row at all — the
        // exact shape of an agency that onboarded before this build shipped.

        $response = $this->actingAs($admin)
            ->get(route('corex.agency-setup.step', ['step' => 'leases']))
            ->assertOk();

        // Defaults per RentalApplicationQualifyingSetting's own DEFAULT_* constants
        // (true/true/false/true/id_number) — never null, never coerced to "off".
        $this->assertTrue(RentalApplicationQualifyingSetting::lockPropertyAfterSubmissionFor($agency->id));
        $this->assertTrue(RentalApplicationQualifyingSetting::tagContactAsTenantOnApprovalFor($agency->id));
        $this->assertFalse(RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor($agency->id));
        $this->assertTrue(RentalApplicationQualifyingSetting::documentUploadsOpenAfterApprovalFor($agency->id));
        $this->assertSame('id_number', RentalApplicationQualifyingSetting::returnGateMethodFor($agency->id));

        // Every shipped field defaults to shown, none compulsory — the view
        // must render this from resolvedFieldConfigFor(), not blank/missing rows.
        $response->assertSee('Full name and surname');
        $response->assertSee('Rental application settings');
    }

    /**
     * (e) No HFC/Shelly Beach wording anywhere a USER can see it — non-negotiable #9.
     * Developer comments (Blade {{-- --}} / PHP // and /* *\/) routinely name Johan
     * or HFC for traceability across this codebase (e.g. updateReturnGate()'s own
     * docblock) and are never rendered — only actual copy strings and rendered
     * markup count, so comments are stripped before scanning.
     */
    public function test_no_hfc_specific_wording_in_the_new_user_facing_copy(): void
    {
        $forbidden = ['HFC', 'Home Finders', 'Shelly Beach', 'hfcoastal'];

        $partial = file_get_contents(resource_path('views/agency-setup/steps/rentals-field-config.blade.php'));
        $partial = preg_replace('/\{\{--.*?--\}\}/s', '', $partial);

        foreach ($forbidden as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $partial, "found '{$term}' in rentals-field-config.blade.php's rendered markup");
        }

        $config = file_get_contents(config_path('agency-onboarding-copy.php'));
        $start = strpos($config, "'leases' => [");
        $end = strpos($config, "'properties' => [");
        $leasesStepSource = substr($config, $start, $end - $start);
        $leasesStepSource = preg_replace('#/\*.*?\*/#s', '', $leasesStepSource);
        $leasesStepSource = preg_replace('#//[^\n]*#', '', $leasesStepSource);

        foreach ($forbidden as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $leasesStepSource, "found '{$term}' in the leases step's user-facing copy values");
        }
    }
}

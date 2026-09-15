<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\RentalApplication;
use Tests\TestCase;

/**
 * Submission hard floor, AT-392 round 5, 2026-09-13 — the settings
 * checklist and submit()'s own validation are driven by ONE array
 * (RentalApplication::submissionFieldRegistry()) so they can never drift.
 * This test is the other half of that promise: a field added to the
 * public applicant form later without a matching registry entry fails
 * the build here, rather than silently shipping unenforceable — Johan's
 * own words, "the settings list must not silently miss a field."
 *
 * Deliberately excluded from the registry, and therefore from this
 * coverage check, with reasons on record (see submissionFieldRegistry()'s
 * own docblock): current_rental_to, current_rental_still_living
 * (a flat tick can't express "required unless the box is checked"),
 * property_address_override (read-only, disabled, never applicant-
 * editable), and rental_terms (legacy column, not rendered on the
 * current public form at all).
 */
final class RentalApplicationFieldRegistryCoverageTest extends TestCase
{
    private const DELIBERATELY_EXCLUDED = [
        'current_rental_to',
        'current_rental_still_living',
        'property_address_override',
        'rental_terms',
    ];

    public function test_every_applicant_field_input_on_the_public_form_has_a_registry_entry(): void
    {
        $blade = file_get_contents(resource_path('views/rental-applications/public/show.blade.php'));
        $this->assertNotFalse($blade, 'could not read the public applicant form template');

        preg_match_all('/name="([a-z_]+)"/', $blade, $matches);
        $formFieldNames = array_unique($matches[1]);

        $registryKeys = collect(RentalApplication::submissionFieldRegistry())->pluck('key')->all();

        $uncovered = array_diff($formFieldNames, $registryKeys, self::DELIBERATELY_EXCLUDED, [
            '_token', 'viewport', // not applicant data at all — CSRF token and the <meta name="viewport"> tag
        ]);

        $this->assertEmpty(
            $uncovered,
            'These public-form fields have no submissionFieldRegistry() entry — add one (or add to '
                . 'DELIBERATELY_EXCLUDED with a reason) so this field can be made compulsory: '
                . implode(', ', $uncovered)
        );
    }

    public function test_every_registry_key_that_maps_to_a_real_column_is_a_known_validation_rule(): void
    {
        $rules = RentalApplication::fieldValidationRules();
        $virtual = ['contact_method', 'declaration_signature', 'tpn_consent_signature'];

        foreach (RentalApplication::submissionFieldRegistry() as $field) {
            if (in_array($field['key'], $virtual, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $field['key'],
                $rules,
                "registry key '{$field['key']}' has no matching fieldValidationRules() entry — either it's stale, or the model rule was renamed without updating the registry"
            );
        }
    }
}

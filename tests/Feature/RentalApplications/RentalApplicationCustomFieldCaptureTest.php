<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md §7, piece (c)(2) — custom
 * fields render on the applicant form and validate correctly, through the
 * SAME resolver (RentalApplication::resolvedFieldConfigFor()) and the SAME
 * validation method (submissionValidationRules()) as every shipped field.
 *
 * Covers the real bug found and fixed while building this piece: a
 * literal dot in a custom field's key (the original design) silently
 * broke Laravel's own dot-path resolution in validation rule keys,
 * old(), and error bags — RentalApplicationCustomField::generateKey() now
 * produces `custom_x` (underscore), never `custom.x`.
 */
final class RentalApplicationCustomFieldCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'status' => 'sent', 'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function customField(array $attrs = []): RentalApplicationCustomField
    {
        $label = $attrs['label'] ?? 'Pet Details';

        return RentalApplicationCustomField::create(array_merge([
            'agency_id' => $this->agency->id,
            'key' => RentalApplicationCustomField::generateKey($this->agency->id, $label),
            'label' => $label,
            'field_type' => 'text',
            'sort_order' => 0,
        ], $attrs));
    }

    private function completePayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Jane Applicant',
            'id_number' => '9001015800083',
            'email' => 'jane@example.com',
            'current_residential_address' => '1 Example Road, Ramsgate',
            'monthly_salary' => 20000,
            'rental_term_months' => 12,
            'declaration_signature' => self::SIG,
            'tpn_consent_signature' => self::SIG,
        ], $overrides);
    }

    // ── The key-format bug this piece found and fixed ──────────────────

    public function test_generated_key_never_contains_a_dot(): void
    {
        $field = $this->customField(['label' => 'Pet Deposit']);

        $this->assertSame('custom_pet_deposit', $field->key);
        $this->assertStringNotContainsString('.', $field->key);
    }

    public function test_a_custom_field_value_survives_a_failed_validation_redisplay(): void
    {
        $field = $this->customField();
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), [
            // Deliberately incomplete — missing id_number etc. — the
            // custom field's own value must still round-trip via old(),
            // same AT-392 "losing a tenant's typed answers is
            // unacceptable" standard every shipped field already meets.
            'custom_field_values' => [$field->key => 'One small dog'],
        ]);

        $response->assertSessionHasErrors();
        $response->assertSessionHas('_old_input.custom_field_values.' . $field->key, 'One small dog');
    }

    // ── Rendering / resolver ────────────────────────────────────────────

    public function test_public_form_renders_an_active_custom_field(): void
    {
        $field = $this->customField(['label' => 'Pet Deposit']);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee('Pet Deposit');
        $response->assertSee('name="custom_field_values[' . $field->key . ']"', false);
    }

    public function test_public_form_never_renders_a_retired_custom_field(): void
    {
        $field = $this->customField();
        $field->delete();
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('name="custom_field_values[' . $field->key . ']"', false);
    }

    public function test_public_form_never_renders_a_hidden_custom_field(): void
    {
        $field = $this->customField(['shown' => false]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('name="custom_field_values[' . $field->key . ']"', false);
    }

    public function test_choice_list_field_renders_its_options(): void
    {
        $field = $this->customField(['field_type' => 'choice_list', 'label' => 'Parking', 'options' => ['Covered', 'Open']]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertSee('<option value="Covered"', false);
        $response->assertSee('<option value="Open"', false);
    }

    // ── Validation, per type ─────────────────────────────────────────────

    public function test_a_required_custom_field_blocks_submission_when_blank(): void
    {
        $field = $this->customField(['required' => true]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->completePayload());

        $response->assertSessionHasErrors('custom_field_values.' . $field->key);
        $this->assertSame('sent', $application->fresh()->status);
    }

    public function test_a_hidden_and_required_custom_field_can_never_block_submission(): void
    {
        // A custom field's shown/required live on the SAME row (unlike
        // shipped fields' two-mechanism gap) — activeFor() already
        // excludes it before a rule is ever built.
        $this->customField(['required' => true, 'shown' => false]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->completePayload());

        $response->assertSessionHasNoErrors();
    }

    public function test_a_number_type_field_rejects_non_numeric_input(): void
    {
        $field = $this->customField(['field_type' => 'number', 'label' => 'Deposit']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => 'not-a-number']]));

        $response->assertSessionHasErrors('custom_field_values.' . $field->key);
    }

    public function test_a_number_type_field_accepts_a_comma_formatted_amount(): void
    {
        $field = $this->customField(['field_type' => 'number', 'label' => 'Deposit']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => '1,500']]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('1500', $application->fresh()->custom_field_values[$field->key]);
    }

    public function test_a_choice_list_field_rejects_a_value_not_in_its_options(): void
    {
        $field = $this->customField(['field_type' => 'choice_list', 'label' => 'Parking', 'options' => ['Covered', 'Open']]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => 'Underground']]));

        $response->assertSessionHasErrors('custom_field_values.' . $field->key);
    }

    public function test_a_date_type_field_rejects_a_non_date_value(): void
    {
        $field = $this->customField(['field_type' => 'date', 'label' => 'Move-in']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => 'not-a-date']]));

        $response->assertSessionHasErrors('custom_field_values.' . $field->key);
    }

    // ── Save — the never-replace-a-retired-fields-answer rule ───────────

    public function test_submit_saves_custom_field_values(): void
    {
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => 'One small dog']]));

        $response->assertSessionHasNoErrors();
        $this->assertSame('One small dog', $application->fresh()->custom_field_values[$field->key]);
    }

    public function test_a_resubmit_never_wipes_a_retired_fields_already_captured_answer(): void
    {
        $activeField = $this->customField(['label' => 'Pet Details']);
        $retiredField = $this->customField(['label' => 'Old Question']);
        // 'reopened' — the real status a resubmit actually reaches submit()
        // through (submit() explicitly refuses POST_RETURN_STATUSES,
        // 'returned' included).
        $application = $this->application([
            'status' => 'reopened', 'submitted_at' => now()->subDay(),
            'custom_field_values' => [$retiredField->key => 'An answer from before it was retired'],
        ]);
        $retiredField->delete();

        // returnGatePassed() checks a session flag once submitted_at is
        // set (the same gate a real applicant passes by re-entering their
        // ID on the return-gate screen) — satisfied directly here since
        // this test is exercising submit() itself, not the gate.
        $response = $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true])
            ->post(route('rental-applications.public.submit', $application->token),
                $this->completePayload(['custom_field_values' => [$activeField->key => 'One small dog']]));

        $response->assertSessionHasNoErrors();
        $fresh = $application->fresh();
        $this->assertSame('One small dog', $fresh->custom_field_values[$activeField->key]);
        $this->assertSame('An answer from before it was retired', $fresh->custom_field_values[$retiredField->key], 'retiring the definition must never touch an application that already answered it');
    }

    public function test_clearing_an_active_fields_value_on_resubmit_actually_clears_it(): void
    {
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application([
            'status' => 'reopened', 'submitted_at' => now()->subDay(),
            'custom_field_values' => [$field->key => 'One small dog'],
        ]);

        $response = $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true])
            ->post(route('rental-applications.public.submit', $application->token),
                $this->completePayload(['custom_field_values' => [$field->key => '']]));

        $response->assertSessionHasNoErrors();
        $this->assertNull($application->fresh()->custom_field_values[$field->key]);
    }

    public function test_a_hand_crafted_value_for_a_retired_field_is_never_accepted(): void
    {
        // Never trust blindly — only rules built from activeFor() exist,
        // so a POST naming a retired/unknown key is simply dropped by
        // validate(), never persisted.
        $field = $this->customField(['label' => 'Pet Details']);
        $field->delete();
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['custom_field_values' => [$field->key => 'Sneaky value']]));

        $response->assertSessionHasNoErrors();
        $this->assertEmpty($application->fresh()->custom_field_values ?? []);
    }

    // ── Autosave ─────────────────────────────────────────────────────────

    public function test_autosave_persists_a_custom_field_value(): void
    {
        $field = $this->customField(['label' => 'Pet Details']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.autosave', $application->token), [
            'custom_field_values' => [$field->key => 'Autosaved value'],
        ]);

        $response->assertOk();
        $this->assertSame('Autosaved value', $application->fresh()->custom_field_values[$field->key]);
    }

    public function test_autosave_never_enforces_a_required_custom_field(): void
    {
        $this->customField(['required' => true]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Partial Applicant',
        ]);

        $response->assertOk();
        $response->assertJson(['saved' => true]);
    }

    public function test_autosave_drops_an_invalid_custom_field_entry_without_failing_the_whole_save(): void
    {
        $field = $this->customField(['field_type' => 'number', 'label' => 'Deposit']);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.autosave', $application->token), [
            'full_name' => 'Partial Applicant',
            'custom_field_values' => [$field->key => 'not-a-number'],
        ]);

        $response->assertOk();
        $fresh = $application->fresh();
        $this->assertSame('Partial Applicant', $fresh->full_name, 'the rest of the autosave must still succeed');
        $this->assertEmpty($fresh->custom_field_values ?? [], 'the invalid entry itself must be dropped, not stored');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/rental-application-field-config.md — per-agency SHOWN/HIDDEN,
 * label/help-text overrides, and within-section ordering, built on top of
 * (never duplicating) the existing required_field_keys mechanism covered by
 * RentalApplicationSubmissionHardFloorTest.
 *
 * The critical case this file exists to guard: required_field_keys and
 * hidden_field_keys are two independent agency choices — nothing stops an
 * agency ticking a field BOTH compulsory and hidden. Before
 * effectiveRequiredFieldKeysFor() existed, that combination was an
 * unsatisfiable server-side validation rule: required by submit(), invisible
 * on the form, no way for the applicant to ever pass it. Found by walking
 * the actual page against a real fixture, not by reasoning about the code.
 */
final class RentalApplicationFieldDisplayConfigTest extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

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
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
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

    // ── The bug found and fixed this build: required + hidden conflict ──

    public function test_a_field_ticked_both_required_and_hidden_never_blocks_submission(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['work_number']),
            'hidden_field_keys' => ['work_number'],
        ]);
        $application = $this->application();
        $payload = $this->completePayload();
        unset($payload['work_number']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
        $this->assertSame('returned', $application->fresh()->status);
    }

    public function test_the_public_form_never_renders_the_required_attribute_for_a_hidden_field(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['work_number']),
            'hidden_field_keys' => ['work_number'],
        ]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('name="work_number"', false);
    }

    public function test_a_field_required_but_not_hidden_is_unaffected_by_the_reconciliation(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['work_number']),
            'hidden_field_keys' => ['special_conditions'],
        ]);
        $application = $this->application();
        $payload = $this->completePayload();
        unset($payload['work_number']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors('work_number');
    }

    // ── Shown / hidden ────────────────────────────────────────────────

    public function test_a_hidden_field_does_not_appear_on_the_public_form(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['special_conditions'],
        ]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('name="special_conditions"', false);
    }

    public function test_a_field_not_hidden_still_appears(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['special_conditions'],
        ]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertSee('name="full_name"', false);
    }

    public function test_a_hidden_fields_value_is_never_required_to_submit(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['special_conditions'],
        ]);
        $application = $this->application();
        $payload = $this->completePayload();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
        $this->assertNull($application->fresh()->special_conditions);
    }

    // ── Label / help-text overrides ──────────────────────────────────

    public function test_a_label_override_replaces_the_default_wording_on_the_public_form(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Applicant full legal name'],
        ]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertSee('Applicant full legal name');
    }

    public function test_a_help_text_override_appears_under_its_field(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_help_text_overrides' => ['id_number' => 'Enter your 13-digit RSA ID number exactly as it appears on your ID document.'],
        ]);
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertSee('Enter your 13-digit RSA ID number exactly as it appears on your ID document.');
    }

    // ── resolvedFieldConfigFor() — the one canonical resolver ─────────

    public function test_resolver_returns_full_shipped_registry_when_agency_never_configured_anything(): void
    {
        $config = RentalApplication::resolvedFieldConfigFor($this->agency->id);

        $this->assertArrayHasKey('full_name', $config);
        $this->assertTrue($config['full_name']['shown']);
        $this->assertSame('Full name and surname', $config['full_name']['label']);
        $this->assertNull($config['full_name']['help_text']);
    }

    public function test_resolver_handles_a_null_agency_id_without_error(): void
    {
        $config = RentalApplication::resolvedFieldConfigFor(null);

        $this->assertArrayHasKey('full_name', $config);
        $this->assertTrue($config['full_name']['shown']);
    }

    public function test_within_section_ordering_places_named_fields_first_in_the_configured_sequence(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_order' => ['id_number', 'full_name'],
        ]);

        $config = RentalApplication::resolvedFieldConfigFor($this->agency->id);

        $this->assertSame(0, $config['id_number']['order']);
        $this->assertSame(1, $config['full_name']['order']);
    }

    public function test_ordering_falls_back_to_registry_order_for_fields_left_unnamed(): void
    {
        // Only id_number named — every other Personal Details field keeps
        // its original registry order, appended after the named ones.
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_order' => ['id_number'],
        ]);

        $config = RentalApplication::resolvedFieldConfigFor($this->agency->id);

        $this->assertSame(0, $config['id_number']['order']);
        $this->assertGreaterThan($config['id_number']['order'], $config['full_name']['order']);
    }

    public function test_ordering_is_scoped_within_its_own_section_only(): void
    {
        // 'full_name' (Personal Details) named ahead of 'adults' (Lease
        // Requirement) must never cross section boundaries — each section
        // resolves its own order independently starting from 0.
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_order' => ['full_name', 'adults'],
        ]);

        $config = RentalApplication::resolvedFieldConfigFor($this->agency->id);

        $this->assertSame('Personal Details', $config['full_name']['section']);
        $this->assertSame('Lease Requirement', $config['adults']['section']);
        $this->assertSame(0, $config['adults']['order']);
    }

    // ── Historical integrity — snapshot on submit only ─────────────────

    public function test_a_draft_has_no_snapshot(): void
    {
        $application = $this->application(['status' => 'in_progress']);

        $this->assertNull($application->fresh()->field_config_snapshot);
    }

    public function test_submitting_freezes_the_resolved_config_at_that_moment(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'hidden_field_keys' => ['special_conditions'],
            'field_label_overrides' => ['full_name' => 'Applicant full legal name'],
        ]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->completePayload());
        $response->assertSessionHasNoErrors();

        $snapshot = $application->fresh()->field_config_snapshot;
        $this->assertNotNull($snapshot);
        $this->assertFalse($snapshot['special_conditions']['shown']);
        $this->assertSame('Applicant full legal name', $snapshot['full_name']['label']);
    }

    public function test_a_later_config_change_never_alters_an_already_submitted_snapshot(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'field_label_overrides' => ['full_name' => 'Applicant full legal name'],
        ]);
        $application = $this->application();
        $this->post(route('rental-applications.public.submit', $application->token), $this->completePayload())
            ->assertSessionHasNoErrors();

        RentalApplicationQualifyingSetting::where('agency_id', $this->agency->id)
            ->update(['field_label_overrides' => ['full_name' => 'A brand new label']]);

        $snapshot = $application->fresh()->field_config_snapshot;
        $this->assertSame('Applicant full legal name', $snapshot['full_name']['label']);
    }

    // ── Not retroactive — existing wiring untouched ───────────────────

    public function test_conditional_groups_still_govern_requiredness_exactly_as_before(): void
    {
        // Regression guard: this build's changes to $requiredFieldKeys'
        // SOURCE (effectiveRequiredFieldKeysFor() instead of
        // requiredFieldKeysFor()) must never alter conditional-group
        // behaviour when nothing is hidden.
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['employer_name']),
        ]);
        $application = $this->application();
        $payload = $this->completePayload(['employment_type' => 'permanently_employed']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors('employer_name');
    }
}

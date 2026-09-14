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
 * Submission hard floor, AT-392 round 5, 2026-09-13 — Johan, twice ruled:
 * every field on the applicant form is agency tick/untick
 * (RentalApplicationQualifyingSetting::required_field_keys), no locked
 * set. Before this build, submit() enforced almost nothing — two
 * non-empty signature strings was the entire floor, and an applicant
 * could submit with no name, no ID, no income, no address at all.
 *
 * §2 (BUILD_STANDARD) governs the STORAGE layer — every column stays
 * nullable, a draft/autosave must never be blocked. This gate is a
 * separate, SUBMISSION-layer rule; the tests below prove the two never
 * get conflated (a blank submit is refused, but nothing about a draft's
 * own save path changes).
 */
final class RentalApplicationSubmissionHardFloorTest extends TestCase
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

    /** A submission that satisfies every DEFAULT compulsory field. */
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

    // ── The core defect this build closes ─────────────────────────────

    public function test_a_completely_blank_submission_is_refused_under_the_default_settings(): void
    {
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), []);

        $response->assertSessionHasErrors(['full_name', 'id_number', 'current_residential_address', 'monthly_salary', 'rental_term_months', 'declaration_signature', 'tpn_consent_signature']);
        $this->assertSame('sent', $application->fresh()->status, 'nothing was saved — the refusal is total, not partial');
    }

    public function test_the_error_message_names_the_field_in_plain_language_not_the_column_name(): void
    {
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), []);

        $response->assertSessionHasErrors();
        $errors = session('errors')->getBag('default')->all();
        $this->assertTrue(
            collect($errors)->contains(fn ($m) => str_contains($m, 'Full name')),
            'expected a human-readable "Full name" message, got: ' . implode(' | ', $errors)
        );
        $this->assertFalse(
            collect($errors)->contains(fn ($m) => str_contains($m, 'full_name')),
            'must never leak the raw column name to the applicant'
        );
    }

    public function test_a_fully_complete_submission_under_default_settings_succeeds(): void
    {
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->completePayload());

        $response->assertSessionHasNoErrors();
        $this->assertSame('returned', $application->fresh()->status);
    }

    public function test_cell_alone_satisfies_the_contact_method_requirement_without_email(): void
    {
        $application = $this->application();
        $payload = $this->completePayload(['email' => null, 'cell' => '0821234567']);
        unset($payload['email']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_neither_email_nor_cell_fails_the_contact_method_requirement(): void
    {
        $application = $this->application();
        $payload = $this->completePayload();
        unset($payload['email']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors(['email', 'cell']);
    }

    // ── Conditional groups — the part most likely to be built wrong ──────

    public function test_a_self_employed_applicant_is_never_blocked_by_a_ticked_employer_field(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['employer_name']),
        ]);
        $application = $this->application();
        $payload = $this->completePayload(['employment_type' => 'business_owner_personal_account']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
    }

    public function test_a_permanently_employed_applicant_IS_blocked_by_a_ticked_employer_field_when_left_blank(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['employer_name']),
        ]);
        $application = $this->application();
        $payload = $this->completePayload(['employment_type' => 'permanently_employed']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors('employer_name');
    }

    public function test_landlord_fields_only_apply_when_currently_renting(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['current_landlord_name']),
        ]);
        $application = $this->application();

        $notRenting = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['current_living_situation' => 'owns_or_selling']));
        $notRenting->assertSessionHasNoErrors();

        $applicationB = $this->application();
        $renting = $this->post(route('rental-applications.public.submit', $applicationB->token),
            $this->completePayload(['current_living_situation' => 'renting']));
        $renting->assertSessionHasErrors('current_landlord_name');
    }

    public function test_spouse_fields_only_apply_when_marital_status_implies_a_spouse(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['spouse_name']),
        ]);
        $application = $this->application();

        $single = $this->post(route('rental-applications.public.submit', $application->token),
            $this->completePayload(['marital_status' => 'Single']));
        $single->assertSessionHasNoErrors();

        $applicationB = $this->application();
        $married = $this->post(route('rental-applications.public.submit', $applicationB->token),
            $this->completePayload(['marital_status' => 'Married']));
        $married->assertSessionHasErrors('spouse_name');
    }

    // ── No locked set — Johan's ruling, twice confirmed ───────────────

    public function test_an_agency_can_untick_id_number_and_a_signature_with_no_exception(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => ['full_name', 'current_residential_address', 'monthly_salary', 'rental_term_months', 'contact_method'],
        ]);
        $application = $this->application();
        $payload = $this->completePayload();
        unset($payload['id_number'], $payload['declaration_signature'], $payload['tpn_consent_signature']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
        $this->assertNull($application->fresh()->id_number);
    }

    public function test_an_agency_can_untick_everything_leaving_only_a_documented_choice(): void
    {
        RentalApplicationQualifyingSetting::create(['agency_id' => $this->agency->id, 'required_field_keys' => []]);
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), []);

        $response->assertSessionHasNoErrors();
        $this->assertSame('returned', $application->fresh()->status);
    }

    // ── Signature well-formedness — unconditional, not a settings question ──

    public function test_a_malformed_signature_is_rejected_not_silently_dropped(): void
    {
        $application = $this->application();
        $payload = $this->completePayload(['declaration_signature' => 'not-a-real-signature']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors('declaration_signature');
        $this->assertSame('sent', $application->fresh()->status);
    }

    public function test_a_blank_canvas_signature_is_rejected(): void
    {
        // A real, decodable, fully-transparent 1x1 PNG — the "technically
        // valid PNG but nobody drew on it" case a direct POST could send
        // to bypass the browser's own ink-presence check entirely.
        $blankPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgAAIAAAUAAen63NgAAAAASUVORK5CYII=';
        $application = $this->application();
        $payload = $this->completePayload(['declaration_signature' => $blankPng]);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasErrors('declaration_signature');
    }

    public function test_an_untied_optional_signature_left_blank_is_accepted(): void
    {
        RentalApplicationQualifyingSetting::create([
            'agency_id' => $this->agency->id,
            'required_field_keys' => ['full_name', 'id_number', 'contact_method', 'current_residential_address', 'monthly_salary', 'rental_term_months', 'tpn_consent_signature'],
        ]);
        $application = $this->application();
        $payload = $this->completePayload();
        unset($payload['declaration_signature']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $payload);

        $response->assertSessionHasNoErrors();
    }

    // ── Not retroactive ────────────────────────────────────────────────

    public function test_an_existing_partial_application_from_before_this_build_still_renders(): void
    {
        $application = $this->application([
            'status' => 'in_progress',
            'full_name' => 'Old Partial Applicant',
            // every other field left null, exactly as a pre-existing
            // partial record would be
        ]);

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
    }
}

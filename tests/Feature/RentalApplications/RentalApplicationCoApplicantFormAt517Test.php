<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-517 (second commit) - the Co-applicant block asks what the Main applicant is asked, under the Main applicant's own
 * settings, and the Co-applicant signs the SAME form: their own declaration and credit-check consent, per generation.
 * The application cannot be submitted until both have signed while a Co-applicant is captured.
 * Spec: .ai/specs/rental-applications.md ("Co-applicant (AT-517)").
 */
final class RentalApplicationCoApplicantFormAt517Test extends TestCase
{
    use RefreshDatabase;

    private const SIG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
    private const SIG2 = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAD0lEQVQImWNgYGD4z4AMAA4EAQAvGW0PAAAAAElFTkSuQmCC';

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Test Rentals', 'slug' => 'tr-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['identity_gate_enabled' => false]);
    }

    private function application(array $over = []): RentalApplication
    {
        return RentalApplication::create($over + [
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
    }

    /** A complete Co-applicant block: every default-compulsory Main applicant field answered for them too. */
    private function coBlock(array $over = []): array
    {
        return $over + [
            'full_name' => 'Zinzi Dlamini', 'id_number' => '8501015800083', 'email' => 'zinzi@example.co.za', 'cell' => '0821234567',
            'address' => '2 Beach Road, Ramsgate', 'monthly_salary' => '18000',
        ];
    }

    private function submitForm(RentalApplication $application, array $fields = []): \Illuminate\Testing\TestResponse
    {
        return $this->from('/form')->post(route('rental-applications.public.submit', $application->token), array_merge([
            'full_name' => 'Sipho Ndlovu', 'id_number' => '8001015800083', 'email' => 'sipho@example.co.za',
            'current_residential_address' => '1 Example Road, Ramsgate', 'monthly_salary' => 20000, 'rental_term_months' => 12,
            'declaration_signature' => self::SIG, 'tpn_consent_signature' => self::SIG,
        ], $fields));
    }

    private function sigCount(RentalApplication $application, ?string $kind = null, ?int $generation = null): int
    {
        return RentalApplicationSignature::where('rental_application_id', $application->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($generation, fn ($q) => $q->where('generation', $generation))
            ->count();
    }

    // ═══ 2. The Co-applicant signs the same form ═══

    public function test_an_application_with_no_co_applicant_is_submitted_exactly_as_before(): void
    {
        $application = $this->application();

        $this->submitForm($application)->assertRedirect();

        $this->assertSame('returned', $application->fresh()->status);
        $this->assertSame(2, $this->sigCount($application));
        $this->assertSame(0, $this->sigCount($application, 'co_declaration') + $this->sigCount($application, 'co_tpn_consent'));
    }

    public function test_a_captured_co_applicant_stops_the_submission_until_they_have_signed_both(): void
    {
        $application = $this->application();

        $this->submitForm($application, ['co_applicant' => $this->coBlock()])
            ->assertSessionHasErrors(['co_declaration_signature', 'co_tpn_consent_signature']);

        $application->refresh();
        $this->assertSame('sent', $application->status, 'nothing was submitted');
        $this->assertNull($application->co_applicant, 'and nothing was saved');
        $this->assertSame(0, $this->sigCount($application));

        // One of the two is still not enough.
        $this->submitForm($application, ['co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG])
            ->assertSessionHasErrors(['co_tpn_consent_signature'])
            ->assertSessionDoesntHaveErrors(['co_declaration_signature']);
        $this->assertSame('sent', $application->fresh()->status);
    }

    public function test_a_co_applicant_chosen_by_the_agent_also_needs_to_sign_even_with_an_empty_block(): void
    {
        $co = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Zinzi', 'last_name' => 'Dlamini', 'email' => 'zinzi@example.co.za']);
        $application = $this->application(['co_applicant_contact_id' => $co->id]);

        $this->submitForm($application)->assertSessionHasErrors(['co_declaration_signature', 'co_tpn_consent_signature']);
        $this->assertSame('sent', $application->fresh()->status);
    }

    public function test_while_a_co_applicant_is_captured_the_main_applicants_signatures_are_compulsory_too(): void
    {
        // The agency made neither of the Main applicant's signatures compulsory ...
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'required_field_keys' => ['full_name', 'id_number', 'contact_method', 'current_residential_address', 'monthly_salary', 'rental_term_months'],
        ]);
        $application = $this->application();
        $this->submitForm($application, ['declaration_signature' => null, 'tpn_consent_signature' => null])->assertRedirect();
        $this->assertSame('returned', $application->fresh()->status, 'alone, they may submit unsigned');

        // ... but "both have signed" is the rule once a Co-applicant is on the form.
        $second = $this->application(['contact_id' => Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'B', 'last_name' => 'C', 'email' => 'b@example.co.za'])->id]);
        $this->submitForm($second, ['declaration_signature' => null, 'tpn_consent_signature' => null, 'co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG])
            ->assertSessionHasErrors(['declaration_signature', 'tpn_consent_signature']);
        $this->assertSame('sent', $second->fresh()->status);
    }

    public function test_both_signing_seals_four_signatures_and_a_resubmit_adds_a_new_generation_without_touching_the_old_ones(): void
    {
        $application = $this->application();
        $this->submitForm($application, [
            'co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertSame(4, $this->sigCount($application, null, 1));
        foreach (['declaration', 'tpn_consent', 'co_declaration', 'co_tpn_consent'] as $kind) {
            $this->assertSame(1, $this->sigCount($application, $kind, 1), $kind);
        }
        $this->assertTrue($application->isFullySigned());
        $this->assertNotNull($application->coDeclarationSignature());
        $this->assertNotNull($application->coTpnConsentSignature());
        $this->assertSame('Zinzi Dlamini', RentalApplicationGeneration::where('rental_application_id', $application->id)->firstOrFail()->snapshot_json['co_applicant']['full_name'], 'the Co-applicant answers are in the sealed generation');
        $this->assertTrue(RentalApplicationGeneration::verifyChain($application->id)['ok'], 'the sealed chain still verifies');

        $first = RentalApplicationSignature::where('rental_application_id', $application->id)->where('kind', 'co_declaration')->where('generation', 1)->firstOrFail();

        // Reopen and resubmit: earlier signatures do not carry over - both people sign again; the old rows stay as they were.
        $this->actingAs($this->agent)->post(route('corex.rental-applications.review.reopen', $application), ['note' => 'Please check the ID numbers.'])->assertOk();
        $this->submitForm($application->fresh(), ['co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG])
            ->assertSessionHasErrors(['co_tpn_consent_signature']);
        $this->submitForm($application->fresh(), [
            'declaration_signature' => self::SIG2, 'tpn_consent_signature' => self::SIG2,
            'co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG2, 'co_tpn_consent_signature' => self::SIG2,
        ])->assertRedirect();

        $application->refresh();
        $this->assertSame(2, $application->current_generation);
        $this->assertSame(4, $this->sigCount($application, null, 2));
        $this->assertSame(4, $this->sigCount($application, null, 1), 'generation 1 is untouched');
        $this->assertSame($first->signature_path, RentalApplicationSignature::find($first->id)->signature_path);
        $this->assertSame(2, $application->currentSignatures()->whereIn('kind', ['co_declaration', 'co_tpn_consent'])->count());
        $this->assertTrue(RentalApplicationGeneration::verifyChain($application->id)['ok']);
    }

    public function test_a_malformed_co_signature_is_refused_like_a_malformed_main_signature(): void
    {
        $application = $this->application();

        $this->submitForm($application, ['co_applicant' => $this->coBlock(), 'co_declaration_signature' => 'data:image/png;base64,AAAA', 'co_tpn_consent_signature' => self::SIG])
            ->assertSessionHasErrors(['co_declaration_signature']);
        $this->assertSame(0, $this->sigCount($application));
    }

    // ═══ 1. The Co-applicant's fields follow the Main applicant's definitions and settings ═══

    public function test_the_widened_block_keeps_every_field_the_main_applicant_has_and_cleans_each_by_its_kind(): void
    {
        $application = $this->application();

        $this->submitForm($application, ['co_applicant' => $this->coBlock([
            'citizenship' => 'South African', 'work_number' => '0315550000',
            'emergency_contact_name' => 'Thabo', 'emergency_contact_cell' => '0825550000',
            'current_living_situation' => 'renting', 'current_landlord_name' => 'Mr Smith', 'current_rental_amount' => 'R 7 500,50',
            'current_rental_due_day' => '1', 'current_rental_from' => '2024-02-01', 'current_rental_to' => '2026-12-31',
            'employment_type' => 'permanently_employed', 'employer_name' => 'Acme', 'employer_address' => '5 Main Road',
            'previous_landlord_name' => 'Mrs Jones', 'junk_key' => 'dropped',
        ]), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG])->assertRedirect();

        $co = $application->fresh()->co_applicant;
        $this->assertSame('South African', $co['citizenship']);
        $this->assertSame('7500.50', $co['current_rental_amount'], 'money is cleaned like the Main applicant\'s');
        $this->assertSame('2024-02-01', $co['current_rental_from']);
        $this->assertSame('permanently_employed', $co['employment_type']);
        $this->assertSame('5 Main Road', $co['employer_address']);
        $this->assertArrayNotHasKey('junk_key', $co);
    }

    public function test_landlord_answers_are_cleared_unless_renting_and_the_end_date_is_cleared_while_still_living_there(): void
    {
        $cleaned = RentalApplication::normaliseBlocks(['co_applicant' => [
            'full_name' => 'Zinzi', 'current_living_situation' => 'owns_or_selling', 'current_landlord_name' => 'Mr Smith', 'current_rental_amount' => '5000',
        ]])['co_applicant'];
        $this->assertArrayNotHasKey('current_landlord_name', $cleaned);
        $this->assertArrayNotHasKey('current_rental_amount', $cleaned);

        $renting = RentalApplication::normaliseBlocks(['co_applicant' => [
            'current_living_situation' => 'renting', 'current_rental_still_living' => '1', 'current_rental_to' => '2026-12-31', 'current_rental_from' => 'not a date',
        ]])['co_applicant'];
        $this->assertArrayNotHasKey('current_rental_to', $renting);
        $this->assertArrayNotHasKey('current_rental_from', $renting, 'an invalid date is dropped, never stored');
        $this->assertSame('1', $renting['current_rental_still_living']);
    }

    public function test_a_field_the_agency_made_compulsory_for_the_main_applicant_is_compulsory_for_the_co_applicant_too(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'required_field_keys' => array_merge(RentalApplicationQualifyingSetting::DEFAULT_REQUIRED_FIELD_KEYS, ['citizenship', 'employer_name', 'current_landlord_name']),
        ]);
        $application = $this->application();
        $sigs = ['co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG];

        // Citizenship is compulsory for everyone; the employer and landlord fields only when they apply to THEIR answers.
        $this->submitForm($application, ['citizenship' => 'South African', 'co_applicant' => $this->coBlock()] + $sigs)
            ->assertSessionHasErrors(['co_applicant.citizenship'])
            ->assertSessionDoesntHaveErrors(['co_applicant.employer_name', 'co_applicant.current_landlord_name']);

        $this->submitForm($application, ['citizenship' => 'South African', 'co_applicant' => $this->coBlock(['citizenship' => 'South African', 'employment_type' => 'permanently_employed', 'current_living_situation' => 'renting'])] + $sigs)
            ->assertSessionHasErrors(['co_applicant.employer_name', 'co_applicant.current_landlord_name'])
            ->assertSessionDoesntHaveErrors(['co_applicant.citizenship']);

        $this->submitForm($application, ['citizenship' => 'South African', 'co_applicant' => $this->coBlock([
            'citizenship' => 'South African', 'employment_type' => 'permanently_employed', 'employer_name' => 'Acme',
            'current_living_situation' => 'renting', 'current_landlord_name' => 'Mr Smith',
        ])] + $sigs)->assertRedirect();
        $this->assertSame('returned', $application->fresh()->status);
    }

    public function test_a_field_the_agency_hid_for_the_main_applicant_is_not_asked_of_the_co_applicant_and_a_renamed_one_keeps_the_agencys_wording(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], [
            'hidden_field_keys' => ['citizenship'], 'required_field_keys' => ['citizenship'],
            'field_label_overrides' => ['work_number' => 'Office phone'],
        ]);
        $application = $this->application();

        $html = $this->get(route('rental-applications.public.show', $application->token))->assertOk()->getContent();
        $this->assertStringNotContainsString('name="co_applicant[citizenship]"', $html);
        $this->assertStringContainsString('name="co_applicant[work_number]"', $html);
        $this->assertStringContainsString('Office phone', $html);
        $this->assertStringContainsString('name="co_applicant[current_living_situation]"', $html);
        $this->assertStringContainsString('name="co_applicant[employer_address]"', $html);
        $this->assertStringContainsString('name="co_applicant[emergency_contact_name]"', $html);
        $this->assertStringContainsString('name="co_applicant[previous_landlord_name]"', $html);

        // Hidden AND compulsory for the Main applicant: never an unsatisfiable rule for the Co-applicant either.
        $this->submitForm($application, ['co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG])->assertRedirect();
        $this->assertSame('returned', $application->fresh()->status);
    }

    // ═══ The form and the printed application show both signature blocks ═══

    public function test_the_applicant_form_carries_the_co_applicants_signature_pads_and_hidden_inputs(): void
    {
        $application = $this->application();

        $html = $this->get(route('rental-applications.public.show', $application->token))->assertOk()->getContent();

        $this->assertStringContainsString('data-qa="co-applicant-signatures"', $html);
        $this->assertStringContainsString('sig-canvas-co_declaration_signature', $html);
        $this->assertStringContainsString('sig-canvas-co_tpn_consent_signature', $html);
        $this->assertStringContainsString('name="co_declaration_signature"', $html);
        $this->assertStringContainsString('name="co_tpn_consent_signature"', $html);
        $this->assertStringContainsString('x-ref="co_declaration_signature_input"', $html);
    }

    public function test_the_printed_application_shows_both_signature_blocks_and_the_co_applicants_answers(): void
    {
        $application = $this->application();
        $this->submitForm($application, ['co_applicant' => $this->coBlock([
            'citizenship' => 'South African', 'current_living_situation' => 'renting', 'current_rental_amount' => '7500', 'employment_type' => 'permanently_employed',
        ]), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG])->assertRedirect();

        $html = view('corex.rental-applications.pdf', [
            'application' => $application->fresh(), 'agency' => $this->agency, 'branch' => $this->branch,
        ])->render();

        $this->assertStringContainsString('Co-applicant Signature', $html);
        $this->assertStringContainsString('Co-applicant Signature —', $html);
        $this->assertSame(4, substr_count($html, '/signatures/'), 'all four signature images are on the page');
        $this->assertStringContainsString('Citizenship: <span class="corex-field-value">South African', $html);
        $this->assertStringContainsString('Currently renting', $html);
        $this->assertStringContainsString('Permanently employed', $html);
        $this->assertStringContainsString('R 7 500.00', $html);
    }

    public function test_the_agent_screens_list_the_co_applicants_signatures(): void
    {
        $application = $this->application();
        $this->submitForm($application, ['co_applicant' => $this->coBlock(), 'co_declaration_signature' => self::SIG, 'co_tpn_consent_signature' => self::SIG])->assertRedirect();

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $application))->assertOk()->getContent();
        $this->assertStringContainsString('data-qa="co-declaration-status"', $html);
        $this->assertStringContainsString('Co-applicant declaration: ✓ Signed', $html);
        $this->assertStringContainsString('Co-applicant TPN consent: ✓ Signed', $html);
    }
}

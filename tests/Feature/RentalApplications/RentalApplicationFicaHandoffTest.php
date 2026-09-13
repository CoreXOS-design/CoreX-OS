<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FICA-mandatory, AT-392 round 3, 2026-09-13 — Johan, a legal position:
 * "technically we not allowed to work with anyone if did not fica...
 * submit and complete fica forces them to complete fica whilst we receive
 * the application back. agent can then push them to complete fica."
 *
 * Reuses CoreX's existing FICA module (FicaSubmission, the same table and
 * staff review workflow compliance/fica already uses) — no second FICA
 * system. These tests prove: the application is ALWAYS received
 * regardless of FICA; the hand-off finds-or-creates the SAME FicaSubmission
 * a repeat contact already has; the authoriser gate reads the SAME
 * Contact::ficaStatus() the badge elsewhere already shows; and an agent's
 * own walk-in verification (agentApprove(), simulated here by directly
 * setting status) is read correctly too.
 */
final class RentalApplicationFicaHandoffTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
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

    private function submitPayload(): array
    {
        return [
            'full_name' => 'Sipho Ndlovu',
            'id_number' => '8501015800083',
            'declaration_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'tpn_consent_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ];
    }

    public function test_submitting_the_application_is_received_regardless_and_redirects_into_the_existing_fica_form(): void
    {
        $application = $this->application();

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $application->refresh();
        $this->assertSame('returned', $application->status, 'the application must be received/submitted regardless of FICA');
        $this->assertNotNull($application->submitted_at);

        $ficaSubmission = FicaSubmission::where('contact_id', $this->contact->id)->first();
        $this->assertNotNull($ficaSubmission, 'a FicaSubmission must be created for the hand-off');
        $this->assertSame('draft', $ficaSubmission->status);
        $this->assertSame($this->agency->id, $ficaSubmission->agency_id);
        $this->assertSame($this->agent->id, $ficaSubmission->requested_by);

        $response->assertRedirect();
        $this->assertStringContainsString('/fica/', $response->headers->get('Location') ?? $response->getTargetUrl() ?? '');
        $this->assertStringContainsString('return_context=rental_application', urldecode($response->headers->get('Location') ?? $response->getTargetUrl() ?? ''));
    }

    public function test_abandoning_fica_leaves_the_application_submitted_with_fica_flagged_outstanding(): void
    {
        $application = $this->application();
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        // Applicant never touches the FICA form at all — simulates closing the tab.
        $application->refresh();
        $this->assertSame('returned', $application->status, 'the application must still be submitted');
        $this->assertTrue($application->ficaOutstanding());

        $show = $this->get(route('rental-applications.public.show', $application->token));
        $show->assertOk();
        $show->assertSee('One more step needed');
    }

    /**
     * ficaOutstanding() alone can't tell "never started" from "submitted,
     * awaiting our own review" — an applicant who already did their part
     * must never be told to "contact your agent" as if they hadn't.
     */
    public function test_a_submitted_but_not_yet_approved_fica_shows_awaiting_review_not_one_more_step(): void
    {
        FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'submitted',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        $application = $this->application();
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->assertTrue($application->refresh()->ficaOutstanding());
        $this->assertFalse($application->ficaAwaitingApplicantAction());

        $show = $this->get(route('rental-applications.public.show', $application->token));
        $show->assertOk();
        $show->assertSee('Verification in progress');
        $show->assertDontSee('One more step needed');
    }

    public function test_a_contact_with_an_already_approved_unexpired_fica_is_reused_not_duplicated(): void
    {
        $existing = FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'approved',
            'verified_by' => $this->agent->id, 'verified_at' => now()->subMonths(2),
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        $application = $this->application();

        $submitResponse = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->assertSame(1, FicaSubmission::where('contact_id', $this->contact->id)->count(), 'must reuse, not duplicate');
        $this->assertFalse($application->refresh()->ficaOutstanding(), 'an already-complete FICA must not read as outstanding');

        // Confirm the reuse path actually RENDERS rather than erroring —
        // Johan/conductor's refinement #5. Follows the REAL redirect chain
        // submit() produced (return_url/return_context included), not a
        // bare fica.form hit with no context — that's the actual path a
        // repeat applicant travels.
        $ficaShow = $this->get($submitResponse->headers->get('Location'));
        $ficaShow->assertRedirect(); // form() bounces an already-submitted/approved status straight to confirmation.
        $confirmation = $this->get($ficaShow->headers->get('Location'));
        $confirmation->assertOk();
        $confirmation->assertSee('already complete');
    }

    public function test_id_number_backfills_the_contact_only_when_empty(): void
    {
        $this->assertNull($this->contact->id_number);
        $application = $this->application();

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->assertSame('8501015800083', $this->contact->fresh()->id_number);
        $this->assertSame('rental_application', $this->contact->fresh()->id_number_source);
    }

    public function test_id_number_never_overwrites_an_existing_contact_value(): void
    {
        $this->contact->update(['id_number' => '9001015800083', 'id_number_source' => 'property_inline_create']);
        $application = $this->application();

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->assertSame('9001015800083', $this->contact->fresh()->id_number, 'an existing id_number must never be overwritten');
        $this->assertSame('property_inline_create', $this->contact->fresh()->id_number_source);
    }

    public function test_authoriser_gate_blocks_submission_when_fica_outstanding_and_setting_requires_it(): void
    {
        $application = $this->application(['status' => 'under_assessment', 'submitted_at' => now()->subDay(), 'current_generation' => 1]);

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.review.submit-for-approval', $application),
            ['expected_generation' => 1],
        );

        $response->assertStatus(422);
        $response->assertJsonPath('reason', 'fica_outstanding');
        $this->assertNull($application->fresh()->submitted_for_approval_at);
    }

    public function test_authoriser_gate_allows_submission_when_the_agency_setting_is_off(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['require_fica_before_authorisation' => false],
        );
        $application = $this->application(['status' => 'under_assessment', 'submitted_at' => now()->subDay(), 'current_generation' => 1]);

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.review.submit-for-approval', $application),
            ['expected_generation' => 1],
        );

        $response->assertOk();
        $this->assertNotNull($application->fresh()->submitted_for_approval_at);
    }

    /**
     * Refinement #3 — the agent must be able to satisfy FICA from the
     * agency side for a walk-in. agentApprove() itself is exhaustively
     * tested elsewhere (FICA's own test suite); this proves the RENTAL
     * gate correctly reads that state once reached — the actual question
     * Johan asked about THIS build, not a re-test of FICA's own workflow.
     */
    public function test_authoriser_gate_allows_submission_once_fica_is_fully_approved_via_the_staff_path(): void
    {
        FicaSubmission::create([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'approved',
            'verified_by' => $this->agent->id, 'verified_at' => now(),
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ]);
        $application = $this->application(['status' => 'under_assessment', 'submitted_at' => now()->subDay(), 'current_generation' => 1]);

        $response = $this->actingAs($this->agent)->postJson(
            route('corex.rental-applications.review.submit-for-approval', $application),
            ['expected_generation' => 1],
        );

        $response->assertOk();
    }

    public function test_require_fica_before_authorisation_setting_has_a_sensible_default_and_is_agency_configurable(): void
    {
        $this->assertTrue(RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor($this->agency->id));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['require_fica_before_authorisation' => false],
        );

        $this->assertFalse(RentalApplicationQualifyingSetting::requireFicaBeforeAuthorisationFor($this->agency->id));
    }
}

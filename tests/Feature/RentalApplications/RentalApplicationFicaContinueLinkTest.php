<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\FicaSubmission;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Return leg, AT-392 round 5, 2026-09-13 — Johan, walking the applicant's
 * own return page live: it told the applicant FICA was still needed and
 * then gave them nothing to click — "the one thing that page should
 * obviously do... it does not do." With Johan's separate ruling that the
 * FICA form itself saves no partial progress ("start and complete... small
 * enough to complete in 1 go"), this button is not a nicety — it is the
 * entire recovery path for anyone who didn't finish in one sitting.
 *
 * Two states, handled honestly and differently (conductor's own scope):
 * - FICA not started / rejected / needs corrections → the button, wording
 *   that reads as finishing the SAME step submit() already told them about.
 * - FICA submitted, awaiting the agency's own review → NO button, the
 *   existing "no action needed from you" wording, never told to redo work
 *   they've already done.
 */
final class RentalApplicationFicaContinueLinkTest extends TestCase
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
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho@example.co.za',
        ]);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function submittedApplication(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'returned',
            'full_name' => 'Sipho Ndlovu', 'id_number' => '8501015800083',
            'submitted_at' => now()->subDay(), 'current_generation' => 1,
            // Submission identity gate (cc2/cc4, 2026-09-15) — the gate now
            // fires ON SUBMISSION, so a genuinely submitted application has
            // already passed it by the time it reaches this already-
            // submitted page. Without this, show() redirects to the
            // identity-gate screen before ever reaching the FICA-continue
            // content this test exists to check — a real, expected
            // interaction between the two features, not a workaround.
            'identity_verified_at' => now()->subDay(),
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function ficaSubmission(array $attrs = []): FicaSubmission
    {
        return FicaSubmission::create(array_merge([
            'contact_id' => $this->contact->id, 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'requested_by' => $this->agent->id, 'status' => 'draft',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    public function test_never_started_fica_shows_a_working_link_to_the_applicants_own_fica_form(): void
    {
        $application = $this->submittedApplication();
        $fica = $this->ficaSubmission(['status' => 'draft']);

        $response = $this->withSession(['rental_application_return_gate_passed:' . $application->token => true])
            ->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee('Complete FICA Verification');
        // assertSee's own default (true) HTML-escapes the expected value
        // before comparing — matching Blade's {{ }} escaping the & in this
        // URL's query string to &amp; in the rendered page. Passing false
        // here (as an earlier draft of this test did) compares an
        // unescaped string against escaped output and always fails.
        $response->assertSee(route('fica.form', ['token' => $fica->token, 'return_url' => route('rental-applications.public.show', $application->token), 'return_context' => 'rental_application']));
    }

    public function test_rejected_fica_still_shows_the_link_back(): void
    {
        $application = $this->submittedApplication();
        $this->ficaSubmission(['status' => 'rejected']);

        $response = $this->withSession(['rental_application_return_gate_passed:' . $application->token => true])
            ->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee('Complete FICA Verification');
    }

    public function test_fica_submitted_and_awaiting_review_shows_no_button_and_the_existing_wording(): void
    {
        $application = $this->submittedApplication();
        $this->ficaSubmission(['status' => 'submitted']);

        $response = $this->withSession(['rental_application_return_gate_passed:' . $application->token => true])
            ->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('Complete FICA Verification');
        $response->assertSee('Verification in progress');
        $response->assertSee('No action is needed from you right now');
    }

    public function test_an_expired_fica_token_falls_back_to_contact_your_agent_rather_than_a_dead_link(): void
    {
        $application = $this->submittedApplication();
        $this->ficaSubmission(['status' => 'draft', 'token_expires_at' => now()->subDay()]);

        $response = $this->withSession(['rental_application_return_gate_passed:' . $application->token => true])
            ->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('Complete FICA Verification');
        $response->assertSee('Please contact your agent to finish this step');
    }

    public function test_no_fica_submission_at_all_falls_back_to_contact_your_agent(): void
    {
        // The rare edge case: submit()'s FICA hand-off itself failed (see
        // the try/catch around findOrCreateFicaSubmission()) — no
        // FicaSubmission row exists for this contact at all.
        $application = $this->submittedApplication();

        $response = $this->withSession(['rental_application_return_gate_passed:' . $application->token => true])
            ->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee('Complete FICA Verification');
        $response->assertSee('Please contact your agent to finish this step');
    }
}

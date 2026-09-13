<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\OtpMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Return gate, AT-392 round 4, 2026-09-13 — Johan, verbatim: "played
 * around that initial open is not gated but if the applicant submits we
 * should have the id number which we can update the contact record with,
 * so after initial submission we can gate on ID."
 *
 * First open: never gated (an unfinished form holds nothing worth
 * protecting). Every return after the applicant has submitted at least
 * once: gated. Default method is the applicant's own ID number, a speed
 * bump not authentication; email_otp is the agency-configurable stronger
 * option, reusing CoreX's existing OtpService (not a second one-time-code
 * mechanism). Failed attempts are rate-limited and never leak whether a
 * guess was close; a locked-out applicant always sees a way forward that
 * names the agent, never a bare refusal.
 */
final class RentalApplicationReturnGateTest extends TestCase
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
        $this->agent = User::factory()->create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin',
            'name' => 'Test Agent', 'email' => 'agent@example.co.za',
        ]);
    }

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    private function submittedApplication(array $attrs = []): RentalApplication
    {
        return $this->application(array_merge([
            'status' => 'returned', 'submitted_at' => now()->subDay(),
            'full_name' => 'Sipho Ndlovu', 'id_number' => '8501015800083', 'current_generation' => 1,
        ], $attrs));
    }

    public function test_first_open_before_any_submission_is_never_gated(): void
    {
        $application = $this->application();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertDontSee("Verify it's you");
    }

    public function test_a_fresh_session_after_submission_hits_the_gate_not_the_sensitive_content(): void
    {
        $application = $this->submittedApplication();

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee("Verify it's you", false);
        $response->assertDontSee('8501015800083');
        $response->assertDontSee('Application already received');
    }

    public function test_the_same_session_that_just_submitted_is_never_gated(): void
    {
        $application = $this->application();

        $this->post(route('rental-applications.public.submit', $application->token), [
            'full_name' => 'Sipho Ndlovu', 'id_number' => '8501015800083',
            'declaration_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            'tpn_consent_signature' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        ]);

        // Following the redirect chain lands eventually back on the
        // rental application's own show() — must not re-ask the gate.
        $response = $this->get(route('rental-applications.public.show', $application->token));
        $response->assertOk();
        $response->assertDontSee("Verify it's you");
    }

    public function test_the_correct_id_number_passes_the_gate_and_unlocks_the_session(): void
    {
        $application = $this->submittedApplication();

        $this->postJson(route('rental-applications.public.verify-gate', $application->token), [
            'id_number' => '8501015800083',
        ])->assertRedirect(route('rental-applications.public.show', $application->token));

        $response = $this->get(route('rental-applications.public.show', $application->token));
        $response->assertOk();
        $response->assertDontSee("Verify it's you");
    }

    /**
     * Conductor's refinement: "normalise the ID before comparing. Strip
     * spaces, dashes and non-digits on both sides. A correct ID typed with
     * spaces must pass."
     */
    public function test_the_id_number_is_normalised_before_comparing_spaces_and_dashes_are_ignored(): void
    {
        $application = $this->submittedApplication();

        $this->post(route('rental-applications.public.verify-gate', $application->token), [
            'id_number' => '850 101-5800 083',
        ])->assertRedirect(route('rental-applications.public.show', $application->token));

        $response = $this->get(route('rental-applications.public.show', $application->token));
        $response->assertDontSee("Verify it's you");
    }

    public function test_the_wrong_id_number_fails_with_a_generic_message_never_an_oracle(): void
    {
        $application = $this->submittedApplication();

        $this->from(route('rental-applications.public.show', $application->token))
            ->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => '9999999999999'])
            ->assertSessionHasErrors('gate');

        $response = $this->get(route('rental-applications.public.show', $application->token));
        $response->assertSee("didn't match");
        $response->assertDontSee('8501015800083');
    }

    public function test_failed_attempts_are_rate_limited_and_the_locked_out_screen_names_the_agent(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['return_gate_attempt_max' => 2, 'return_gate_attempt_window_minutes' => 15],
        );
        $application = $this->submittedApplication();

        $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => 'wrong'])->assertSessionHasErrors('gate');
        $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => 'wrong'])->assertSessionHasErrors('gate');

        $lockedOut = $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => 'wrong']);
        $lockedOut->assertStatus(429);
        $lockedOut->assertSee("We can't verify you right now", false);
        $lockedOut->assertSee('Test Agent');
        $lockedOut->assertSee('agent@example.co.za');
        $lockedOut->assertDontSee('Too many attempts');

        // Even correct answers are refused while locked out — the whole
        // point of the cap.
        $stillLocked = $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => '8501015800083']);
        $stillLocked->assertStatus(429);
    }

    public function test_email_otp_method_sends_a_code_and_the_correct_code_passes(): void
    {
        Mail::fake();
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['return_gate_method' => 'email_otp'],
        );
        $application = $this->submittedApplication(['email' => 'applicant@example.co.za']);

        $this->get(route('rental-applications.public.show', $application->token))->assertOk();

        Mail::assertSent(OtpMail::class);
        $capturedCode = null;
        $capturedSubject = null;
        Mail::assertSent(OtpMail::class, function ($mail) use (&$capturedCode, &$capturedSubject) {
            $capturedCode = $mail->code;
            $capturedSubject = $mail->envelope()->subject;
            return true;
        });
        $this->assertNotNull($capturedCode);
        $this->assertStringNotContainsString($capturedCode, (string) $capturedSubject, 'the subject line must never carry the raw code — cc3\'s flag on OtpMail\'s default');

        $this->post(route('rental-applications.public.verify-gate', $application->token), ['otp_code' => $capturedCode])
            ->assertRedirect(route('rental-applications.public.show', $application->token));

        $this->get(route('rental-applications.public.show', $application->token))->assertDontSee("Verify it's you");
    }

    public function test_email_otp_does_not_resend_on_every_reload_only_once_per_session(): void
    {
        Mail::fake();
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['return_gate_method' => 'email_otp'],
        );
        $application = $this->submittedApplication(['email' => 'applicant@example.co.za']);

        $this->get(route('rental-applications.public.show', $application->token))->assertOk();
        $this->get(route('rental-applications.public.show', $application->token))->assertOk();

        Mail::assertSentCount(1);
    }

    public function test_pdf_route_is_also_gated_not_just_show(): void
    {
        $application = $this->submittedApplication();

        $this->get(route('rental-applications.public.pdf', $application->token))
            ->assertRedirect(route('rental-applications.public.show', $application->token));
    }

    /**
     * The single most sensitive route the applicant journey has: a direct
     * link to a real uploaded ID copy or payslip must never bypass the
     * gate just because it skips show() entirely.
     */
    public function test_document_view_route_is_also_gated_not_just_show(): void
    {
        $application = $this->submittedApplication();
        $document = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
            'original_name' => 'payslip.pdf', 'storage_path' => 'rental-applications/x/payslip.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 100,
            'source_type' => 'rental_application', 'source_id' => $application->id,
            'agency_id' => $application->agency_id, 'branch_id' => $application->branch_id,
        ]));

        $this->get(route('rental-applications.public.documents.view', [$application->token, $document->id]))
            ->assertRedirect(route('rental-applications.public.show', $application->token));
    }

    public function test_a_reopened_application_is_gated_too_on_a_fresh_session(): void
    {
        $application = $this->submittedApplication(['status' => 'reopened']);

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertOk();
        $response->assertSee("Verify it's you", false);
    }

    public function test_return_gate_settings_have_sensible_defaults_and_are_agency_configurable(): void
    {
        $this->assertSame('id_number', RentalApplicationQualifyingSetting::returnGateMethodFor($this->agency->id));
        $this->assertSame(5, RentalApplicationQualifyingSetting::returnGateAttemptMaxFor($this->agency->id));
        $this->assertSame(15, RentalApplicationQualifyingSetting::returnGateAttemptWindowMinutesFor($this->agency->id));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['return_gate_method' => 'email_otp', 'return_gate_attempt_max' => 3, 'return_gate_attempt_window_minutes' => 30],
        );

        $this->assertSame('email_otp', RentalApplicationQualifyingSetting::returnGateMethodFor($this->agency->id));
        $this->assertSame(3, RentalApplicationQualifyingSetting::returnGateAttemptMaxFor($this->agency->id));
        $this->assertSame(30, RentalApplicationQualifyingSetting::returnGateAttemptWindowMinutesFor($this->agency->id));
    }
}

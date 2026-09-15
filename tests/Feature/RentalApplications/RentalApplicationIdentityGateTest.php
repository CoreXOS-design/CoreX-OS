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
 * Submission identity gate, 2026-09-13 — Johan walked the applicant link
 * himself in a real browser, signed both pads, pressed "Submit and
 * Complete FICA Verification," and landed straight in FICA with no
 * identity challenge anywhere: "priorities are as the process runs -
 * agent sends application to applicant. so on completion we need the
 * fica gate. ON SUBMISSION THE ID / OTP GATE."
 *
 * Different moment, different purpose, from RentalApplicationReturnGateTest
 * (which gates a LATER visit after first submission and never fires on
 * that first submission itself). This gate fires on a genuine first-ever
 * submission only, before the application is visible/actionable to the
 * agency — the notification, the CRM-sync event, and the FICA hand-off
 * are all deferred until it resolves (releaseSubmissionToAgency()).
 *
 * Channel is chosen per applicant, never a fixed agency setting: email
 * OTP (reusing the same App\Services\Otp\OtpService the Return Gate and
 * DR2 already use) when an email is on file, ID-number match (the Return
 * Gate's own comparator, reused verbatim) when it isn't. Johan's ruling
 * when NEITHER is present: let the application through, flag it
 * identity_gate_unreachable for the AGENT to chase — never block the
 * applicant for a configuration gap that isn't theirs.
 */
final class RentalApplicationIdentityGateTest extends TestCase
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

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    private function submitPayload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Sipho Ndlovu',
            'declaration_signature' => self::SIGNATURE,
            'tpn_consent_signature' => self::SIGNATURE,
        ], $overrides);
    }

    // ── (a) fires on first submission, before the agency has it ──────────

    public function test_first_submission_with_email_is_gated_by_otp_before_reaching_fica(): void
    {
        Mail::fake();
        $application = $this->application(['email' => 'applicant@example.co.za']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $response->assertRedirect(route('rental-applications.public.identity-gate', $application->token));
        Mail::assertNothingSent();
        // Data is captured regardless — never lost, never re-asked.
        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertNotNull($application->submitted_at);
        $this->assertNull($application->identity_verified_at);
    }

    public function test_agent_is_not_notified_and_fica_is_not_reached_until_the_gate_passes(): void
    {
        Mail::fake();
        $application = $this->application(['email' => 'applicant@example.co.za']);

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        // Johan: "the gate is worthless after the agency already has it" —
        // the agent notification is one of the things that must wait.
        Mail::assertNotSent(\App\Mail\RentalApplicationReturnedMail::class);
        $this->assertDatabaseMissing('fica_submissions', ['contact_id' => $this->contact->id]);
    }

    public function test_passing_the_gate_releases_the_submission_notifies_the_agent_and_reaches_fica(): void
    {
        Mail::fake();
        $application = $this->application(['email' => 'applicant@example.co.za', 'id_number' => '8501015800083']);

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());
        $application->refresh();

        $this->get(route('rental-applications.public.identity-gate', $application->token))->assertOk();
        $capturedCode = null;
        Mail::assertSent(OtpMail::class, function ($mail) use (&$capturedCode) {
            $capturedCode = $mail->code;
            return true;
        });

        $response = $this->post(route('rental-applications.public.verify-identity-gate', $application->token), [
            'otp_code' => $capturedCode,
        ]);

        $application->refresh();
        $this->assertNotNull($application->identity_verified_at);
        Mail::assertSent(\App\Mail\RentalApplicationReturnedMail::class);
        $this->assertDatabaseHas('fica_submissions', ['contact_id' => $this->contact->id]);
        $response->assertRedirect();
        $this->assertStringContainsString('fica', (string) $response->headers->get('Location'));
    }

    // ── (b) channel chosen per applicant — email when present, ID-number fallback otherwise ──

    /**
     * recipientEmail() falls back to the CONTACT's own email when the
     * application's own email field is blank — correct behaviour (any
     * email on file is a real channel), but it means these fallback tests
     * must clear the contact's email too, not just the application's.
     */
    public function test_no_email_on_file_falls_back_to_id_number(): void
    {
        $this->contact->update(['email' => null]);
        $application = $this->application(['email' => null, 'id_number' => '8501015800083']);

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['id_number' => '8501015800083']));

        $gate = $this->get(route('rental-applications.public.identity-gate', $application->token));
        $gate->assertOk();
        $gate->assertSee('Enter your ID number to continue', false);
    }

    public function test_correct_id_number_passes_the_fallback_gate(): void
    {
        $this->contact->update(['email' => null]);
        $application = $this->application(['email' => null, 'id_number' => '8501015800083']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['id_number' => '8501015800083']));

        $response = $this->post(route('rental-applications.public.verify-identity-gate', $application->token), [
            'id_number' => '8501015800083',
        ]);

        $this->assertNotNull($application->refresh()->identity_verified_at);
        $response->assertRedirect();
    }

    public function test_wrong_id_number_fails_with_a_generic_message_never_an_oracle(): void
    {
        $this->contact->update(['email' => null]);
        $application = $this->application(['email' => null, 'id_number' => '8501015800083']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['id_number' => '8501015800083']));

        $this->from(route('rental-applications.public.identity-gate', $application->token))
            ->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => '0000000000000'])
            ->assertSessionHasErrors('gate');

        $this->assertNull($application->refresh()->identity_verified_at);
        $response = $this->get(route('rental-applications.public.identity-gate', $application->token));
        $response->assertSee("didn't match");
        $response->assertDontSee('8501015800083');
    }

    // ── unreachable applicants — let through, flagged for the agent, never blocked ──

    public function test_neither_email_nor_id_number_lets_the_submission_through_flagged_unreachable(): void
    {
        Mail::fake();
        $this->contact->update(['email' => null]);
        $application = $this->application(['email' => null, 'id_number' => null]);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        // Let through — never blocked, never a dead end for the applicant.
        $response->assertRedirect();
        $this->assertStringContainsString('fica', (string) $response->headers->get('Location'));

        $application->refresh();
        $this->assertSame('returned', $application->status);
        $this->assertTrue($application->identity_gate_unreachable);
        $this->assertNull($application->identity_verified_at);
        // Unreachable is let straight through — the agent IS notified,
        // since there is nothing further to wait for.
        Mail::assertSent(\App\Mail\RentalApplicationReturnedMail::class);
    }

    public function test_unreachable_flag_surfaces_a_distinct_badge_on_the_agent_review_screen(): void
    {
        $application = $this->application([
            'email' => null, 'id_number' => null, 'status' => 'returned',
            'submitted_at' => now(), 'identity_gate_unreachable' => true,
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
        $response->assertSee('Identity unreachable', false);
    }

    public function test_awaiting_applicant_badge_shows_while_the_gate_is_pending(): void
    {
        $application = $this->application([
            'email' => 'applicant@example.co.za', 'status' => 'returned', 'submitted_at' => now(),
        ]);

        $response = $this->actingAs($this->agent)->get(route('corex.rental-applications.review', $application));

        $response->assertOk();
        $response->assertSee('Awaiting applicant identity confirmation', false);
    }

    // ── (d) abandon safety — nothing lost, the link resumes at the gate ───

    public function test_abandoning_at_the_gate_loses_nothing_returning_resumes_at_the_gate(): void
    {
        Mail::fake();
        $application = $this->application(['email' => 'applicant@example.co.za']);

        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['full_name' => 'Sipho Ndlovu', 'id_number' => '8501015800083']));

        // Fresh "session" (no cookies carried over) — a genuinely different
        // browser/day, same link. The RETURN gate (a different, earlier
        // check) fires first on a fresh session — proving identity there
        // is what a real returning applicant would do before ever
        // reaching this gate again.
        $this->flushSession();
        $returnGate = $this->get(route('rental-applications.public.show', $application->token));
        $returnGate->assertOk();
        $returnGate->assertSee("Verify it's you", false);

        $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => '8501015800083']);

        $response = $this->get(route('rental-applications.public.show', $application->token));

        $response->assertRedirect(route('rental-applications.public.identity-gate', $application->token));
        $application->refresh();
        $this->assertSame('Sipho Ndlovu', $application->full_name);
        $this->assertNotNull($application->submitted_at);
    }

    public function test_pdf_and_document_view_are_also_gated_while_identity_is_pending(): void
    {
        $application = $this->application(['email' => 'applicant@example.co.za']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->get(route('rental-applications.public.pdf', $application->token))
            ->assertRedirect(route('rental-applications.public.identity-gate', $application->token));

        $document = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
            'original_name' => 'payslip.pdf', 'storage_path' => 'rental-applications/x/payslip.pdf',
            'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 100,
            'source_type' => 'rental_application', 'source_id' => $application->id,
            'agency_id' => $application->agency_id, 'branch_id' => $application->branch_id,
        ]));
        $this->get(route('rental-applications.public.documents.view', [$application->token, $document->id]))
            ->assertRedirect(route('rental-applications.public.identity-gate', $application->token));
    }

    // ── resubmits (reopened applications) never re-ask — Return Gate already proved identity ──

    public function test_a_resubmit_after_reopen_is_never_re_gated_by_the_identity_gate(): void
    {
        Mail::fake();
        $application = $this->application([
            'status' => 'reopened', 'submitted_at' => now()->subDay(), 'identity_verified_at' => now()->subDay(),
            'current_generation' => 1, 'full_name' => 'Sipho Ndlovu',
        ]);
        $this->withSession(["rental_application_return_gate_passed:{$application->token}" => true]);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        // Straight through to FICA, no identity-gate redirect.
        $response->assertRedirect();
        $this->assertStringContainsString('fica', (string) $response->headers->get('Location'));
        Mail::assertSent(\App\Mail\RentalApplicationReturnedMail::class);
    }

    // ── (f) agency-configurable, sensible defaults ────────────────────────

    public function test_identity_gate_settings_have_sensible_defaults_and_are_agency_configurable(): void
    {
        $this->assertTrue(RentalApplicationQualifyingSetting::identityGateEnabledFor($this->agency->id));
        $this->assertSame(5, RentalApplicationQualifyingSetting::identityGateAttemptMaxFor($this->agency->id));
        $this->assertSame(15, RentalApplicationQualifyingSetting::identityGateAttemptWindowMinutesFor($this->agency->id));
        $this->assertNull(RentalApplicationQualifyingSetting::identityGateOtpLengthFor($this->agency->id));

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['identity_gate_enabled' => false, 'identity_gate_attempt_max' => 3, 'identity_gate_attempt_window_minutes' => 30, 'identity_gate_otp_length' => 8],
        );

        $this->assertFalse(RentalApplicationQualifyingSetting::identityGateEnabledFor($this->agency->id));
        $this->assertSame(3, RentalApplicationQualifyingSetting::identityGateAttemptMaxFor($this->agency->id));
        $this->assertSame(30, RentalApplicationQualifyingSetting::identityGateAttemptWindowMinutesFor($this->agency->id));
        $this->assertSame(8, RentalApplicationQualifyingSetting::identityGateOtpLengthFor($this->agency->id));
    }

    public function test_disabling_the_gate_lets_first_submission_straight_through(): void
    {
        Mail::fake();
        RentalApplicationQualifyingSetting::updateOrCreate(['agency_id' => $this->agency->id], ['identity_gate_enabled' => false]);
        $application = $this->application(['email' => 'applicant@example.co.za']);

        $response = $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $response->assertRedirect();
        $this->assertStringContainsString('fica', (string) $response->headers->get('Location'));
        Mail::assertSent(\App\Mail\RentalApplicationReturnedMail::class);
    }

    // ── (c) non-receipt / expiry — human sentences, never "Too many attempts" ──

    public function test_failed_attempts_are_rate_limited_and_the_locked_out_screen_names_the_agent_never_a_bare_message(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['identity_gate_attempt_max' => 2, 'identity_gate_attempt_window_minutes' => 15],
        );
        $application = $this->application(['email' => null, 'id_number' => '8501015800083']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['id_number' => '8501015800083']));

        $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong'])->assertSessionHasErrors('gate');
        $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong'])->assertSessionHasErrors('gate');

        $lockedOut = $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong']);
        $lockedOut->assertStatus(429);
        $lockedOut->assertSee("We can't verify you right now", false);
        $lockedOut->assertSee('Test Agent');
        $lockedOut->assertSee('agent@example.co.za');
        $lockedOut->assertDontSee('Too many attempts');
    }

    public function test_resend_otp_sends_a_new_code_and_does_not_resend_automatically_on_every_reload(): void
    {
        Mail::fake();
        $application = $this->application(['email' => 'applicant@example.co.za']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload());

        $this->get(route('rental-applications.public.identity-gate', $application->token))->assertOk();
        $this->get(route('rental-applications.public.identity-gate', $application->token))->assertOk();
        Mail::assertSentCount(1);

        // Past the resend cooldown the initial auto-send itself already
        // started (OtpService::throttle() covers every issue, including
        // this feature's own automatic first send) — proves resend
        // genuinely works once the window passes, not just that the
        // route responds.
        $this->travel(61)->seconds();
        $this->post(route('rental-applications.public.identity-gate.resend-otp', $application->token))
            ->assertRedirect(route('rental-applications.public.identity-gate', $application->token));
        Mail::assertSentCount(2);
    }

    /**
     * Never the "identity gate uses OtpService's own SEPARATE budget from
     * the Return Gate" mistake — this asserts the two gates have distinct
     * named limiters (rental-application-identity-gate vs
     * rental-application-gate) and never share an attempt count.
     */
    public function test_identity_gate_lockout_never_shares_budget_with_the_return_gate(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['identity_gate_attempt_max' => 2, 'identity_gate_attempt_window_minutes' => 15, 'return_gate_attempt_max' => 2, 'return_gate_attempt_window_minutes' => 15],
        );
        $application = $this->application(['email' => null, 'id_number' => '8501015800083']);
        $this->post(route('rental-applications.public.submit', $application->token), $this->submitPayload(['id_number' => '8501015800083']));

        // Exhaust the IDENTITY gate's own budget.
        $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong']);
        $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong']);
        $this->post(route('rental-applications.public.verify-identity-gate', $application->token), ['id_number' => 'wrong'])->assertStatus(429);

        // The Return Gate's own endpoint, same token, is unaffected.
        $this->post(route('rental-applications.public.verify-gate', $application->token), ['id_number' => 'wrong'])->assertSessionHasErrors('gate');
    }
}

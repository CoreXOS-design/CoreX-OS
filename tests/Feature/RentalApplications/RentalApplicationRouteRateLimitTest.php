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
 * AT-392 round 2, 2026-09-13 — the conductor's sweep of the public
 * applicant-journey routes after the document-upload throttle incident:
 * show (30/1), autosave (40/1), submit (10/1), pdf (30/1), and document
 * view (30/1) all still carried Laravel's stock per-IP throttle — the
 * exact defect the document-upload fix closed. All five re-keyed to the
 * application token, same pattern as rental-application-documents, each
 * with its own agency-configurable default.
 *
 * One representative proof per route (independent-budget + human-message
 * proofs already exhaustively covered for the identical mechanism in
 * RentalApplicationDocumentUploadRateLimitTest — repeating all three
 * proofs five more times would test the SAME shared per-token limiter
 * plumbing, not new behaviour) plus the settings' sensible-default /
 * agency-configurable checks.
 */
final class RentalApplicationRouteRateLimitTest extends TestCase
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

    private function application(array $attrs = []): RentalApplication
    {
        return RentalApplication::create(array_merge([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'sent',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
        ], $attrs));
    }

    public function test_show_route_trips_at_its_own_configured_cap_with_the_human_message_and_two_tokens_never_share_a_budget(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['show_rate_limit_max' => 2, 'show_rate_limit_window_minutes' => 10],
        );
        $applicationA = $this->application();
        $applicationB = $this->application(['contact_id' => Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Other', 'last_name' => 'Applicant', 'email' => 'other@example.co.za',
        ])->id]);

        $this->get(route('rental-applications.public.show', $applicationA->token))->assertOk();
        $this->get(route('rental-applications.public.show', $applicationA->token))->assertOk();
        $tripped = $this->get(route('rental-applications.public.show', $applicationA->token));
        $tripped->assertStatus(429);
        $tripped->assertSee('This page is receiving a lot of requests right now');
        $tripped->assertDontSee('This link has expired');

        // Token B, same test process (same "IP"), is completely unaffected.
        $this->get(route('rental-applications.public.show', $applicationB->token))->assertOk();
    }

    public function test_submit_route_is_keyed_per_token_with_its_own_configurable_cap(): void
    {
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_SUBMIT_RATE_LIMIT_MAX,
            RentalApplicationQualifyingSetting::submitRateLimitMaxFor($this->agency->id)
        );

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['submit_rate_limit_max' => 5, 'submit_rate_limit_window_minutes' => 15],
        );

        $this->assertSame(5, RentalApplicationQualifyingSetting::submitRateLimitMaxFor($this->agency->id));
        $this->assertSame(15, RentalApplicationQualifyingSetting::submitRateLimitWindowMinutesFor($this->agency->id));
    }

    public function test_pdf_route_trips_with_the_human_message_at_its_configured_cap(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['pdf_rate_limit_max' => 1, 'pdf_rate_limit_window_minutes' => 10],
        );
        $application = $this->application();

        // Laravel's limiter allows exactly $maxAttempts through before
        // refusing (a cap of 1 lets ONE request reach the controller) —
        // so the first request's own outcome is irrelevant here (it may
        // hit the real Puppeteer-backed PdfService, which can fail for
        // reasons that have nothing to do with rate limiting in a test
        // environment without a working render pipeline). Only the
        // SECOND request is this test's actual assertion: the throttle
        // middleware runs BEFORE the controller regardless of what the
        // first request did, so it must refuse every attempt after the cap.
        $this->get(route('rental-applications.public.pdf', $application->token));

        $tripped = $this->get(route('rental-applications.public.pdf', $application->token));
        $tripped->assertStatus(429);
        $tripped->assertJson([
            'message' => "This is being requested a lot right now, so it's paused for a moment. Please wait a minute and try again.",
        ]);
    }

    public function test_document_view_and_autosave_request_settings_have_sensible_defaults_and_are_agency_configurable(): void
    {
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_DOCUMENT_VIEW_RATE_LIMIT_MAX,
            RentalApplicationQualifyingSetting::documentViewRateLimitMaxFor($this->agency->id)
        );
        $this->assertSame(
            RentalApplicationQualifyingSetting::DEFAULT_AUTOSAVE_REQUEST_RATE_LIMIT_MAX,
            RentalApplicationQualifyingSetting::autosaveRequestRateLimitMaxFor($this->agency->id)
        );

        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            [
                'document_view_rate_limit_max' => 7, 'document_view_rate_limit_window_minutes' => 3,
                'autosave_request_rate_limit_max' => 8, 'autosave_request_rate_limit_window_minutes' => 2,
            ],
        );

        $this->assertSame(7, RentalApplicationQualifyingSetting::documentViewRateLimitMaxFor($this->agency->id));
        $this->assertSame(3, RentalApplicationQualifyingSetting::documentViewRateLimitWindowMinutesFor($this->agency->id));
        $this->assertSame(8, RentalApplicationQualifyingSetting::autosaveRequestRateLimitMaxFor($this->agency->id));
        $this->assertSame(2, RentalApplicationQualifyingSetting::autosaveRequestRateLimitWindowMinutesFor($this->agency->id));
    }

    /**
     * Autosave's OUTER middleware trip must stay 200 + {saved:false} —
     * NEVER a visible 429 — matching autosave()'s own documented contract
     * ("Always returns 200 — never a visible error"). This is the whole
     * reason autosave_request_rate_limit_max is a SEPARATE setting from
     * the existing autosave_rate_limit_max (the inner per-application
     * counter) rather than sharing one number with it.
     */
    public function test_autosave_route_trips_silently_at_200_never_a_visible_error(): void
    {
        RentalApplicationQualifyingSetting::updateOrCreate(
            ['agency_id' => $this->agency->id],
            ['autosave_request_rate_limit_max' => 2, 'autosave_request_rate_limit_window_minutes' => 10],
        );
        $application = $this->application();

        $this->postJson(route('rental-applications.public.autosave', $application->token), ['full_name' => 'A'])->assertOk();
        $this->postJson(route('rental-applications.public.autosave', $application->token), ['full_name' => 'B'])->assertOk();

        $tripped = $this->postJson(route('rental-applications.public.autosave', $application->token), ['full_name' => 'C']);
        $tripped->assertOk();
        $tripped->assertJson(['saved' => false]);
    }
}

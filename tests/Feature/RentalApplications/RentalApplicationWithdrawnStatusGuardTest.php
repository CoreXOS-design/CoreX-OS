<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * REGRESSION FIX (2026-09-12) — a second end-to-end walkthrough found the
 * generic agent status endpoint (RentalApplicationController::updateStatus(),
 * fed by the dropdown on index/show/view-readonly.blade.php) had NO guard
 * against leaving 'withdrawn': the validation only checked the TARGET status
 * was agent-settable and that the CURRENT status had been submitted at all —
 * neither said anything about which transitions were actually meant to be
 * reachable. A plain agent could silently flip a withdrawn application back
 * to under_assessment with one dropdown click — no confirmation, no required
 * note — directly contradicting this module's own documented policy that
 * withdrawn is a final, considered decision (see RentalApplication::
 * REOPENABLE_STATUSES's own docblock).
 *
 * The fix, locked in below:
 * 1. The generic endpoint now refuses ANY transition where the CURRENT
 *    status is 'withdrawn' — for every role, not just plain agents. A
 *    permission check would be the wrong shape here: this isn't "who may do
 *    this," it's "this door doesn't lead anywhere any more."
 * 2. 'withdrawn' was added to REOPENABLE_STATUSES, reusing the EXACT same
 *    override-tier-gated, required-note, audited reopen() endpoint already
 *    built for declined — the one legitimate, explicit, named door back.
 * 3. The status dropdown itself no longer renders on a withdrawn row at all
 *    (index/show/view-readonly.blade.php) — covered by browser-level proof
 *    in this session's report, not re-asserted here as a Blade-text test
 *    (see BUILD_STANDARD.md §5a: a hidden option and a refused endpoint are
 *    two different claims — this file proves the endpoint refuses it
 *    regardless of what any template renders).
 *
 * SAME-DAY FOLLOW-UP, Johan (approved): "withdrawn" reads as if the
 * applicant acted for themselves; they didn't — there is no applicant
 * self-service withdraw anywhere in this module. Marking an application
 * withdrawn is now its own explicit action (not a value in the shared
 * under_assessment/withdrawn dropdown), REQUIRES a note
 * (required_if:status,withdrawn), and displays as
 * RentalApplication::WITHDRAWN_LABEL ("Recorded as withdrawn by applicant")
 * rather than a bare "Withdrawn" badge. Covered below:
 * 4. test_marking_withdrawn_without_a_note_is_rejected
 * 5. test_marking_withdrawn_with_a_note_succeeds_and_is_labelled_correctly
 * 6. test_marking_under_assessment_still_allows_an_optional_note (regression
 *    guard — the new required_if must not spill onto the OTHER agent-settable
 *    status, which stays a routine, optional-note judgement call).
 */
final class RentalApplicationWithdrawnStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private Contact $contact;
    private User $overrideAgent; // role=admin — override tier, same as RentalApplicationReopenTest's fixture.
    private User $plainAgent;    // role=agent — has rental_applications.create, but NOT override tier.

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Thabo', 'last_name' => 'Mokoena', 'email' => 'thabo@example.co.za',
        ]);
        $this->overrideAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->plainAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
    }

    private function withdrawnApplication(?User $createdBy = null): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => ($createdBy ?? $this->overrideAgent)->id, 'status' => 'withdrawn',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'full_name' => 'Thabo Mokoena', 'id_number' => '8505125800086',
            'submitted_at' => now()->subDay(), 'current_generation' => 1,
        ]);
    }

    public function test_generic_status_endpoint_refuses_to_move_a_withdrawn_application_anywhere(): void
    {
        // Created by (and owned by) the plain agent, so an 'own' data-scope
        // check can never be the reason this is refused — isolating the
        // assertion to the withdrawn-transition guard specifically.
        $application = $this->withdrawnApplication($this->plainAgent);

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'under_assessment'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('withdrawn', $application->fresh()->status, 'The generic endpoint must never move an application out of withdrawn.');
        $this->assertSame(0, RentalApplicationStatusHistory::where('rental_application_id', $application->id)->count(), 'A refused transition must record nothing.');
    }

    /**
     * The refusal is a "this door is closed" rule, not a permission check —
     * it must hold even for an override-tier user attempting the SAME
     * generic endpoint. The override tier's own door is reopen(), tested
     * below, not this one.
     */
    public function test_generic_status_endpoint_refuses_withdrawn_even_for_an_override_tier_user(): void
    {
        $application = $this->withdrawnApplication();

        $response = $this->actingAs($this->overrideAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'under_assessment'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame('withdrawn', $application->fresh()->status);
    }

    public function test_reopen_endpoint_moves_withdrawn_to_reopened_for_an_override_tier_user_with_a_note(): void
    {
        $application = $this->withdrawnApplication();

        $response = $this->actingAs($this->overrideAgent)->post(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => 'Applicant called back — they want to proceed after all, please reopen.'],
        );

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $response->assertJsonPath('status', 'reopened');

        $application->refresh();
        $this->assertSame('reopened', $application->status);
        $this->assertSame($this->overrideAgent->id, $application->reopened_by_user_id);
        $this->assertNotNull($application->reopened_at);

        $history = RentalApplicationStatusHistory::where('rental_application_id', $application->id)->latest('id')->first();
        $this->assertNotNull($history, 'The reopen must be recorded in the audit trail — who, when, note.');
        $this->assertSame('withdrawn', $history->from_status);
        $this->assertSame('reopened', $history->to_status);
        $this->assertSame($this->overrideAgent->id, $history->changed_by_user_id);
        $this->assertStringContainsString('Applicant called back', $history->note);
    }

    public function test_reopen_endpoint_refuses_withdrawn_for_a_plain_non_override_agent(): void
    {
        // Owned by the plain agent too — isolating the refusal to the
        // override-tier check inside reopen() itself, not a data-scope gate.
        $application = $this->withdrawnApplication($this->plainAgent);

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => 'Trying to reopen without the right tier.'],
        );

        $response->assertStatus(403);
        $this->assertSame('withdrawn', $application->fresh()->status, 'A refused reopen must never change status.');
    }

    public function test_reopen_endpoint_requires_a_note_to_reopen_a_withdrawn_application(): void
    {
        $application = $this->withdrawnApplication();

        $response = $this->actingAs($this->overrideAgent)->post(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => ''],
        );

        $response->assertSessionHasErrors('note');
        $this->assertSame('withdrawn', $application->fresh()->status);
    }

    /**
     * Once reopened, the application must land back in a genuinely working
     * state — 'reopened' is one of REVIEWABLE_STATUSES, so Review opens
     * normally, exactly like the declined-reopen path already proven in
     * RentalApplicationReopenTest.
     */
    public function test_after_reopening_a_withdrawn_application_review_opens_normally(): void
    {
        $application = $this->withdrawnApplication();
        $this->actingAs($this->overrideAgent)->post(
            route('corex.rental-applications.review.reopen', $application),
            ['note' => 'Reopening for reassessment.'],
        )->assertOk();

        $review = $this->actingAs($this->overrideAgent)->get(route('corex.rental-applications.review', $application->fresh()));
        $review->assertOk();
    }

    private function returnedApplication(?User $createdBy = null): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $this->contact->id,
            'created_by_user_id' => ($createdBy ?? $this->overrideAgent)->id, 'status' => 'returned',
            'token' => Str::random(64), 'token_expires_at' => now()->addDays(14),
            'full_name' => 'Thabo Mokoena', 'id_number' => '8505125800086',
            'submitted_at' => now()->subDay(), 'current_generation' => 1,
        ]);
    }

    public function test_marking_withdrawn_without_a_note_is_rejected(): void
    {
        $application = $this->returnedApplication($this->plainAgent);

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'withdrawn'],
        );

        $response->assertSessionHasErrors('note');
        $this->assertSame('returned', $application->fresh()->status, 'A rejected withdrawal must never change status.');
        $this->assertSame(0, RentalApplicationStatusHistory::where('rental_application_id', $application->id)->count());
    }

    public function test_marking_withdrawn_with_a_note_succeeds_and_is_labelled_correctly(): void
    {
        $application = $this->returnedApplication($this->plainAgent);

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'withdrawn', 'note' => 'Applicant called to say they found another place.'],
        );

        $response->assertRedirect();
        $response->assertSessionHas('success', RentalApplication::WITHDRAWN_LABEL . '.');

        $application->refresh();
        $this->assertSame('withdrawn', $application->status);

        $history = RentalApplicationStatusHistory::where('rental_application_id', $application->id)->latest('id')->first();
        $this->assertNotNull($history);
        $this->assertSame('returned', $history->from_status);
        $this->assertSame('withdrawn', $history->to_status);
        $this->assertSame($this->plainAgent->id, $history->changed_by_user_id, 'The audit entry must name who recorded it.');
        $this->assertSame('Applicant called to say they found another place.', $history->note);

        $this->assertSame('Recorded as withdrawn by applicant', RentalApplication::displayStatusLabel('withdrawn'));
    }

    /** Regression guard — the new required_if:status,withdrawn must not spill onto the OTHER agent-settable status. */
    public function test_marking_under_assessment_still_allows_an_optional_note(): void
    {
        $application = $this->returnedApplication($this->plainAgent);

        $response = $this->actingAs($this->plainAgent)->post(
            route('corex.rental-applications.update-status', $application),
            ['status' => 'under_assessment'],
        );

        $response->assertRedirect();
        $response->assertSessionDoesntHaveErrors('note');
        $this->assertSame('under_assessment', $application->fresh()->status);
    }
}

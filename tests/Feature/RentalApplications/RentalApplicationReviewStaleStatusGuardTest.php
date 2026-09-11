<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression, 2026-09-11 (cc2's finding) — the "Review" button is correctly
 * hidden on the list once an application leaves
 * RentalApplicationController::REVIEWABLE_STATUSES, but the ROUTE itself had
 * no status check of its own: a bookmarked/copied review URL still opened
 * the full working editor on an already-decided application. Fixed by
 * guarding RentalApplicationReviewController::show() with the same
 * REVIEWABLE_STATUSES set, redirecting to the read-only "Open" view
 * otherwise — with two deliberate exceptions, not a blanket redirect,
 * because this SAME screen is also where the agent takes their one
 * remaining action after a decision:
 * - approved-but-not-yet-notified: the wishlist-confirm-then-send step
 *   (AT-392) still needs the working screen.
 * - declined, for an override-tier (CO/admin) user: the reopen action
 *   (RentalApplication::REOPENABLE_STATUSES includes 'declined' for exactly
 *   this reason).
 * A blanket redirect on REVIEWABLE_STATUSES alone would have silently
 * broken both of those real, already-shipped features.
 */
final class RentalApplicationReviewStaleStatusGuardTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function application(string $status): RentalApplication
    {
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Test', 'last_name' => 'Applicant', 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);

        return RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $this->agent->id, 'status' => $status,
        ]);
    }

    public function test_a_reviewable_status_still_renders_the_working_editor(): void
    {
        $rentalApplication = $this->application('under_assessment');

        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.review', $rentalApplication))
            ->assertOk();
    }

    public function test_approved_but_not_yet_notified_still_renders_for_the_wishlist_send_step(): void
    {
        $rentalApplication = $this->application('approved');

        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.review', $rentalApplication))
            ->assertOk();
    }

    public function test_approved_and_notified_redirects_to_the_read_only_view(): void
    {
        $rentalApplication = $this->application('approved');
        $rentalApplication->forceFill(['applicant_notified_at' => now()])->save();

        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.review', $rentalApplication))
            ->assertRedirect(route('corex.rental-applications.show', $rentalApplication));
    }

    public function test_declined_still_renders_for_an_override_tier_user_to_reopen(): void
    {
        $rentalApplication = $this->application('declined');
        $this->agency->update(['rental_application_co_user_ids' => [$this->agent->id]]);

        $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.review', $rentalApplication))
            ->assertOk();
    }

    public function test_declined_redirects_for_a_non_override_user(): void
    {
        // role 'agent' (not 'admin' — User::isRentalApplicationOverrideTier()
        // treats every 'admin'/'super_admin' as override-tier regardless of
        // the CO list, so 'admin' can never produce a genuine non-override
        // user for this test) restricts PermissionService's data scope to
        // 'own' in this test suite (documented in
        // RentalApplicationAuthorisationQueueSearchSortTest::ro()'s own
        // comment as test-suite-only, unreachable on a real server) — made
        // the application's own creator here so guardRentalApplication()'s
        // ownership check passes and the request actually reaches THIS
        // guard, the one under test.
        $nonOverrideAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $contact = Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Test', 'last_name' => 'Applicant', 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);
        $rentalApplication = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $nonOverrideAgent->id, 'status' => 'declined',
        ]);

        $this->actingAs($nonOverrideAgent)
            ->get(route('corex.rental-applications.review', $rentalApplication))
            ->assertRedirect(route('corex.rental-applications.show', $rentalApplication));
    }

    /**
     * Regression, 2026-09-11 (cc4's E2E walk) — the header's $headerExtraFact
     * used to be driven by $propertyLinkLocked (a permanent one-way flag set
     * once at first submission, never cleared) for the agent, so it could
     * still claim "submitted for authorisation" on an application that had
     * since been DECLINED. Fixed to state the application's own actual
     * current status, with the lock (still worth saying) appended as its
     * own clearly-labelled fact rather than standing in for the status.
     */
    public function test_header_states_actual_status_not_the_stale_property_lock_claim(): void
    {
        $rentalApplication = $this->application('declined');
        $rentalApplication->forceFill(['submitted_at' => now()->subDays(3)])->save();
        $this->agency->update(['rental_application_co_user_ids' => [$this->agent->id]]);

        $response = $this->actingAs($this->agent)
            ->get(route('corex.rental-applications.review', $rentalApplication));

        $response->assertOk();
        $response->assertDontSee('submitted for authorisation');
        $response->assertSee('Declined');
    }
}

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
 * Regression, 2026-09-11 — an authoriser opening ANY application that is
 * approved but not yet notified 500'd: review.blade.php's tenant-wishlist
 * drawer block referenced $existingWishlist/$wishlistPrefill (computed only
 * by RentalApplicationReviewController::show(), never by
 * RentalApplicationAuthorisationController::show()) with no $viewerRole
 * guard of its own. Introduced by e341dc7d6 (2026-09-10); unnoticed until
 * now because the block only renders for status===approved &&
 * !applicant_notified_at — a narrow, easy-to-miss window. Fixed by gating
 * the whole block behind $viewerRole === 'agent' (see that block's own
 * comment in review.blade.php) rather than duplicating the wishlist query
 * into the authoriser controller: the wishlist-add/update routes it posts
 * to only exist under the agent controller, and the only way to open the
 * drawer at all is a trigger button that already sits behind the same
 * agent-only gate — this is genuinely an agent-only step in the approval
 * workflow, not a shared one the authoriser has any action to take in.
 */
final class RentalApplicationAuthorisationApprovedNotYetNotifiedScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoriser_can_open_an_approved_not_yet_notified_application(): void
    {
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);

        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $authoriser = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $agency->update(['rental_application_ro_user_ids' => [$authoriser->id]]);

        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Test', 'last_name' => 'Applicant', 'email' => 'applicant-' . uniqid() . '@example.co.za',
        ]);

        $rentalApplication = RentalApplication::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => 'approved',
            'approved_rental_amount' => 8500, 'applicant_notified_at' => null,
        ]);

        $response = $this->actingAs($authoriser)
            ->get(route('corex.rental-applications.authorisation.show', $rentalApplication));

        $response->assertOk();
        $response->assertDontSee('existingWishlist', false);
    }
}

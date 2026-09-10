<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cc1's live find, 2026-09-10 — a genuine multi-tenancy breach, not a
 * theoretical one. `'property_id' => ['exists:properties,id']` on the
 * property-link endpoint (and its two siblings, RentalApplicationController's
 * store()/update()) is a RAW query against the properties table — it never
 * goes through the Property model, so AgencyScope never applies. An ordinary
 * non-owner agent in one agency POSTed a real property_id belonging to a
 * completely different agency straight at link-property, bypassing the
 * search picker entirely, and it saved: 302, no 403, the application's
 * property_id genuinely set to another tenant's stock.
 *
 * Fixed via Property::findLinkableForRentalApplication() — resolves the id
 * through the model, with BOTH its scopes (AgencyScope AND scopeVisibleTo()'s
 * branch/own per the agency's `properties` Role Manager setting), so an id
 * that exists but isn't this user's to link comes back null exactly like an
 * id that doesn't exist. This file proves the fix on all three affected
 * routes, for all three boundaries (agency/branch/own) cc1 and cc5 between
 * them found leaking, and that a refused attempt is both a real 403 (not a
 * silent no-op) and lands in the audit trail (unlike a lock-refusal, which
 * deliberately logs nothing — see RentalApplicationPropertyLinkLockTest;
 * this is a different refusal reason with a different audit contract, by
 * design: a permission refusal is exactly what someone will want to see
 * later, an already-locked-state refusal changed nothing to record).
 */
final class RentalApplicationPropertyLinkTenancyTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agencyA;
    private Branch $branchA1;
    private Branch $branchA2;
    private Agency $agencyB;
    private Branch $branchB1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agencyA = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $this->branchA1 = Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'Ramsgate']);
        $this->branchA2 = Branch::create(['agency_id' => $this->agencyA->id, 'name' => 'Margate']);
        $this->agencyB = Agency::create(['name' => 'Rival Rentals', 'slug' => 'rival-' . uniqid()]);
        $this->branchB1 = Branch::create(['agency_id' => $this->agencyB->id, 'name' => 'Port Edward']);
    }

    private function property(Agency $agency, Branch $branch, ?User $agent = null, string $listingType = 'rental'): Property
    {
        // agent_id is NOT NULL on properties — a caller not testing "own"
        // scope specifically doesn't need to name a real agent, just any
        // valid one in the target agency/branch.
        $agent ??= User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'Test property ' . uniqid(), 'status' => 'active', 'property_type' => 'house',
            'listing_type' => $listingType, 'suburb' => 'Ramsgate', 'city' => 'Margate',
            'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
    }

    private function contact(Agency $agency, Branch $branch, ?User $createdBy = null): Contact
    {
        // ContactScope defaults a plain 'agent' role to 'own' (contacts they
        // created) in this suite's no-grants-seeded posture — a contact with
        // no created_by_user_id is invisible to Contact::findOrFail() for
        // every one of these tests' acting agents, unrelated to the property
        // fix under test. Always stamp it.
        return Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'created_by_user_id' => $createdBy?->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho' . uniqid() . '@example.co.za', 'phone' => '0821234567',
        ]);
    }

    private function application(User $agent, Contact $contact, string $status = 'sent', ?int $propertyId = null): RentalApplication
    {
        return RentalApplication::create([
            'agency_id' => $agent->agency_id, 'branch_id' => $agent->branch_id, 'contact_id' => $contact->id,
            'created_by_user_id' => $agent->id, 'status' => $status, 'property_id' => $propertyId,
        ]);
    }

    // ── The exact attack: cross-AGENCY, via link-property ────────────────

    public function test_link_property_refuses_a_property_belonging_to_a_different_agency(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $foreignProperty = $this->property($this->agencyB, $this->branchB1);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $foreignProperty->id],
        );

        $response->assertStatus(403);
        $this->assertNull($application->fresh()->property_id, 'the foreign agency\'s property id must never reach the row');
    }

    public function test_link_property_still_works_for_a_property_in_the_same_agency(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $ownProperty = $this->property($this->agencyA, $this->branchA1, $agent);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $ownProperty->id],
        );

        $response->assertRedirect();
        $this->assertSame($ownProperty->id, $application->fresh()->property_id, 'a legitimate in-scope link must still succeed');
    }

    public function test_refused_cross_agency_attempt_is_recorded_in_the_audit_trail(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $foreignProperty = $this->property($this->agencyB, $this->branchB1);

        $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $foreignProperty->id],
        )->assertStatus(403);

        $log = RentalApplicationAuditLog::where('rental_application_id', $application->id)
            ->where('event_category', 'property_link')->where('event_type', 'link_refused')->latest('id')->first();

        $this->assertNotNull($log, 'a refused cross-tenant link attempt must be recorded — this is exactly what someone will want to see later');
        $this->assertSame($agent->id, $log->user_id);
        $this->assertSame($foreignProperty->id, (int) $log->new_values['requested_property_id']);
    }

    public function test_clearing_the_property_link_is_never_blocked_by_the_tenancy_check(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $ownProperty = $this->property($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact, 'sent', $ownProperty->id);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => null],
        );

        $response->assertRedirect();
        $this->assertNull($application->fresh()->property_id);
    }

    public function test_link_property_refuses_a_non_rental_listing_in_the_same_agency(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $forSaleProperty = $this->property($this->agencyA, $this->branchA1, $agent, 'sale');

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $forSaleProperty->id],
        );

        $response->assertStatus(403);
        $this->assertNull($application->fresh()->property_id, 'a for-sale listing was never meant to be linkable to a rental application');
    }

    // ── The branch boundary — cc5's separate finding, same defect class ──

    public function test_link_property_refuses_a_property_in_a_different_branch_when_scope_is_branch(): void
    {
        // branch_manager resolves to 'branch' scope for `properties` under
        // this suite's no-grants-seeded fallback (PermissionService's
        // documented test-suite posture) — same mechanism
        // Property::scopeVisibleTo() reads in production via Role Manager.
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'branch_manager']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $otherBranchProperty = $this->property($this->agencyA, $this->branchA2);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $otherBranchProperty->id],
        );

        $response->assertStatus(403);
        $this->assertNull($application->fresh()->property_id, 'same agency is not enough — a branch-scoped role must not reach another branch\'s stock');
    }

    public function test_link_property_still_works_within_the_same_branch_when_scope_is_branch(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'branch_manager']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $sameBranchProperty = $this->property($this->agencyA, $this->branchA1);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $sameBranchProperty->id],
        );

        $response->assertRedirect();
        $this->assertSame($sameBranchProperty->id, $application->fresh()->property_id);
    }

    // ── The own boundary ───────────────────────────────────────────────

    public function test_link_property_refuses_another_agents_property_when_scope_is_own(): void
    {
        // Plain 'agent' role resolves to 'own' scope for `properties` under
        // the same no-grants-seeded fallback.
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $otherAgent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact);
        $colleaguesProperty = $this->property($this->agencyA, $this->branchA1, $otherAgent);

        $response = $this->actingAs($agent)->post(
            route('corex.rental-applications.review.link-property', $application),
            ['property_id' => $colleaguesProperty->id],
        );

        $response->assertStatus(403);
        $this->assertNull($application->fresh()->property_id, 'same agency AND branch is not enough — an own-scoped agent must not reach a colleague\'s listing');
    }

    // ── The two sibling routes — store() and update() ─────────────────────

    public function test_store_refuses_a_cross_agency_property_id(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $foreignProperty = $this->property($this->agencyB, $this->branchB1);

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $contact->id,
            'property_id' => $foreignProperty->id,
        ]);

        $response->assertStatus(403);
        $this->assertSame(0, RentalApplication::where('contact_id', $contact->id)->count(), 'the application must never be created with the foreign property attached');
    }

    public function test_store_still_works_with_a_legitimate_property_id(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $ownProperty = $this->property($this->agencyA, $this->branchA1, $agent);

        $response = $this->actingAs($agent)->post(route('corex.rental-applications.store'), [
            'contact_id' => $contact->id,
            'property_id' => $ownProperty->id,
        ]);

        $response->assertRedirect();
        $this->assertSame($ownProperty->id, RentalApplication::where('contact_id', $contact->id)->first()->property_id);
    }

    public function test_update_refuses_a_cross_agency_property_id(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact, 'draft');
        $foreignProperty = $this->property($this->agencyB, $this->branchB1);

        $response = $this->actingAs($agent)->put(route('corex.rental-applications.update', $application), [
            'property_id' => $foreignProperty->id,
        ]);

        $response->assertStatus(403);
        $this->assertNull($application->fresh()->property_id);
    }

    public function test_update_leaving_property_id_untouched_does_not_require_re_authorization(): void
    {
        // Regression guard for the pre-existing "absent means keep the
        // current value" contract — must not become "absent means refused."
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $contact = $this->contact($this->agencyA, $this->branchA1, $agent);
        $ownProperty = $this->property($this->agencyA, $this->branchA1, $agent);
        $application = $this->application($agent, $contact, 'draft', $ownProperty->id);

        $response = $this->actingAs($agent)->put(route('corex.rental-applications.update', $application), [
            'full_name' => 'Updated Name',
        ]);

        $response->assertRedirect();
        $this->assertSame($ownProperty->id, $application->fresh()->property_id);
    }

    // ── The search picker — must not offer what can't be linked ──────────

    public function test_search_properties_never_lists_a_property_from_another_agency(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyA->id, 'branch_id' => $this->branchA1->id, 'role' => 'agent']);
        $ownProperty = $this->property($this->agencyA, $this->branchA1, $agent);
        $foreignProperty = $this->property($this->agencyB, $this->branchB1);

        $response = $this->actingAs($agent)->getJson(route('corex.rental-applications.search-properties'));

        $response->assertOk();
        $ids = collect($response->json())->pluck('id')->all();
        $this->assertContains($ownProperty->id, $ids);
        $this->assertNotContains($foreignProperty->id, $ids);
    }
}

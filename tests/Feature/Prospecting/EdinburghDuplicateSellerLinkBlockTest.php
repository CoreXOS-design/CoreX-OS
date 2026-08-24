<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertyMatchDecision;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\ComposeSellerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Edinburgh erf 364 remediation (Johan, 2026-08-24) — "12 Edinburgh erf 364" duplicate-stock
 * failure. ComposeSellerService::linkSellerToProperty() used to write a bare updateOrInsert
 * with NO query against existing links at all: a seller already linked to an ACTIVE, on-market
 * property could be silently linked to a brand-new second property too. Three signals existed
 * at creation time (the active advertised property, the existing contact link, an agreeing CMA
 * scrape) and only one was checked, and only informationally.
 *
 * This suite reproduces the EXACT shape: a contact already seller-linked to an on-market
 * Property A, then a second capture attempt for the SAME contact against a brand-new listing
 * for the SAME address, which — via ensurePropertyForListing()'s idempotent promote — creates
 * a distinct Property B. That second link must hard-block, not warn.
 */
final class EdinburghDuplicateSellerLinkBlockTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $listingAgentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Edinburgh Test ' . Str::random(6), 'slug' => 'edinburgh-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // properties.agent_id has no default — a listing agent of record, distinct from
        // whichever role is under test in each case.
        $this->listingAgentId = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent'])->id;

        // PermissionService: an EMPTY role_permissions table is "unseeded" and allow-alls every
        // check (tests/TestCase.php) — the exact fallback that would make item 3's promote()
        // gate untestable. Seed the real, current QA1 grant shape as global-template
        // (agency_id=NULL) rows so grantsExist() flips on and both the route middleware and the
        // in-method check enforce for real: agent holds .access only, branch_manager holds
        // .access + .promote.
        $now = now();
        DB::table('role_permissions')->insert([
            ['role' => 'agent', 'permission_key' => 'deeds_capture.access', 'agency_id' => null, 'scope' => null, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'agent', 'permission_key' => 'outreach.compose', 'agency_id' => null, 'scope' => null, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'branch_manager', 'permission_key' => 'deeds_capture.access', 'agency_id' => null, 'scope' => null, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'branch_manager', 'permission_key' => 'deeds_capture.promote', 'agency_id' => null, 'scope' => null, 'created_at' => $now, 'updated_at' => $now],
            ['role' => 'branch_manager', 'permission_key' => 'outreach.compose', 'agency_id' => null, 'scope' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
        \App\Services\PermissionService::clearCache();
    }

    private function user(string $role): User
    {
        return User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => $role]);
    }

    private function activeProperty(string $address): Property
    {
        return Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $this->listingAgentId,
            'external_id' => (string) Str::uuid(),
            'title' => $address, 'address' => $address, 'suburb' => 'Uvongo',
            'street_number' => '12', 'street_name' => 'Edinburgh',
            'beds' => 0, 'baths' => 0, 'garages' => 0, 'price' => 0,
            'property_type' => 'house', 'status' => 'for_sale', 'listing_type' => 'sale',
        ]);
    }

    private function seller(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Edinburgh', 'last_name' => 'Seller', 'phone' => '', 'id_number' => '8001015009087',
        ]);
    }

    private function listing(string $address, ?int $matchedPropertyId, int $capturedBy): int
    {
        return (int) DB::table('prospecting_listings')->insertGetId([
            'agency_id' => $this->agencyId, 'portal_source' => 'p24', 'portal_ref' => 'test-' . Str::random(10),
            'portal_url' => 'https://example.test/' . Str::random(6), 'captured_by_user_id' => $capturedBy,
            'is_active' => true, 'address' => $address, 'suburb' => 'Uvongo', 'price' => 0,
            'matched_property_id' => $matchedPropertyId,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── The reproduction ────────────────────────────────────────────────

    /**
     * Full HTTP-level reproduction of the Edinburgh shape: seller already linked to an
     * on-market Property A; a SECOND, brand-new listing for the same address is opened and the
     * SAME seller is linked again — ensurePropertyForListing() promotes a distinct Property B,
     * and linkSellerToProperty() must hard-block before ever writing that second link.
     */
    public function test_edinburgh_shape_second_capture_is_hard_blocked(): void
    {
        $agent = $this->user('agent');
        $propertyA = $this->activeProperty('12 Edinburgh, Uvongo');
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $propertyA->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A second, unmatched listing for the same address — a fresh capture, exactly the
        // Edinburgh shape (property 3218 already active; a second capture attempt follows).
        $listingBId = $this->listing('12 Edinburgh, Uvongo', null, $agent->id);

        $response = $this->actingAs($agent)
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingBId]), [
                'contact_id' => $seller->id,
            ]);

        $response->assertStatus(409)->assertJsonPath('reason', 'duplicate_seller_link_blocked');

        $propertyBId = (int) DB::table('prospecting_listings')->where('id', $listingBId)->value('matched_property_id');
        $this->assertNotSame(0, $propertyBId, 'the listing IS promoted to its own property (the bug was the seller-link write, not the promote)');
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seller->id, 'property_id' => $propertyBId, 'role' => 'seller']);
        $this->assertSame(1, DB::table('contact_property')->where('contact_id', $seller->id)->where('role', 'seller')->count(), 'the seller stays linked to exactly ONE property — no duplicate stock');

        $decision = PropertyMatchDecision::where('subject_type', 'seller_link')->where('subject_key', 'contact:' . $seller->id)->first();
        $this->assertNotNull($decision, 'the block is recorded via PropertyMatchDecisionService, not silently dropped');
        $this->assertSame('blocked', $decision->outcome);
    }

    // ── Escape hatch (item 2) ──────────────────────────────────────────

    public function test_branch_manager_override_with_reason_clears_the_block_and_is_logged_via_match_decision(): void
    {
        $bm = $this->user('branch_manager');
        $propertyA = $this->activeProperty('12 Edinburgh, Uvongo');
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $propertyA->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $listingBId = $this->listing('12 Edinburgh, Uvongo', null, $bm->id);

        $response = $this->actingAs($bm)
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingBId]), [
                'contact_id' => $seller->id,
                'override_active_link_block' => true,
                'override_reason' => 'Confirmed with the seller — genuinely a second, separate property.',
            ]);

        $response->assertOk();
        $propertyBId = (int) DB::table('prospecting_listings')->where('id', $listingBId)->value('matched_property_id');
        $this->assertDatabaseHas('contact_property', ['contact_id' => $seller->id, 'property_id' => $propertyBId, 'role' => 'seller']);

        $decision = PropertyMatchDecision::where('subject_type', 'seller_link')->where('subject_key', 'contact:' . $seller->id)->first();
        $this->assertNotNull($decision);
        $this->assertSame('active_link_override', $decision->outcome);
        $this->assertSame($bm->id, $decision->confirmed_by_user_id, 'who overrode it is recorded');
        $this->assertNotNull($decision->confirmed_at, 'when it was overridden is recorded');
    }

    /** An agent cannot self-clear the block — override=true from a crafted request is ignored server-side. */
    public function test_agent_cannot_self_clear_the_block_even_with_override_flag(): void
    {
        $agent = $this->user('agent');
        $propertyA = $this->activeProperty('12 Edinburgh, Uvongo');
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $propertyA->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $listingBId = $this->listing('12 Edinburgh, Uvongo', null, $agent->id);

        $response = $this->actingAs($agent)
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingBId]), [
                'contact_id' => $seller->id,
                'override_active_link_block' => true,
                'override_reason' => 'I promise this is fine.',
            ]);

        $response->assertStatus(500);

        $propertyBId = (int) DB::table('prospecting_listings')->where('id', $listingBId)->value('matched_property_id');
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seller->id, 'property_id' => $propertyBId, 'role' => 'seller']);
    }

    public function test_override_requires_a_reason(): void
    {
        $bm = $this->user('branch_manager');
        $propertyA = $this->activeProperty('12 Edinburgh, Uvongo');
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $propertyA->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $listingBId = $this->listing('12 Edinburgh, Uvongo', null, $bm->id);

        $response = $this->actingAs($bm)
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingBId]), [
                'contact_id' => $seller->id,
                'override_active_link_block' => true,
            ]);

        $response->assertStatus(500);
        $propertyBId = (int) DB::table('prospecting_listings')->where('id', $listingBId)->value('matched_property_id');
        $this->assertDatabaseMissing('contact_property', ['contact_id' => $seller->id, 'property_id' => $propertyBId, 'role' => 'seller']);
    }

    // ── An off-market conflicting property never blocks (only ACTIVE stock is a certain match) ──

    public function test_a_second_link_is_not_blocked_when_the_first_property_is_off_market(): void
    {
        $agent = $this->user('agent');
        $sold = $this->activeProperty('9 Sold Street, Uvongo');
        $sold->update(['status' => 'sold']);
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $sold->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $listingBId = $this->listing('12 Edinburgh, Uvongo', null, $agent->id);

        $response = $this->actingAs($agent)
            ->postJson(route('seller-outreach.entry.link-seller-prospecting', ['prospectingListingId' => $listingBId]), [
                'contact_id' => $seller->id,
            ]);

        $response->assertOk();
        $propertyBId = (int) DB::table('prospecting_listings')->where('id', $listingBId)->value('matched_property_id');
        $this->assertDatabaseHas('contact_property', ['contact_id' => $seller->id, 'property_id' => $propertyBId, 'role' => 'seller']);
    }

    // ── Query-before-write (item 4), directly against the service ──────

    public function test_link_seller_to_property_queries_existing_links_before_writing(): void
    {
        $propertyA = $this->activeProperty('12 Edinburgh, Uvongo');
        $propertyB = $this->activeProperty('14 Edinburgh, Uvongo');
        $seller = $this->seller();
        DB::table('contact_property')->insert([
            'contact_id' => $seller->id, 'property_id' => $propertyA->id, 'role' => 'seller',
            'source' => 'manual', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\App\Exceptions\DuplicateSellerLinkBlockedException::class);
        app(ComposeSellerService::class)->linkSellerToProperty((int) $seller->id, (int) $propertyB->id, 'manual');
    }

    // ── promote() permission enforcement (item 3) ──────────────────────

    private function trackedPropertyForPromote(int $capturedByUserId): TrackedProperty
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '20', 'street_name' => 'Lilliecrona Drive',
            'suburb' => 'Manaba Beach', 'erf_number' => 'ERF-' . Str::random(4),
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(),
            // Deeds-capture visibility scope (assertPropertyInDeedsScope, pre-existing,
            // unrelated to this remediation) defaults to 'own' since deeds_capture.view
            // isn't seeded here — attribute the capture to the acting user so the
            // promote()-reachability proof isn't confounded by that separate scope gate.
            'deeds_captured_by_user_id' => $capturedByUserId,
        ]);
        $owner = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId,
            'first_name' => 'Owner', 'last_name' => 'Promote', 'phone' => '', 'id_number' => Str::random(13),
        ]);
        DB::table('tracked_property_owners')->insert([
            'tracked_property_id' => $tp->id, 'contact_id' => $owner->id, 'name' => 'Owner Promote',
            'is_primary' => true, 'role' => 'owner', 'ownership_status' => 'current',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $tp;
    }

    /** An agent-role user cannot reach promote() — blocked by BOTH the route middleware and the in-method check. */
    public function test_agent_cannot_reach_promote(): void
    {
        $agent = $this->user('agent');
        $tp = $this->trackedPropertyForPromote($agent->id);

        $response = $this->actingAs($agent)->post(route('corex.deeds-capture.promote', $tp->id), []);

        $response->assertStatus(403);
        $this->assertNull($tp->fresh()->promoted_to_property_id);
    }

    /** A branch_manager CAN reach promote() — holds deeds_capture.promote. */
    public function test_branch_manager_can_reach_promote(): void
    {
        $bm = $this->user('branch_manager');
        $tp = $this->trackedPropertyForPromote($bm->id);

        $response = $this->actingAs($bm)->post(route('corex.deeds-capture.promote', $tp->id), []);

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $this->assertNotNull($tp->fresh()->promoted_to_property_id);
    }
}

<?php

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the Universal Match-or-Create rule
 * (CLAUDE.md Non-Negotiable #10) — every ingestion path MUST go through
 * TrackedPropertyMatchOrCreateService and the 5-strategy resolver.
 *
 * For each strategy: one positive test that it matches an existing TP,
 * one negative test that a non-match creates a new row. Plus tests for
 * source_chain append-only semantics and promoteToStock() audit preservation.
 */
class TrackedPropertyMatchOrCreateTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private TrackedPropertyMatchOrCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user   = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
            'role'      => 'admin',
        ]);

        $this->service = new TrackedPropertyMatchOrCreateService();
    }

    // ──────────────────────── Strategy 1: source-ref ────────────────────────

    public function test_strategy_1_source_ref_matches_existing(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'First St', 'suburb' => 'Margate'],
            ['type' => 'p24', 'ref' => 'P24-AAA-111']
        );

        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Totally Different Rd', 'suburb' => 'Uvongo'],
            ['type' => 'p24', 'ref' => 'P24-AAA-111']
        );

        $this->assertSame($first->id, $second->id, 'Same source ref must resolve to same TP');
        $this->assertSame(1, TrackedProperty::queryWithoutAgencyScope()->count());
    }

    public function test_strategy_1_different_source_ref_creates_new(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'First St', 'suburb' => 'Margate'],
            ['type' => 'p24', 'ref' => 'P24-AAA-111']
        );
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_name' => 'Second St', 'suburb' => 'Uvongo'],
            ['type' => 'p24', 'ref' => 'P24-AAA-222']
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, TrackedProperty::queryWithoutAgencyScope()->count());
    }

    // ──────────────────────── Strategy 2: GPS proximity ─────────────────────

    public function test_strategy_2_gps_proximity_matches_within_tolerance(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate', 'latitude' => -30.8625000, 'longitude' => 30.3700000],
            ['type' => 'manual', 'ref' => 'M-1']
        );

        // ~1m off — well inside the 0.00005° (~5m) tolerance.
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate', 'latitude' => -30.8625010, 'longitude' => 30.3700010],
            ['type' => 'manual', 'ref' => 'M-2']
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_strategy_2_gps_far_apart_creates_new(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate', 'latitude' => -30.8625, 'longitude' => 30.3700],
            ['type' => 'manual', 'ref' => 'M-1']
        );
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Uvongo', 'latitude' => -30.9000, 'longitude' => 30.4000],
            ['type' => 'manual', 'ref' => 'M-2']
        );

        $this->assertNotSame($first->id, $second->id);
    }

    // ──────────────────────── Strategy 3: erf + suburb ──────────────────────

    public function test_strategy_3_erf_plus_suburb_matches(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '1234', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'D-1']
        );
        // Different source type+ref, no GPS, no address — must resolve via erf+suburb.
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '1234', 'suburb' => 'MARGATE'],
            ['type' => 'cma', 'ref' => 'C-1']
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_strategy_3_different_erf_creates_new(): void
    {
        $first  = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '1234', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'D-1']
        );
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '5678', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'D-2']
        );

        $this->assertNotSame($first->id, $second->id);
    }

    // ──────────────────────── Strategy 4: normalised address ────────────────

    public function test_strategy_4_normalised_address_matches(): void
    {
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '12', 'street_name' => 'Mitchell St', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'A-1']
        );
        // Different spelling: "MITCHELL STREET" should normalise to the same.
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '12', 'street_name' => 'MITCHELL STREET', 'suburb' => 'MARGATE'],
            ['type' => 'cma', 'ref' => 'A-2']
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_strategy_4_different_address_creates_new(): void
    {
        $first  = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '12', 'street_name' => 'Mitchell St', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'A-1']
        );
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '99', 'street_name' => 'Mitchell St', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'A-2']
        );

        $this->assertNotSame($first->id, $second->id);
    }

    // ──────────────────────── Strategy 5: token overlap ─────────────────────

    public function test_strategy_5_token_overlap_matches_loosely(): void
    {
        // Seed a TP with a clear street_name + suburb (no source ref, no GPS, no erf)
        $first = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '15', 'street_name' => 'Sandpiper Avenue', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'T-1']
        );

        // Incoming: same suburb, no street_number, free-text address with 2+ token overlap.
        // No source ref, no GPS, no erf, no street_name → forces strategy 5.
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate', 'address' => '15 Sandpiper Avenue, somewhere'],
            ['type' => 'flyer', 'ref' => 'T-2']
        );

        $this->assertSame($first->id, $second->id);
    }

    public function test_strategy_5_no_overlap_creates_new(): void
    {
        $first  = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '15', 'street_name' => 'Sandpiper Avenue', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'T-1']
        );
        // Same suburb but entirely different street tokens → no overlap, new TP.
        $second = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate', 'address' => 'Completely unrelated wording here'],
            ['type' => 'flyer', 'ref' => 'T-2']
        );

        $this->assertNotSame($first->id, $second->id);
    }

    // ──────────────────────── source_chain semantics ───────────────────────

    public function test_source_chain_is_appended_on_every_match(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '999', 'suburb' => 'Margate'],
            ['type' => 'deeds', 'ref' => 'X-1']
        );
        $this->assertCount(1, $tp->source_chain);

        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '999', 'suburb' => 'Margate'],
            ['type' => 'cma', 'ref' => 'X-2']
        );
        $this->assertCount(2, $tp->source_chain);

        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '999', 'suburb' => 'Margate'],
            ['type' => 'p24', 'ref' => 'X-3']
        );
        $this->assertCount(3, $tp->source_chain);

        $types = array_column($tp->source_chain, 'type');
        $this->assertSame(['deeds', 'cma', 'p24'], $types);
    }

    // ──────────────────────── promoteToStock ───────────────────────────────

    public function test_promote_to_stock_preserves_audit_chain_and_links(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            [
                'street_number' => '7', 'street_name' => 'Promote Rd',
                'suburb' => 'Margate', 'erf_number' => '4242',
                'last_known_asking_price' => 1500000,
            ],
            ['type' => 'deeds', 'ref' => 'PRM-1']
        );
        // Add a second source so the chain has > 1 entry to preserve.
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['erf_number' => '4242', 'suburb' => 'Margate'],
            ['type' => 'cma', 'ref' => 'PRM-2']
        );
        $chainBefore = $tp->source_chain;
        $this->assertCount(2, $chainBefore);

        $property = $this->service->promoteToStock(
            trackedPropertyId: (int) $tp->id,
            promotingUserId: (int) $this->user->id,
        );

        $this->assertInstanceOf(Property::class, $property);
        $this->assertSame($this->agency->id, (int) $property->agency_id);
        $this->assertSame($this->user->id, (int) $property->agent_id);
        $this->assertSame($this->branch->id, (int) $property->branch_id);

        $tp->refresh();
        $this->assertSame($property->id, (int) $tp->promoted_to_property_id);
        $this->assertNotNull($tp->promoted_at);
        $this->assertSame($this->user->id, (int) $tp->promoted_by_user_id);
        $this->assertSame(TrackedProperty::STATUS_PROMOTED, $tp->status);

        // Audit chain preserved untouched.
        $this->assertEquals($chainBefore, $tp->source_chain);
    }

    public function test_promote_to_stock_is_idempotent(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '1', 'street_name' => 'Once Rd', 'suburb' => 'Margate'],
            ['type' => 'manual', 'ref' => 'IDM-1']
        );

        $p1 = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);
        $p2 = $this->service->promoteToStock((int) $tp->id, (int) $this->user->id);

        $this->assertSame($p1->id, $p2->id, 'Re-promoting must return the same Property');
        $this->assertSame(1, Property::queryWithoutAgencyScope()->count());
    }

    // ──────────────────────── promoteToStock: property-pillar dedup (2026-08-14) ────

    public function test_promote_to_stock_dedups_freehold_by_erf_across_distinct_tracked_properties(): void
    {
        // Two SEPARATE TrackedProperty rows (a fresh re-capture, a differently
        // -matched suspense record, whatever the real-world cause) that both
        // represent the same freehold stand — same erf+suburb. unit_number
        // differs purely to stop strategy=3_erf_suburb correctly deduping
        // them at the TP level too (same erf+suburb legitimately IS one TP
        // identity, so without an artificial guard fact the fixture setup
        // would merge them before this test ever reaches promoteToStock()).
        $tpA = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '10', 'street_name' => 'Erf Rd', 'suburb' => 'Margate', 'erf_number' => '9001', 'unit_number' => 'TPGUARD-A'],
            ['type' => 'deeds', 'ref' => 'ERFDUP-A']
        );
        $tpB = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '10', 'street_name' => 'Erf Rd Renamed', 'suburb' => 'Margate', 'erf_number' => '9001', 'unit_number' => 'TPGUARD-B'],
            ['type' => 'deeds', 'ref' => 'ERFDUP-B-unrelated-ref']
        );
        $this->assertNotSame($tpA->id, $tpB->id, 'sanity: these must be two distinct TrackedProperty rows for this test to prove anything');

        $propertyA = $this->service->promoteToStock((int) $tpA->id, (int) $this->user->id);
        $propertyB = $this->service->promoteToStock((int) $tpB->id, (int) $this->user->id);

        $this->assertSame($propertyA->id, $propertyB->id, 'Same erf+suburb must resolve to ONE canonical Property, not a duplicate');
        $this->assertSame(1, Property::queryWithoutAgencyScope()->count());
    }

    public function test_promote_to_stock_dedups_sectional_by_scheme_and_section(): void
    {
        // scheme_number/section_number aren't in canonicalFactsForWrite()'s
        // whitelist — matchOrCreate() uses them for matching but doesn't
        // persist them itself; the real production caller
        // (DeedsCaptureController::ingestOne()) writes them via a SEPARATE
        // explicit fill()+save() straight after. Replicating that exact
        // real write pattern here rather than relying on matchOrCreate()
        // alone, which would silently leave these columns null.
        // unit_number differs purely to keep the TWO FIXTURE TrackedProperty
        // rows from accidentally merging into each other at TP-match time
        // (same street/suburb/complex_name, so without SOME differing
        // both-sides-populated field, strategy 0/4 would happily match them
        // to each other and this test would end up "proving" TP-level
        // idempotency instead of the property-level dedup it's actually for).
        $tpA = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '20', 'street_name' => 'Scheme Drive', 'suburb' => 'Manaba', 'complex_name' => 'TESTOVE', 'unit_number' => 'TPGUARD-A'],
            ['type' => 'deeds', 'ref' => 'SCHEMEDUP-A']
        );
        $tpA->update(['scheme_number' => '99/2000', 'section_number' => '5']);
        $tpB = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '20', 'street_name' => 'Scheme Drive', 'suburb' => 'Manaba', 'complex_name' => 'TESTOVE', 'unit_number' => 'TPGUARD-B'],
            ['type' => 'deeds', 'ref' => 'SCHEMEDUP-B-unrelated-ref']
        );
        $tpB->update(['scheme_number' => '99/2000', 'section_number' => '5']);
        $this->assertNotSame($tpA->id, $tpB->id, 'sanity: must be two distinct TrackedProperty rows');

        $propertyA = $this->service->promoteToStock((int) $tpA->id, (int) $this->user->id);
        $propertyB = $this->service->promoteToStock((int) $tpB->id, (int) $this->user->id);

        $this->assertSame($propertyA->id, $propertyB->id, 'Same complex+section must resolve to ONE canonical Property');
        $this->assertSame(1, Property::queryWithoutAgencyScope()->count());
    }

    public function test_promote_to_stock_does_not_collapse_different_sectional_units_in_same_scheme(): void
    {
        // Same scheme/street/suburb (they legitimately share these), DIFFERENT
        // section — must NOT dedup. This is the property-pillar equivalent of
        // the numbersConflict() section_number fix for tracked_properties.
        // See the note in the test above re: scheme_number/section_number
        // needing an explicit update() to persist.
        $unit1 = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '30', 'street_name' => 'Astove Ave', 'suburb' => 'Manaba', 'complex_name' => 'ASTOVE'],
            ['type' => 'deeds', 'ref' => 'UNITDIFF-1']
        );
        $unit1->update(['scheme_number' => '470/2000', 'section_number' => '1']);
        $unit2 = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '30', 'street_name' => 'Astove Ave', 'suburb' => 'Manaba',
             'complex_name' => 'ASTOVE', 'scheme_number' => '470/2000', 'section_number' => '2'],
            ['type' => 'deeds', 'ref' => 'UNITDIFF-2']
        );
        $unit2->update(['scheme_number' => '470/2000', 'section_number' => '2']);
        $this->assertNotSame($unit1->id, $unit2->id, 'sanity: must be two distinct TrackedProperty rows (numbersConflict() section veto must have prevented a TP-level merge too)');

        $property1 = $this->service->promoteToStock((int) $unit1->id, (int) $this->user->id);
        $property2 = $this->service->promoteToStock((int) $unit2->id, (int) $this->user->id);

        $this->assertNotSame($property1->id, $property2->id, 'Different sections in the same scheme are DIFFERENT properties');
        $this->assertSame(2, Property::queryWithoutAgencyScope()->count());
    }

    public function test_promote_to_stock_refresh_never_overwrites_relationship_fields(): void
    {
        $tpA = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '5', 'street_name' => 'Guarded Rd', 'suburb' => 'Margate',
             'erf_number' => '7700', 'last_known_asking_price' => 1000000],
            ['type' => 'deeds', 'ref' => 'GUARD-A']
        );
        $property = $this->service->promoteToStock((int) $tpA->id, (int) $this->user->id);

        // Simulate the agent doing real relationship work on the property
        // after promotion — exactly what must survive a later refresh.
        $otherAgent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $property->update([
            'agent_id'     => $otherAgent->id,
            'status'       => 'active',
            'price'        => 2500000,
            'title'        => 'Agent-authored marketing title — do not touch',
            'listing_type' => 'rent',
        ]);

        // A second, distinct TrackedProperty for the SAME erf, with a
        // DIFFERENT property_type/municipal_valuation — simulates a fresh
        // re-capture landing after the agent has already taken ownership.
        // Created via replicate() rather than a second matchOrCreate() call:
        // same erf+suburb legitimately dedups at the TP level too (correct
        // matcher behavior), which would make this SECOND TrackedProperty
        // the SAME row as tpA — already promoted, so promoteToStock() would
        // hit the early-return and never exercise the refresh path this test
        // is actually for.
        $tpB = $tpA->replicate();
        $tpB->source_chain = [];
        $tpB->promoted_to_property_id = null;
        $tpB->promoted_at = null;
        $tpB->promoted_by_user_id = null;
        $tpB->status = TrackedProperty::STATUS_ACTIVE;
        $tpB->property_type = 'townhouse';
        $tpB->municipal_valuation = 999000;
        $tpB->save();
        $this->assertNotSame($tpA->id, $tpB->id, 'sanity: must be two distinct TrackedProperty rows');
        $this->assertFalse($tpB->isPromoted(), 'sanity: tpB must NOT already be promoted, or this test would not exercise the refresh path');

        $refreshed = $this->service->promoteToStock((int) $tpB->id, (int) $this->user->id);

        $this->assertSame($property->id, $refreshed->id, 'must resolve to the same canonical Property');
        $this->assertSame(1, Property::queryWithoutAgencyScope()->count());

        $refreshed->refresh();
        // Relationship/listing-decision fields: untouched.
        $this->assertSame($otherAgent->id, (int) $refreshed->agent_id, 'agent_id must never be overwritten on refresh');
        $this->assertSame('active', $refreshed->status, 'status must never be overwritten on refresh');
        $this->assertSame(2500000.0, (float) $refreshed->price, 'price must never be overwritten on refresh');
        $this->assertSame('Agent-authored marketing title — do not touch', $refreshed->title, 'title must never be overwritten on refresh');
        $this->assertSame('rent', $refreshed->listing_type, 'listing_type must never be overwritten on refresh');
        // Physical facts: DID refresh.
        $this->assertSame('townhouse', $refreshed->property_type, 'property_type IS a physical fact and must refresh');
        $this->assertSame(999000.0, (float) $refreshed->municipal_valuation, 'municipal_valuation IS a physical fact and must refresh');
    }

    public function test_promote_to_stock_normalised_address_fallback_dedups_when_no_erf_or_scheme(): void
    {
        $tpA = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '42', 'street_name' => 'No Erf Street', 'suburb' => 'Ramsgate'],
            ['type' => 'deeds', 'ref' => 'NOERF-A']
        );

        // tpB is created DIRECTLY (bypassing matchOrCreate()) rather than via
        // a second matchOrCreate() call with an artificial discriminator —
        // any fact that differs enough to stop the TP-level matcher merging
        // these two (identical address, no erf/scheme) would ALSO be exactly
        // the kind of fact resolvePropertyMatch()'s address-fallback conflict
        // guard checks, defeating this specific test. Simulates the real
        // scenario this feature targets: a pre-existing residual duplicate TP
        // (from before a matcher fix, a race, whatever) that the CURRENT
        // matcher wouldn't create fresh today, but that still exists in the
        // data and must be handled gracefully at the property layer.
        $tpB = $tpA->replicate();
        $tpB->source_chain = [];
        $tpB->save();
        $this->assertNotSame($tpA->id, $tpB->id, 'sanity: must be two distinct TrackedProperty rows');

        $propertyA = $this->service->promoteToStock((int) $tpA->id, (int) $this->user->id);
        $propertyB = $this->service->promoteToStock((int) $tpB->id, (int) $this->user->id);

        $this->assertSame($propertyA->id, $propertyB->id, 'normalised address+suburb must dedup when neither erf nor scheme data exists');
        $this->assertSame(1, Property::queryWithoutAgencyScope()->count());
    }

    public function test_promote_to_stock_address_fallback_does_not_collapse_different_units_without_scheme_data(): void
    {
        // Same street address, no erf/scheme data at all, but DIFFERENT
        // unit_number — must not collapse via the address-fallback branch.
        $tpA = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '8', 'street_name' => 'Shared Address Way', 'suburb' => 'Uvongo', 'unit_number' => '1'],
            ['type' => 'deeds', 'ref' => 'NOSCHEMEUNIT-A']
        );
        $tpB = $this->service->matchOrCreate(
            $this->agency->id,
            ['street_number' => '8', 'street_name' => 'Shared Address Way', 'suburb' => 'Uvongo', 'unit_number' => '2'],
            ['type' => 'deeds', 'ref' => 'NOSCHEMEUNIT-B']
        );
        $this->assertNotSame($tpA->id, $tpB->id, 'sanity: differing unit_number must have prevented a TP-level merge too');

        $propertyA = $this->service->promoteToStock((int) $tpA->id, (int) $this->user->id);
        $propertyB = $this->service->promoteToStock((int) $tpB->id, (int) $this->user->id);

        $this->assertNotSame($propertyA->id, $propertyB->id, 'different unit_number at the same bare address must NOT collapse');
        $this->assertSame(2, Property::queryWithoutAgencyScope()->count());
    }

    public function test_external_ref_is_written_on_create(): void
    {
        $tp = $this->service->matchOrCreate(
            $this->agency->id,
            ['suburb' => 'Margate'],
            ['type' => 'p24', 'ref' => 'EXT-1', 'payload' => ['raw' => 'json']]
        );

        $ref = TrackedPropertyExternalRef::queryWithoutAgencyScope()
            ->where('source_type', 'p24')
            ->where('source_ref', 'EXT-1')
            ->first();

        $this->assertNotNull($ref);
        $this->assertSame($tp->id, (int) $ref->tracked_property_id);
        $this->assertSame($this->agency->id, (int) $ref->agency_id);
    }
}

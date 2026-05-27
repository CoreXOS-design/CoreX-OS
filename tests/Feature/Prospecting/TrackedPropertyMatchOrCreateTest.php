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

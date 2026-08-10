<?php

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Importer speedup (2026-08-10) — proves matchOrCreate()'s new batched
 * (BatchMatchCache-backed) resolution path produces IDENTICAL match/create
 * decisions to the original per-listing path.
 *
 * The fixture exercises every strategy the batch cache touches (1, 3, 4, 5)
 * plus the two riskiest correctness edges of batching itself:
 *   - a repeat ref appearing twice in the SAME batch (item 0 and item 8),
 *   - a listing matching a TrackedProperty CREATED by an EARLIER listing in
 *     the SAME batch, before any fresh DB query would normally see it
 *     (item 6 creates, item 7 matches it via erf+suburb) — this only works
 *     if the cache is write-through, not just a one-time preload.
 *
 * Runs the SAME fixture twice — once with no cache (original resolveMatch()
 * path), once with a primeCacheForBatch()-primed cache (new
 * resolveMatchWithCache() path) — against independently seeded-but-identical
 * starting states, and asserts the two runs produce the same matching
 * PATTERN (which listings resolve onto the same TrackedProperty) and the
 * same final field values. Raw auto-increment ids legitimately differ
 * between the two runs (DELETE does not reset MySQL's AUTO_INCREMENT
 * counter), so the comparison is via normalised group signatures, not
 * literal id equality.
 */
class TrackedPropertyBatchCacheParityTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private TrackedPropertyMatchOrCreateService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Agency', 'slug' => 'agency']);
        $this->service = new TrackedPropertyMatchOrCreateService();
    }

    public function test_batched_resolution_matches_the_original_per_listing_resolution(): void
    {
        $oldRun = $this->runFixture(useCache: false);
        $this->resetTrackedPropertyTables();
        $newRun = $this->runFixture(useCache: true);

        $this->assertSame(
            $oldRun['groups'],
            $newRun['groups'],
            'Batched matching must group listings onto the same TrackedProperty as the original per-listing matching.'
        );
        $this->assertSame($oldRun['created_count'], $newRun['created_count'], 'Same number of TrackedProperty rows must be created.');
        $this->assertSame($oldRun['field_snapshots'], $newRun['field_snapshots'], 'Final field values must be identical.');

        // Sanity: the fixture is meant to hit strategies 1, 3, 4 and 5, plus
        // a create. If the grouping collapsed to "everything is one TP" or
        // "nothing ever matches", the test would still pass on a broken
        // implementation vacuously — pin the expected shape explicitly.
        $this->assertSame([0, 0, 0, 0, 1, 2, 3, 3, 0, 4, 1], $oldRun['groups']);
        $this->assertSame(5, $oldRun['created_count']);
    }

    /**
     * Seeds an identical "prior day" state, then processes the SAME fixture
     * of batch items either via the original per-listing path (no cache) or
     * the new batched path (primed BatchMatchCache), returning a normalised
     * view of the outcome.
     */
    private function runFixture(bool $useCache): array
    {
        // Prior-day seed — always via the plain, uncached call. Three TPs
        // across three suburbs, one with no erf_number (Ramsgate), so the
        // batch below can exercise strategy 3 (erf) and strategy 4 (address)
        // against genuinely different pre-existing rows.
        $this->service->matchOrCreate($this->agency->id, [
            'street_number' => '10', 'street_name' => 'Sandpiper Avenue',
            'suburb' => 'Margate', 'erf_number' => '1001',
            'last_known_asking_price' => 1000000,
        ], ['type' => 'p24', 'ref' => 'p24-M1']);

        // Deliberately no street_number/street_name on the canonical TP row —
        // numbersConflict() gates Strategy 0 too (correctly — it's a hard
        // discriminator against collapsing two genuinely different numbered
        // properties), so a corrected address with a DIFFERENT street number
        // than an already-set canonical one would legitimately veto. Erf-only
        // here isolates the Strategy 0 fixture below from that gate, matching
        // the realistic "portal never gave us a usable street" starting point.
        $tpU1 = $this->service->matchOrCreate($this->agency->id, [
            'suburb' => 'Uvongo', 'erf_number' => '2001',
        ], ['type' => 'p24', 'ref' => 'p24-U1']);

        $this->service->matchOrCreate($this->agency->id, [
            'street_number' => '20', 'street_name' => 'Marine Drive',
            'suburb' => 'Ramsgate',
        ], ['type' => 'p24', 'ref' => 'p24-R1']);

        // Strategy 0 fixture — an agent has corrected TP-U1's address once
        // (verified confidence). A future portal ingestion of a DIFFERENT,
        // unrelated-looking ref for that exact corrected street/suburb must
        // resolve to TP-U1 via Strategy 0, ahead of (and instead of)
        // strategies 1-5 — the "silent killer" fix this strategy exists for.
        TrackedPropertyAddress::create([
            'agency_id'           => $this->agency->id,
            'tracked_property_id' => $tpU1->id,
            'street_number'       => '99',
            'street_name'         => TrackedPropertyAddress::normaliseStreet('Corrected Close'),
            'suburb'              => 'Uvongo',
            'suburb_normalised'   => TrackedPropertyAddress::normaliseSuburb('Uvongo'),
            'source_type'         => 'manual_agent',
            'confidence'          => TrackedPropertyAddress::CONFIDENCE_VERIFIED,
            'is_primary'          => false,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
        ]);

        $items = [
            // 0: repeat of p24-M1 → strategy 1 (source-ref).
            ['facts' => ['suburb' => 'Margate'], 'source' => ['type' => 'p24', 'ref' => 'p24-M1']],
            // 1: new ref, same erf+suburb as the Margate TP → strategy 3, plus a price update.
            ['facts' => ['erf_number' => '1001', 'suburb' => 'Margate', 'last_known_asking_price' => 1250000], 'source' => ['type' => 'p24', 'ref' => 'p24-M2-NEW']],
            // 2: new ref, same address different casing/abbreviation → strategy 4.
            ['facts' => ['street_number' => '10', 'street_name' => 'SANDPIPER AVE', 'suburb' => 'Margate'], 'source' => ['type' => 'p24', 'ref' => 'p24-M3-NEW']],
            // 3: new ref, loose free-text address → strategy 5 (token overlap).
            ['facts' => ['suburb' => 'Margate', 'address' => '10 Sandpiper Avenue, Beachfront'], 'source' => ['type' => 'p24', 'ref' => 'p24-M4-NEW']],
            // 4: new ref, same erf+suburb as the Uvongo TP → strategy 3.
            ['facts' => ['erf_number' => '2001', 'suburb' => 'Uvongo'], 'source' => ['type' => 'p24', 'ref' => 'p24-U2-NEW']],
            // 5: new ref, address abbreviation of the Ramsgate TP (no erf on that TP) → strategy 4.
            ['facts' => ['street_number' => '20', 'street_name' => 'Marine Dr', 'suburb' => 'Ramsgate'], 'source' => ['type' => 'p24', 'ref' => 'p24-R2-NEW']],
            // 6: brand-new suburb, no existing match anywhere → CREATE.
            ['facts' => ['street_number' => '3', 'street_name' => 'Palm Street', 'suburb' => 'Shelly Beach', 'erf_number' => '3001'], 'source' => ['type' => 'p24', 'ref' => 'p24-S1-NEW']],
            // 7: same erf as item 6's TP — created earlier in THIS batch → strategy 3, proves mid-batch write-through.
            ['facts' => ['erf_number' => '3001', 'suburb' => 'Shelly Beach'], 'source' => ['type' => 'p24', 'ref' => 'p24-S2-NEW']],
            // 8: repeat of p24-M1 again, later in the same batch → strategy 1.
            ['facts' => ['suburb' => 'Margate'], 'source' => ['type' => 'p24', 'ref' => 'p24-M1']],
            // 9: unrelated to everything → CREATE, distinct new TP.
            ['facts' => ['street_number' => '1', 'street_name' => 'Nowhere Close', 'suburb' => 'Southbroom'], 'source' => ['type' => 'p24', 'ref' => 'p24-X1-NEW']],
            // 10: matches TP-U1's AGENT-CORRECTED address, not its original erf/street → strategy 0 (Match A), must win over strategies 1-5.
            ['facts' => ['street_number' => '99', 'street_name' => 'Corrected Close', 'suburb' => 'Uvongo'], 'source' => ['type' => 'p24', 'ref' => 'p24-U3-NEW']],
        ];

        $cache = $useCache
            ? $this->service->primeCacheForBatch($this->agency->id, $items)
            : null;

        // Snapshot each item's id AND field values immediately after ITS OWN
        // matchOrCreate() call — not in a second pass over stored objects at
        // the end. The batched path's cache intentionally shares live object
        // references (rememberTp() write-through) so a LATER item's enrich()
        // can legitimately mutate the SAME PHP object an EARLIER item's
        // result variable points to (Eloquent's update() mutates $this in
        // place before returning fresh()). That is correct production
        // behaviour — the DB ends up right either way — but it means reading
        // attributes off a held reference after the whole loop reflects the
        // object's state at read-time, not at that item's own processing
        // time, in the cached run only. Snapshotting inline avoids that and
        // is what actually proves per-item parity.
        $idResults = [];
        $fieldSnapshots = [];
        foreach ($items as $i => $item) {
            $tp = $this->service->matchOrCreate(
                $this->agency->id,
                $item['facts'],
                $item['source'],
                cache: $cache,
            );
            $idResults[$i] = $tp->id;
            $fieldSnapshots[$i] = [
                'erf_number'              => $tp->erf_number,
                'street_number'           => $tp->street_number,
                'street_name'             => $tp->street_name,
                'suburb_normalised'       => $tp->suburb_normalised,
                'last_known_asking_price' => $tp->last_known_asking_price !== null ? (float) $tp->last_known_asking_price : null,
                'source_chain_count'      => count($tp->source_chain ?? []),
            ];
        }

        // Normalise: map each result's real id to a per-run sequential group
        // number in first-seen order. Comparable across two separately
        // seeded runs whose raw auto-increment ids differ.
        $groupOf = [];
        $nextGroup = 0;
        $groups = [];
        foreach ($idResults as $id) {
            if (! isset($groupOf[$id])) {
                $groupOf[$id] = $nextGroup++;
            }
            $groups[] = $groupOf[$id];
        }

        return [
            'groups'          => $groups,
            'created_count'   => TrackedProperty::queryWithoutAgencyScope()->where('agency_id', $this->agency->id)->count(),
            'field_snapshots' => $fieldSnapshots,
        ];
    }

    private function resetTrackedPropertyTables(): void
    {
        DB::table('tracked_property_external_refs')->delete();
        DB::table('tracked_property_addresses')->delete();
        DB::table('tracked_properties')->delete();
    }
}

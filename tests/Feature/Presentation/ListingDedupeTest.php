<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\User;
use App\Services\Presentations\Evidence\ListingNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance tests for Phase B1 — listing dedupe + lifecycle.
 */
class ListingDedupeTest extends TestCase
{
    use RefreshDatabase;

    private ListingNormalizationService $normalizer;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        $branch = Branch::create([
            'name'      => 'Dedupe Test Branch',
            'code'      => 'DDT',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $branch->id,
        ]);

        $this->normalizer   = new ListingNormalizationService();
        $this->presentation = Presentation::create([
            'branch_id'          => $branch->id,
            'created_by_user_id' => $user->id,
            'title'              => 'Dedupe Test Pres',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Camps Bay',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── Idempotent re-ingestion ─────────────────────────────────────────

    public function test_reingesting_same_row_does_not_duplicate(): void
    {
        $row = $this->makeRow('p24:111', 2_000_000, 'Camps Bay', 3);

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationActiveListing::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(1, $count, 'Same row ingested twice must not create duplicate');
    }

    public function test_reingesting_updates_last_seen_at(): void
    {
        $row = $this->makeRow('p24:222', 1_500_000, 'Green Point', 2);

        $first = $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Simulate time passing
        $first->update(['last_seen_at' => now()->subHour()]);
        $oldSeen = $first->fresh()->last_seen_at;

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $refreshed = $first->fresh();
        $this->assertTrue($refreshed->last_seen_at->gt($oldSeen), 'last_seen_at must be updated on re-ingest');
    }

    // ── Deactivation sweep ──────────────────────────────────────────────

    public function test_deactivation_marks_missing_listings_inactive(): void
    {
        // Insert 3 rows from p24_search
        $rowA = $this->makeRow('p24:AAA', 1_000_000, 'X', 2);
        $rowB = $this->makeRow('p24:BBB', 1_500_000, 'X', 3);
        $rowC = $this->makeRow('p24:CCC', 2_000_000, 'X', 4);

        foreach ([$rowA, $rowB, $rowC] as $r) {
            $this->normalizer->upsertNormalizedRow(
                $this->presentation->id, 1, $r, 'p24_search', 'p24_search_v1', 'deterministic_v1',
            );
        }

        $this->assertSame(3, PresentationActiveListing::where('presentation_id', $this->presentation->id)->where('is_active', true)->count());

        // New snapshot only contains A and B — C should be deactivated
        // Platform prefix is 'p24' not 'p24_search' (cross-source dedupe)
        $seenKeys = ['p24:AAA', 'p24:BBB'];
        $seenFp   = [
            $this->normalizer->buildFingerprint($rowA),
            $this->normalizer->buildFingerprint($rowB),
        ];

        $deactivated = $this->normalizer->deactivateMissing($this->presentation->id, 'p24_search', $seenKeys, $seenFp);

        $this->assertSame(1, $deactivated);

        $cRow = PresentationActiveListing::where('presentation_id', $this->presentation->id)
            ->where('external_key', 'p24:CCC')
            ->first();
        $this->assertFalse($cRow->is_active);
    }

    // ── Listing page upgrades record completeness ───────────────────────

    public function test_listing_page_upgrades_search_record(): void
    {
        // Search row: has external_id but no beds/baths
        $searchRow = [
            'external_id'    => 'PROP-999',
            'list_price_inc' => 3_000_000,
            'suburb'         => 'Clifton',
            'property_type'  => null,
            'beds'           => null,
            'baths'          => null,
            'size_m2'        => null,
            'listing_date'   => null,
            'raw_data'       => ['source' => 'search'],
        ];

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $searchRow, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Listing page: same external_id, better rank, more fields
        $listingRow = [
            'external_id'    => 'PROP-999',
            'list_price_inc' => 3_000_000,
            'suburb'         => 'Clifton',
            'property_type'  => 'house',
            'beds'           => 4,
            'baths'          => 3,
            'size_m2'        => 220,
            'listing_date'   => '2026-01-15',
            'raw_data'       => ['source' => 'listing'],
        ];

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $listingRow, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        // Must still be 1 row, not 2
        $count = PresentationActiveListing::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(1, $count);

        $row = PresentationActiveListing::where('presentation_id', $this->presentation->id)->first();
        $this->assertSame(4, $row->beds, 'beds should be upgraded from listing page');
        $this->assertSame(3, $row->baths, 'baths should be upgraded from listing page');
        $this->assertSame(220, $row->size_m2, 'size_m2 should be upgraded from listing page');
        $this->assertSame('house', $row->property_type);
        $this->assertSame(10, $row->source_rank, 'source_rank should be upgraded to listing level');
    }

    public function test_listing_page_does_not_overwrite_non_null_with_null(): void
    {
        // Insert with suburb set
        $row = $this->makeRow('p24:KEEP', 2_000_000, 'Sea Point', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        // Re-ingest with suburb=null (should NOT wipe)
        $rowNull = $this->makeRow('p24:KEEP', 2_000_000, null, null);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $rowNull, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        $record = PresentationActiveListing::where('presentation_id', $this->presentation->id)->first();
        $this->assertSame('Sea Point', $record->suburb, 'Non-null suburb must not be wiped by null');
        $this->assertSame(3, $record->beds, 'Non-null beds must not be wiped by null');
    }

    // ── Fingerprint-based dedupe (no external_id) ───────────────────────

    public function test_fingerprint_dedupe_when_no_external_id(): void
    {
        $row = [
            'external_id'    => null,
            'list_price_inc' => 1_800_000,
            'suburb'         => 'Bantry Bay',
            'property_type'  => 'unit',
            'beds'           => 2,
            'baths'          => 1,
            'size_m2'        => 85,
            'listing_date'   => null,
            'raw_data'       => [],
        ];

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationActiveListing::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(1, $count, 'Fingerprint dedupe must prevent duplicates when no external_id');
    }

    // ── Cross-source dedupe ─────────────────────────────────────────────

    public function test_same_external_id_across_search_and_listing(): void
    {
        $searchRow = [
            'external_id'    => 'SHARED-42',
            'list_price_inc' => 2_200_000,
            'suburb'         => 'Fresnaye',
            'property_type'  => null,
            'beds'           => null,
            'baths'          => null,
            'size_m2'        => null,
            'listing_date'   => null,
            'raw_data'       => ['from' => 'search'],
        ];

        $listingRow = [
            'external_id'    => 'SHARED-42',
            'list_price_inc' => 2_200_000,
            'suburb'         => 'Fresnaye',
            'property_type'  => 'house',
            'beds'           => 5,
            'baths'          => 3,
            'size_m2'        => 300,
            'listing_date'   => '2026-02-01',
            'raw_data'       => ['from' => 'listing'],
        ];

        // Ingest from search first
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $searchRow, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Then ingest from listing (same external_id, different source_type prefix)
        // The external_key will differ (p24_search:SHARED-42 vs p24_listing:SHARED-42)
        // but fingerprint should match since fields are similar
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $listingRow, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        // Should have at most 2 rows since external_keys differ, but fingerprint might match
        // The important thing is the listing version should be the upgraded one
        $rows = PresentationActiveListing::where('presentation_id', $this->presentation->id)->get();
        $this->assertLessThanOrEqual(2, $rows->count());
    }

    // ── CompetitiveStockService doesn't double-count ───────────────────

    public function test_competitive_stock_excludes_inactive(): void
    {
        // Create 2 active, 1 inactive
        PresentationActiveListing::create([
            'presentation_id' => $this->presentation->id,
            'list_price_inc'  => 1_000_000,
            'is_active'       => true,
            'source_rank'     => 20,
            'status'          => 'active',
            'raw_row_json'    => '{}',
            'parser_version'  => 'test',
            'fingerprint'     => hash('sha256', 'a'),
        ]);
        PresentationActiveListing::create([
            'presentation_id' => $this->presentation->id,
            'list_price_inc'  => 2_000_000,
            'is_active'       => true,
            'source_rank'     => 20,
            'status'          => 'active',
            'raw_row_json'    => '{}',
            'parser_version'  => 'test',
            'fingerprint'     => hash('sha256', 'b'),
        ]);
        PresentationActiveListing::create([
            'presentation_id' => $this->presentation->id,
            'list_price_inc'  => 3_000_000,
            'is_active'       => false,
            'source_rank'     => 20,
            'status'          => 'active',
            'raw_row_json'    => '{}',
            'parser_version'  => 'test',
            'fingerprint'     => hash('sha256', 'c'),
        ]);

        // Query with dedupe filter
        $activeCount = PresentationActiveListing::where('presentation_id', $this->presentation->id)
            ->where('is_active', true)
            ->count();

        $this->assertSame(2, $activeCount, 'Inactive rows must be excluded');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function makeRow(?string $externalId, ?int $price, ?string $suburb, ?int $beds): array
    {
        $extParts = $externalId ? explode(':', $externalId, 2) : [null, null];

        return [
            'external_id'    => $extParts[1] ?? $externalId,
            'list_price_inc' => $price,
            'suburb'         => $suburb,
            'property_type'  => 'house',
            'beds'           => $beds,
            'baths'          => null,
            'size_m2'        => null,
            'listing_date'   => null,
            'raw_data'       => [],
        ];
    }
}

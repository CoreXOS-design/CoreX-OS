<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\User;
use App\Services\Presentations\Evidence\ListingNormalizationService;
use App\Services\Presentations\PresentationDataQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance tests for Phase B3 — merge intelligence + data quality scoring.
 */
class ListingDataQualityTest extends TestCase
{
    use RefreshDatabase;

    private ListingNormalizationService $normalizer;
    private PresentationDataQualityService $qualityService;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'features.listing_lifecycle_v1'    => true,
            'features.listing_data_quality_v1' => true,
        ]);

        $branch = Branch::create([
            'name'      => 'DQ Test Branch',
            'code'      => 'DQT',
            'is_active' => true,
        ]);

        $user = User::factory()->create([
            'role'      => 'agent',
            'branch_id' => $branch->id,
        ]);

        $this->normalizer     = new ListingNormalizationService();
        $this->qualityService = new PresentationDataQualityService();
        $this->presentation   = Presentation::create([
            'branch_id'          => $branch->id,
            'created_by_user_id' => $user->id,
            'title'              => 'DQ Test Pres',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Camps Bay',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── Merge confidence ────────────────────────────────────────────────

    public function test_price_conflict_reduces_merge_confidence(): void
    {
        $row1 = $this->makeRow('p24:DQ1', 2_000_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row1, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Same listing, different price via listing page
        $row2 = $this->makeRow('p24:DQ1', 1_800_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row2, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        $listing = PresentationActiveListing::where('presentation_id', $this->presentation->id)
            ->where('external_key', 'p24:DQ1')
            ->first();

        $this->assertNotNull($listing->merge_confidence);
        $this->assertLessThan(100, $listing->merge_confidence, 'Price conflict should reduce merge confidence');
        $this->assertSame(80, $listing->merge_confidence, 'Price conflict = -20, so confidence = 80');

        // Check conflict flags
        $flags = $listing->conflict_flags_json;
        $this->assertIsArray($flags);
        $this->assertTrue($flags['price_conflict']);
    }

    public function test_no_conflict_merge_keeps_high_confidence(): void
    {
        $row1 = $this->makeRow('p24:DQ2', 2_000_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row1, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Same listing, same data
        $row2 = $this->makeRow('p24:DQ2', 2_000_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row2, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        $listing = PresentationActiveListing::where('presentation_id', $this->presentation->id)
            ->where('external_key', 'p24:DQ2')
            ->first();

        $this->assertSame(100, $listing->merge_confidence);
    }

    // ── Data quality score ──────────────────────────────────────────────

    public function test_missing_fields_reduce_data_quality_score(): void
    {
        // Row with only price (no beds, baths, size) → lower score
        $row = $this->makeRow('p24:DQ3', 2_000_000, null, null);
        $listing = $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $this->assertNotNull($listing->data_quality_score);
        // price (+20) + external_key (+10) = 30
        $this->assertSame(30, $listing->data_quality_score);
    }

    public function test_complete_fields_give_high_data_quality(): void
    {
        $row = [
            'external_id'    => 'FULL-1',
            'list_price_inc' => 2_000_000,
            'suburb'         => 'Camps Bay',
            'property_type'  => 'house',
            'beds'           => 3,
            'baths'          => 2,
            'size_m2'        => 150,
            'listing_date'   => null,
            'raw_data'       => [],
        ];

        $listing = $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_listing', 'p24_listing_v1', 'deterministic_v1',
        );

        $this->assertNotNull($listing->data_quality_score);
        // price(+20) + beds(+15) + baths(+15) + size(+20) + external_key(+10) = 80
        $this->assertSame(80, $listing->data_quality_score);
    }

    // ── Feature flag disables scoring ────────────────────────────────

    public function test_no_scoring_when_feature_disabled(): void
    {
        config(['features.listing_data_quality_v1' => false]);

        $row = $this->makeRow('p24:DQ5', 2_000_000, 'Camps Bay', 3);
        $listing = $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $this->assertNull($listing->data_quality_score);
        $this->assertNull($listing->merge_confidence);
    }

    // ── Dataset quality evaluation ──────────────────────────────────

    public function test_dataset_grade_deterministic(): void
    {
        $this->createScoredListing(80, 90);
        $this->createScoredListing(85, 100);
        $this->createScoredListing(75, 70);

        $evalA = $this->qualityService->evaluate($this->presentation->id);
        $evalB = $this->qualityService->evaluate($this->presentation->id);

        $this->assertSame($evalA, $evalB, 'Dataset grade must be deterministic');
    }

    public function test_dataset_evaluation_returns_all_keys(): void
    {
        $this->createScoredListing(80, 90);

        $eval = $this->qualityService->evaluate($this->presentation->id);

        $this->assertArrayHasKey('avg_merge_confidence', $eval);
        $this->assertArrayHasKey('avg_data_quality_score', $eval);
        $this->assertArrayHasKey('low_confidence_percentage', $eval);
        $this->assertArrayHasKey('conflict_listing_count', $eval);
        $this->assertArrayHasKey('overall_grade', $eval);
    }

    public function test_dataset_evaluation_empty_returns_nulls(): void
    {
        $eval = $this->qualityService->evaluate($this->presentation->id);

        $this->assertNull($eval['avg_merge_confidence']);
        $this->assertNull($eval['avg_data_quality_score']);
        $this->assertNull($eval['low_confidence_percentage']);
        $this->assertSame(0, $eval['conflict_listing_count']);
        $this->assertNull($eval['overall_grade']);
    }

    public function test_low_confidence_percentage(): void
    {
        // 2 of 4 with confidence < 60 = 50%
        $this->createScoredListing(80, 40);
        $this->createScoredListing(80, 55);
        $this->createScoredListing(80, 80);
        $this->createScoredListing(80, 90);

        $eval = $this->qualityService->evaluate($this->presentation->id);
        $this->assertSame(50.0, $eval['low_confidence_percentage']);
    }

    public function test_conflict_listing_count(): void
    {
        $this->createScoredListing(80, 100, ['price_conflict' => true, 'beds_conflict' => false]);
        $this->createScoredListing(80, 100, ['price_conflict' => false, 'beds_conflict' => false]);
        $this->createScoredListing(80, 100, ['price_conflict' => true, 'beds_conflict' => true]);

        $eval = $this->qualityService->evaluate($this->presentation->id);
        $this->assertSame(2, $eval['conflict_listing_count']);
    }

    public function test_overall_grade_calculation(): void
    {
        // All scores = 90 → avg = 90 → grade A
        $this->createScoredListing(90, 100);
        $this->createScoredListing(90, 100);

        $eval = $this->qualityService->evaluate($this->presentation->id);
        $this->assertSame('A', $eval['overall_grade']);
    }

    // ── CompetitiveStock data_quality block ──────────────────────────

    public function test_competitive_stock_contains_data_quality_block(): void
    {
        $this->createScoredListing(80, 90);
        $this->createScoredListing(70, 85);

        $stockService = new \App\Services\MarketAnalytics\CompetitiveStockService(
            null,
            $this->qualityService,
        );

        $result = $stockService->analyzeWithLifecycle(
            [['list_price_inc' => 2_000_000], ['list_price_inc' => 1_500_000]],
            null,
            12.0,
            $this->presentation->id,
        );

        $this->assertArrayHasKey('data_quality', $result);
        $this->assertArrayHasKey('avg_score', $result['data_quality']);
        $this->assertArrayHasKey('avg_merge_confidence', $result['data_quality']);
        $this->assertArrayHasKey('conflict_listing_count', $result['data_quality']);

        // Standard keys still present
        $this->assertArrayHasKey('total_active_stock', $result);
        $this->assertArrayHasKey('median_price', $result);
    }

    public function test_competitive_stock_no_data_quality_when_disabled(): void
    {
        config(['features.listing_data_quality_v1' => false]);

        $stockService = new \App\Services\MarketAnalytics\CompetitiveStockService(
            null,
            $this->qualityService,
        );

        $result = $stockService->analyzeWithLifecycle(
            [['list_price_inc' => 2_000_000]],
            null,
            12.0,
            $this->presentation->id,
        );

        $this->assertArrayNotHasKey('data_quality', $result);
        $this->assertArrayHasKey('total_active_stock', $result);
    }

    // ── Helpers ──────────────────────────────────────────────────────

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

    private function createScoredListing(
        int $qualityScore,
        int $mergeConfidence,
        ?array $conflictFlags = null,
    ): PresentationActiveListing {
        return PresentationActiveListing::create([
            'presentation_id'    => $this->presentation->id,
            'list_price_inc'     => 2_000_000,
            'external_key'       => 'p24:' . uniqid(),
            'fingerprint'        => hash('sha256', uniqid()),
            'is_active'          => true,
            'source_rank'        => 20,
            'status'             => 'active',
            'raw_row_json'       => '{}',
            'parser_version'     => 'test',
            'first_seen_at'      => now(),
            'last_seen_at'       => now(),
            'data_quality_score' => $qualityScore,
            'merge_confidence'   => $mergeConfidence,
            'conflict_flags_json' => $conflictFlags,
        ]);
    }
}

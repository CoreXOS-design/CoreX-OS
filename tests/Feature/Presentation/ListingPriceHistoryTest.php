<?php

namespace Tests\Feature\Presentation;

use App\Models\Branch;
use App\Models\Presentation;
use App\Models\PresentationActiveListing;
use App\Models\PresentationListingPriceHistory;
use App\Models\User;
use App\Services\Presentations\Evidence\ListingLifecycleService;
use App\Services\Presentations\Evidence\ListingNormalizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acceptance tests for Phase B2 — listing lifecycle + price history.
 */
class ListingPriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private ListingNormalizationService $normalizer;
    private Presentation $presentation;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable the feature flag for these tests
        config(['features.listing_lifecycle_v1' => true]);

        $branch = Branch::create([
            'name'      => 'Lifecycle Test Branch',
            'code'      => 'LCT',
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
            'title'              => 'Lifecycle Test Pres',
            'property_address'   => '1 Test Street',
            'suburb'             => 'Camps Bay',
            'property_type'      => 'house',
            'status'             => 'draft',
            'currency'           => 'ZAR',
        ]);
    }

    // ── Price change creates history row ─────────────────────────────

    public function test_initial_insert_creates_price_history(): void
    {
        $row = $this->makeRow('p24:PH1', 2_000_000, 'Camps Bay', 3);

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(1, $count, 'Initial insert should create a price history entry');

        $history = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)->first();
        $this->assertSame(2_000_000, $history->price_inc);
    }

    public function test_price_change_creates_new_history_row(): void
    {
        $row1 = $this->makeRow('p24:PH2', 2_000_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row1, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        // Re-ingest with different price
        $row2 = $this->makeRow('p24:PH2', 1_800_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row2, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(2, $count, 'Price change must create a new history row');

        $prices = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)
            ->orderBy('captured_at')
            ->pluck('price_inc')
            ->toArray();

        $this->assertSame([2_000_000, 1_800_000], $prices);
    }

    public function test_same_price_does_not_create_duplicate_history(): void
    {
        $row = $this->makeRow('p24:PH3', 2_500_000, 'Camps Bay', 3);

        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 2, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(1, $count, 'Same price should not create duplicate history');
    }

    // ── Feature flag disables history ───────────────────────────────

    public function test_no_history_when_feature_disabled(): void
    {
        config(['features.listing_lifecycle_v1' => false]);

        $row = $this->makeRow('p24:PH4', 3_000_000, 'Camps Bay', 3);
        $this->normalizer->upsertNormalizedRow(
            $this->presentation->id, 1, $row, 'p24_search', 'p24_search_v1', 'deterministic_v1',
        );

        $count = PresentationListingPriceHistory::where('presentation_id', $this->presentation->id)->count();
        $this->assertSame(0, $count, 'Price history must not be written when feature is disabled');
    }

    // ── Churn metrics ───────────────────────────────────────────────

    public function test_churn_metrics_deterministic(): void
    {
        $lifecycleService = new ListingLifecycleService();

        // Create active listings with known first_seen_at
        $this->createListing('p24:CH1', 2_000_000, '2026-01-01', true);
        $this->createListing('p24:CH2', 1_500_000, '2025-12-01', true);
        $this->createListing('p24:CH3', 3_000_000, '2026-02-01', true);

        $metricsA = $lifecycleService->calculateChurnMetrics($this->presentation->id);
        $metricsB = $lifecycleService->calculateChurnMetrics($this->presentation->id);

        $this->assertSame($metricsA, $metricsB, 'Churn metrics must be deterministic');
        $this->assertArrayHasKey('average_dom', $metricsA);
        $this->assertArrayHasKey('median_dom', $metricsA);
        $this->assertArrayHasKey('stale_percentage', $metricsA);
        $this->assertArrayHasKey('price_reduction_percentage', $metricsA);
        $this->assertArrayHasKey('avg_price_drop_percent', $metricsA);
    }

    public function test_churn_metrics_empty_returns_nulls(): void
    {
        $lifecycleService = new ListingLifecycleService();
        $metrics = $lifecycleService->calculateChurnMetrics($this->presentation->id);

        $this->assertNull($metrics['average_dom']);
        $this->assertNull($metrics['median_dom']);
        $this->assertNull($metrics['stale_percentage']);
        $this->assertNull($metrics['price_reduction_percentage']);
        $this->assertNull($metrics['avg_price_drop_percent']);
    }

    // ── DOM calculation via feature test ─────────────────────────────

    public function test_dom_correct_for_active_listing(): void
    {
        $lifecycleService = new ListingLifecycleService();

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-02-20'));

        $listing = $this->createListing('p24:DOM1', 2_000_000, '2026-01-21', true);
        $dom = $lifecycleService->calculateDom($listing);

        $this->assertSame(30, $dom);

        \Carbon\Carbon::setTestNow();
    }

    public function test_dom_correct_for_inactive_listing(): void
    {
        $lifecycleService = new ListingLifecycleService();

        $listing = $this->createListing('p24:DOM2', 2_000_000, '2026-01-01', false, '2026-02-10');
        $dom = $lifecycleService->calculateDom($listing);

        $this->assertSame(40, $dom);
    }

    // ── Helpers ─────────────────────────────────────────────────────

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

    private function createListing(string $externalKey, int $price, string $firstSeen, bool $active, ?string $lastSeen = null): PresentationActiveListing
    {
        return PresentationActiveListing::create([
            'presentation_id' => $this->presentation->id,
            'list_price_inc'  => $price,
            'external_key'    => $externalKey,
            'fingerprint'     => hash('sha256', $externalKey),
            'is_active'       => $active,
            'source_rank'     => 20,
            'status'          => 'active',
            'raw_row_json'    => '{}',
            'parser_version'  => 'test',
            'first_seen_at'   => $firstSeen,
            'last_seen_at'    => $lastSeen ?? $firstSeen,
        ]);
    }
}

<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Contracts\SoldTransactionsSource;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use App\Services\MarketAnalytics\Support\ComparableSet;
use App\Services\MarketAnalytics\Support\ComparableSetBuilder;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class ComparableSetBuilderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeSource(array $rows): SoldTransactionsSource
    {
        return new class($rows) implements SoldTransactionsSource {
            public function __construct(private array $rows) {}

            public function getRecords(SoldTransactionsFilter $filter): Collection
            {
                return collect($this->rows);
            }
        };
    }

    private function makeFilter(): SoldTransactionsFilter
    {
        return new SoldTransactionsFilter(
            suburbSlug:   'north-shore',
            propertyType: 'house',
            dateFrom:     '2024-01-01',
            dateTo:       '2025-01-01',
        );
    }

    /**
     * Three rows: two with the same date (tie-break by price then hash),
     * one on an earlier date.
     */
    private function sampleRows(): array
    {
        return [
            [
                'source_tag'     => 'internal_deals_v1',
                'deal_id'        => 10,
                'sold_date'      => '2024-06-15',
                'sold_price_inc' => 1500000.0,
                'suburb_slug'    => 'north-shore',
                'property_type'  => null,
                'bedrooms'       => null,
                'listed_date'    => null,
            ],
            [
                'source_tag'     => 'internal_deals_v1',
                'deal_id'        => 20,
                'sold_date'      => '2024-03-01',
                'sold_price_inc' => 1200000.0,
                'suburb_slug'    => 'north-shore',
                'property_type'  => null,
                'bedrooms'       => null,
                'listed_date'    => null,
            ],
            [
                'source_tag'     => 'internal_deals_v1',
                'deal_id'        => 30,
                'sold_date'      => '2024-06-15',   // same date as deal 10
                'sold_price_inc' => 1800000.0,      // higher price → comes after deal 10
                'suburb_slug'    => 'north-shore',
                'property_type'  => null,
                'bedrooms'       => null,
                'listed_date'    => null,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Determinism
    // -------------------------------------------------------------------------

    public function test_same_input_produces_identical_comps_hash(): void
    {
        $filter  = $this->makeFilter();
        $source  = $this->makeSource($this->sampleRows());
        $builder = new ComparableSetBuilder($source);

        $set1 = $builder->build($filter);
        $set2 = $builder->build($filter);

        $this->assertSame($set1->compsHash, $set2->compsHash);
        $this->assertSame($set1->rows, $set2->rows);
    }

    public function test_comps_hash_is_stable_regardless_of_source_row_order(): void
    {
        $filter = $this->makeFilter();
        $rows   = $this->sampleRows();

        $set1 = (new ComparableSetBuilder($this->makeSource($rows)))->build($filter);
        $set2 = (new ComparableSetBuilder($this->makeSource(array_reverse($rows))))->build($filter);
        $set3 = (new ComparableSetBuilder($this->makeSource([$rows[2], $rows[0], $rows[1]])))->build($filter);

        $this->assertSame($set1->compsHash, $set2->compsHash, 'reversed order must produce same hash');
        $this->assertSame($set1->compsHash, $set3->compsHash, 'shuffled order must produce same hash');
        $this->assertSame(3, $set1->count);
    }

    // -------------------------------------------------------------------------
    // Sort order
    // -------------------------------------------------------------------------

    public function test_rows_sorted_date_asc_then_price_asc(): void
    {
        $filter = $this->makeFilter();
        $set    = (new ComparableSetBuilder($this->makeSource($this->sampleRows())))->build($filter);

        // Expected: deal 20 (2024-03-01), deal 10 (2024-06-15, 1.5M), deal 30 (2024-06-15, 1.8M)
        $this->assertSame('2024-03-01', $set->rows[0]['sold_date'],      'row[0] should be earliest date');
        $this->assertSame('2024-06-15', $set->rows[1]['sold_date'],      'row[1] date');
        $this->assertSame(1500000.0,    $set->rows[1]['sold_price_inc'], 'row[1] lower price first');
        $this->assertSame('2024-06-15', $set->rows[2]['sold_date'],      'row[2] date');
        $this->assertSame(1800000.0,    $set->rows[2]['sold_price_inc'], 'row[2] higher price last');
    }

    // -------------------------------------------------------------------------
    // comps_hash structure
    // -------------------------------------------------------------------------

    public function test_comps_hash_is_64_char_sha256(): void
    {
        $set = (new ComparableSetBuilder($this->makeSource($this->sampleRows())))
            ->build($this->makeFilter());

        $this->assertSame(64, strlen($set->compsHash));
    }

    public function test_empty_source_returns_valid_empty_set(): void
    {
        $set = (new ComparableSetBuilder($this->makeSource([])))->build($this->makeFilter());

        $this->assertSame(0, $set->count);
        $this->assertSame([], $set->rows);
        $this->assertSame(64, strlen($set->compsHash)); // sha256 of json([])
    }

    public function test_different_row_sets_produce_different_comps_hash(): void
    {
        $filter = $this->makeFilter();
        $rows   = $this->sampleRows();

        $set1 = (new ComparableSetBuilder($this->makeSource($rows)))->build($filter);
        // Remove one row
        $set2 = (new ComparableSetBuilder($this->makeSource([$rows[0], $rows[1]])))->build($filter);

        $this->assertNotSame($set1->compsHash, $set2->compsHash);
    }

    // -------------------------------------------------------------------------
    // Price post-filter
    // -------------------------------------------------------------------------

    public function test_price_filter_includes_within_20_percent(): void
    {
        $filter = $this->makeFilter();
        $rows   = $this->sampleRows(); // prices: 1.2M, 1.5M, 1.8M

        // Target 1.5M ± 20% = [1.2M, 1.8M] — all three are on the boundary or inside
        $set = (new ComparableSetBuilder($this->makeSource($rows)))
            ->build($filter, priceTargetIncVat: 1500000.0);

        $this->assertSame(3, $set->count);
    }

    public function test_price_filter_excludes_out_of_range(): void
    {
        $filter = $this->makeFilter();
        $rows   = $this->sampleRows(); // prices: 1.2M, 1.5M, 1.8M

        // Target 1.4M ± 20% = [1.12M, 1.68M] — 1.8M is excluded
        $set = (new ComparableSetBuilder($this->makeSource($rows)))
            ->build($filter, priceTargetIncVat: 1400000.0);

        $this->assertSame(2, $set->count);
        $prices = array_column($set->rows, 'sold_price_inc');
        $this->assertNotContains(1800000.0, $prices);
    }

    // -------------------------------------------------------------------------
    // row_hash is present on every returned row
    // -------------------------------------------------------------------------

    public function test_each_row_has_row_hash(): void
    {
        $set = (new ComparableSetBuilder($this->makeSource($this->sampleRows())))
            ->build($this->makeFilter());

        foreach ($set->rows as $row) {
            $this->assertArrayHasKey('row_hash', $row);
            $this->assertSame(64, strlen($row['row_hash']));
        }
    }
}

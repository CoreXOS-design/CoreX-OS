<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\CompetitiveStockService;
use App\Services\Presentations\Evidence\ListingLifecycleService;
use App\Services\Presentations\PresentationDataQualityService;
use PHPUnit\Framework\TestCase;

class CompetitiveStockServiceTest extends TestCase
{
    private CompetitiveStockService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new CompetitiveStockService();
    }

    private function rows(array $prices): array
    {
        return array_map(fn (float $p) => ['list_price_inc' => $p], $prices);
    }

    public function test_total_active_stock_matches_row_count(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000, 1_500_000, 2_000_000]), null, 12.0);
        $this->assertSame(3, $result['total_active_stock']);
    }

    public function test_median_price_odd_count(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000, 2_000_000, 3_000_000]), null, 12.0);
        $this->assertSame(2_000_000.0, $result['median_price']);
    }

    public function test_median_price_even_count(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000, 2_000_000, 3_000_000, 4_000_000]), null, 12.0);
        $this->assertSame(2_500_000.0, $result['median_price']);
    }

    public function test_mean_price(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000, 2_000_000, 3_000_000]), null, 12.0);
        $this->assertSame(2_000_000.0, $result['mean_price']);
    }

    public function test_min_max_price(): void
    {
        $result = $this->svc->analyze($this->rows([1_500_000, 3_000_000, 2_000_000]), null, 12.0);
        $this->assertSame(1_500_000.0, $result['min_price']);
        $this->assertSame(3_000_000.0, $result['max_price']);
    }

    public function test_below_above_subject_count(): void
    {
        // Subject = 2M, rows: 1M, 1.5M, 2.5M, 3M
        $result = $this->svc->analyze(
            $this->rows([1_000_000, 1_500_000, 2_500_000, 3_000_000]),
            2_000_000.0,
            12.0,
        );
        $this->assertSame(2, $result['below_subject_count']);
        $this->assertSame(2, $result['above_subject_count']);
    }

    public function test_subject_price_null_gives_null_counts(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000, 2_000_000]), null, 12.0);
        $this->assertNull($result['below_subject_count']);
        $this->assertNull($result['above_subject_count']);
    }

    public function test_stock_months_available_calculation(): void
    {
        // 12 listings, 24 sold per year → 12 * 12 / 24 = 6 months
        $result = $this->svc->analyze($this->rows(array_fill(0, 12, 2_000_000.0)), null, 24.0);
        $this->assertSame(6.0, $result['stock_months_available']);
    }

    public function test_empty_rows_returns_nulls(): void
    {
        $result = $this->svc->analyze([], 2_000_000.0, 12.0);
        $this->assertSame(0, $result['total_active_stock']);
        $this->assertNull($result['median_price']);
        $this->assertNull($result['mean_price']);
        $this->assertNull($result['min_price']);
        $this->assertNull($result['max_price']);
        $this->assertNull($result['below_subject_count']);
        $this->assertNull($result['above_subject_count']);
        $this->assertNull($result['stock_months_available']);
    }

    public function test_zero_annual_absorption_gives_null_months(): void
    {
        $result = $this->svc->analyze($this->rows([1_000_000]), null, 0.0);
        $this->assertNull($result['stock_months_available']);
    }

    public function test_rows_with_null_prices_are_excluded_from_calculations(): void
    {
        $rows = [
            ['list_price_inc' => 1_000_000],
            ['list_price_inc' => null],
            ['list_price_inc' => 3_000_000],
        ];
        $result = $this->svc->analyze($rows, null, 12.0);
        $this->assertSame(3, $result['total_active_stock']); // total = all rows
        $this->assertSame(2_000_000.0, $result['median_price']); // computed from 2 valid prices
    }

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->svc->analyze($this->rows([2_000_000]), 2_000_000.0, 12.0);
        $required = [
            'total_active_stock', 'median_price', 'mean_price',
            'min_price', 'max_price', 'below_subject_count',
            'above_subject_count', 'stock_months_available',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    // ── analyzeWithLifecycle ────────────────────────────────────────────

    public function test_analyze_with_lifecycle_no_presentation_id_no_lifecycle_key(): void
    {
        $svc    = new CompetitiveStockService();
        $result = $svc->analyzeWithLifecycle($this->rows([2_000_000]), null, 12.0, null);

        $this->assertArrayNotHasKey('lifecycle', $result);
    }

    public function test_analyze_with_lifecycle_preserves_existing_keys(): void
    {
        // Even without lifecycle, all standard keys must be present
        $svc    = new CompetitiveStockService();
        $result = $svc->analyzeWithLifecycle($this->rows([1_000_000, 2_000_000]), 1_500_000.0, 12.0);

        $required = [
            'total_active_stock', 'median_price', 'mean_price',
            'min_price', 'max_price', 'below_subject_count',
            'above_subject_count', 'stock_months_available',
        ];
        foreach ($required as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
    }

    public function test_no_lifecycle_service_no_lifecycle_key(): void
    {
        // Constructor with null lifecycle service
        $svc    = new CompetitiveStockService(null);
        $result = $svc->analyzeWithLifecycle($this->rows([2_000_000]), null, 12.0, 1);

        $this->assertArrayNotHasKey('lifecycle', $result);
    }

    // ── data_quality guard clauses ──────────────────────────────────────

    public function test_no_data_quality_service_no_data_quality_key(): void
    {
        $svc    = new CompetitiveStockService(null, null);
        $result = $svc->analyzeWithLifecycle($this->rows([2_000_000]), null, 12.0, 1);

        $this->assertArrayNotHasKey('data_quality', $result);
    }

    public function test_no_presentation_id_no_data_quality_key(): void
    {
        $mockQuality = $this->createMock(PresentationDataQualityService::class);
        $mockQuality->expects($this->never())->method('evaluate');

        $svc    = new CompetitiveStockService(null, $mockQuality);
        $result = $svc->analyzeWithLifecycle($this->rows([2_000_000]), null, 12.0, null);

        $this->assertArrayNotHasKey('data_quality', $result);
    }

    public function test_analyze_still_has_no_data_quality_key(): void
    {
        // The basic analyze() method should never have data_quality
        $svc    = new CompetitiveStockService(null, new PresentationDataQualityService());
        $result = $svc->analyze($this->rows([2_000_000]), null, 12.0);

        $this->assertArrayNotHasKey('data_quality', $result);
        $this->assertArrayNotHasKey('lifecycle', $result);
    }
}

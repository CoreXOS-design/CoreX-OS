<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Services\MarketReports\Parsers\CmaInfoMedianSalesAnalysisParser;
use App\Domain\Presentation\TextExtractionService;
use Mockery;
use Tests\TestCase;

/**
 * AT-22 R3 — the CMA-Info "ST Residential Sales Analysis" table can present
 * as Year | No of Sales | Median Price with NO change-% column. The original
 * Sales-Analysis triplet regex keyed on the change-% column, so median +
 * sales-count were never produced — the presentation Market Overview could
 * never populate. The Residential Price Ranges table (year | count | low |
 * median | high | max) carries the same median + count, so the parser now
 * falls back to it. This test pins that behaviour with a change-%-less sample.
 */
final class CmaInfoMedianRangesFallbackTest extends TestCase
{
    public function test_median_and_count_fall_back_to_the_ranges_table(): void
    {
        // Ranges table only — no Sales-Analysis change-% column, the exact
        // shape that produced 0 median/count rows for Uvongo (PRES 87).
        $text = <<<TXT
        ST Residential Sales Analysis
        Year      UVONGO            RAY NKONYENI
        Residential Price Ranges
        Year Count Low Median High Maximum
        2026 7 R 1 295 000 R 1 300 000 R 1 325 000 R 1 700 000
        Please note these figures are indicative.
        TXT;

        // Real PDF text extraction is irrelevant to the parsing logic under
        // test — stub it to return the sample. A temp file satisfies the
        // is_file() guard; its content matches the sample so the pdftotext
        // path (if the binary runs) yields the same text.
        $tmp = tempnam(sys_get_temp_dir(), 'mdr') . '.pdf';
        file_put_contents($tmp, $text);

        $extractor = Mockery::mock(TextExtractionService::class);
        $extractor->shouldReceive('extractText')->andReturn($text);

        $parser = new CmaInfoMedianSalesAnalysisParser($extractor);
        $result = $parser->parse($tmp, new MarketReport(['source_suburb' => 'UVONGO']));
        @unlink($tmp);

        $byKeyYear = [];
        foreach ($result->dataPoints as $dp) {
            if (($dp['suburb_normalised'] ?? null) !== 'uvongo') continue;
            $year = (int) substr((string) $dp['metric_date'], 0, 4);
            $byKeyYear[$dp['metric_key'] . ':' . $year] = $dp['metric_value_numeric'];
        }

        $this->assertSame(1300000.0, $byKeyYear['suburb_median_price_year:2026'] ?? null, 'median pulled from ranges row');
        $this->assertSame(7.0,       $byKeyYear['suburb_sales_count_year:2026'] ?? null, 'sales count pulled from ranges row');
        $this->assertSame(1295000.0, $byKeyYear['suburb_low_year:2026'] ?? null);
        $this->assertSame(1700000.0, $byKeyYear['suburb_max_year:2026'] ?? null);
    }
}

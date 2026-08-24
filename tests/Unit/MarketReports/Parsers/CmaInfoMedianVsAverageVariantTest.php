<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Models\MarketReports\MarketReport;
use App\Services\MarketReports\Parsers\CmaInfoMedianSalesAnalysisParser;
use App\Domain\Presentation\TextExtractionService;
use Mockery;
use Tests\TestCase;

/**
 * 2026-08-24 — the report's own title/header ("ST Residential Sales
 * Analysis") is IDENTICAL for both the median and the average variant;
 * confirmed against 11 real uploaded reports on live, all 11 carry that
 * exact header regardless of which kind they are. The only reliable
 * in-document signal is the chart's own price-axis label: "Median Selling
 * Price" vs "Average Selling Price" — confirmed against real PDFs of both
 * kinds. Before this fix, every price point was written under
 * suburb_median_price_year regardless of variant; an average-variant
 * report's average prices were silently stored as if they were medians.
 * This test pins: (1) each variant's headline price lands under its own
 * distinct metric key, (2) an undetermined variant writes zero price data
 * rather than guessing.
 */
final class CmaInfoMedianVsAverageVariantTest extends TestCase
{
    private function parse(string $text): array
    {
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

        return ['byKeyYear' => $byKeyYear, 'rawJson' => $result->rawJson, 'dataPoints' => $result->dataPoints];
    }

    public function test_median_variant_lands_under_the_median_key(): void
    {
        // Shape matches a real median-variant report's triplet table:
        // year, count, R<price>, change%, index.
        $text = <<<TXT
        ST Residential Sales Analysis
        Year      UVONGO            RAY NKONYENI
        Median Selling Price
        2026 5 R 1 300 000 10.64% 393.3
        Residential Price Ranges
        Year Count Low Median High Maximum
        2026 7 R 1 295 000 R 1 300 000 R 1 325 000 R 1 700 000
        Please note these figures are indicative.
        TXT;

        $r = $this->parse($text);

        $this->assertSame('median', $r['rawJson']['variant'] ?? null);
        $this->assertSame(1300000.0, $r['byKeyYear']['suburb_median_price_year:2026'] ?? null);
        $this->assertArrayNotHasKey('suburb_average_price_year:2026', $r['byKeyYear'], 'a median report must never write under the average key');
    }

    public function test_average_variant_lands_under_the_average_key_not_median(): void
    {
        // Shape matches a real average-variant report's triplet table —
        // same year/count/R-price/change%/index structure as the median
        // variant (confirmed identical in both), but its OWN price-axis
        // label says Average, not Median.
        $text = <<<TXT
        ST Residential Sales Analysis
        Year      UVONGO            RAY NKONYENI
        Average Selling Price
        2026 39 R 3 428 256 5.32% 885.6
        Please note these figures are indicative.
        TXT;

        $r = $this->parse($text);

        $this->assertSame('average', $r['rawJson']['variant'] ?? null);
        $this->assertSame(3428256.0, $r['byKeyYear']['suburb_average_price_year:2026'] ?? null);
        $this->assertArrayNotHasKey('suburb_median_price_year:2026', $r['byKeyYear'], 'an average report must never write under the median key — this is the exact bug being fixed');
    }

    public function test_undetermined_variant_refuses_to_write_any_price_point(): void
    {
        // Neither phrase present — an unrecognised/future layout. Must
        // refuse rather than default to median.
        $text = <<<TXT
        ST Residential Sales Analysis
        Year      UVONGO            RAY NKONYENI
        2026 5 R 1 300 000 10.64% 393.3
        TXT;

        $r = $this->parse($text);

        $this->assertSame([], $r['dataPoints'], 'an undetermined variant must write ZERO price data points, not guess');
        $this->assertArrayHasKey('variant', $r['rawJson']);
        $this->assertNull($r['rawJson']['variant']);
        $this->assertStringContainsString('Could not determine', $r['rawJson']['note'] ?? '');
    }

    public function test_both_phrases_present_is_also_treated_as_undetermined(): void
    {
        // Ambiguous — both labels appear (e.g. a malformed extraction, or a
        // future report that legitimately shows both). Refuse, don't guess.
        $text = <<<TXT
        ST Residential Sales Analysis
        Median Selling Price
        Average Selling Price
        2026 5 R 1 300 000 10.64% 393.3
        TXT;

        $r = $this->parse($text);

        $this->assertSame([], $r['dataPoints']);
    }
}

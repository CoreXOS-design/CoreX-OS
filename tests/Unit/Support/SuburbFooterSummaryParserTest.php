<?php

namespace Tests\Unit\Support;

use App\Support\Presentation\DocumentExtractor;
use PHPUnit\Framework\TestCase;

/**
 * AT-22 item 4 — footer-summary fallback parse path for the per-sale
 * Home-Finders suburb report layout (DocumentExtractor::parseSuburbSales).
 *
 * Asserts the parser emits the SAME suburb.* field keys the consumer
 * (AnalysisDataService::compileSuburbOverview) reads, so the uploaded
 * suburb report binds to the Suburb Price Summary table instead of
 * rendering "Total Residential Sales = 0".
 */
class SuburbFooterSummaryParserTest extends TestCase
{
    private DocumentExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new DocumentExtractor();
    }

    /**
     * Representative verbatim footer shape from upload #88
     * "Suburb Report - Ramsgate South.pdf".
     */
    private function ramsgateFooterText(): string
    {
        return <<<TXT
Residential (Full title) sales in RAMSGATE SOUTH for the last 12 months

Erf 1234, 12 Marine Drive, Ramsgate South        2025/11/02    R 1 250 000
Erf 1340, 8 Outlook Road, Ramsgate South          2025/09/18    R 1 450 000
Erf 1455, 21 Seaview Crescent, Ramsgate South     2025/08/05    R 1 590 000
Erf 1501, 3 Dolphin Close, Ramsgate South         2025/07/22    R 1 700 000
Erf 1622, 44 Ridge Road, Ramsgate South           2025/06/11    R 1 850 000
Erf 1701, 9 Aloe Lane, Ramsgate South             2025/05/30    R 2 100 000
Erf 1810, 17 Pelican Way, Ramsgate South          2025/05/02    R 2 450 000
Erf 1899, 5 Coral Bend, Ramsgate South            2025/04/19    R 2 800 000

No of sales: 18   Median price: R 1 590 000   Average price: R 1 670 778
TXT;
    }

    public function test_footer_summary_emits_consumer_field_keys(): void
    {
        $fields = $this->extractor->parseSuburbSales($this->ramsgateFooterText());

        $this->assertSame('18', $fields['suburb.latest_sales_count']);
        $this->assertSame('1590000', $fields['suburb.latest_median_price']);
    }

    public function test_low_high_max_are_populated_and_ordered(): void
    {
        $fields = $this->extractor->parseSuburbSales($this->ramsgateFooterText());

        $this->assertArrayHasKey('suburb.latest_low', $fields);
        $this->assertArrayHasKey('suburb.latest_high', $fields);
        $this->assertArrayHasKey('suburb.latest_max', $fields);

        $low  = (int) $fields['suburb.latest_low'];
        $high = (int) $fields['suburb.latest_high'];
        $max  = (int) $fields['suburb.latest_max'];

        $this->assertGreaterThan(0, $low);
        $this->assertLessThanOrEqual($high, $low, 'low must be <= high');
        $this->assertLessThanOrEqual($max, $high, 'high must be <= max');

        // Derived from the per-sale price column: min R1 250 000, max R2 800 000.
        $this->assertSame(1250000, $low);
        $this->assertSame(2800000, $max);
    }

    public function test_year_inferred_from_sale_dates(): void
    {
        $fields = $this->extractor->parseSuburbSales($this->ramsgateFooterText());

        $this->assertSame('2025', $fields['suburb.latest_year']);
    }

    public function test_explicit_low_high_max_labels_are_preferred_over_derivation(): void
    {
        $text = <<<TXT
Residential (Full title) sales in RAMSGATE SOUTH for the last 12 months

Erf 1234, 12 Marine Drive       2025/11/02   R 1 250 000
Erf 1340, 8 Outlook Road        2025/09/18   R 2 800 000

No of sales: 12   Median price: R 1 590 000
Lowest price: R 1 000 000   High price: R 2 600 000   Highest price: R 3 100 000
TXT;

        $fields = $this->extractor->parseSuburbSales($text);

        $this->assertSame('1000000', $fields['suburb.latest_low']);
        $this->assertSame('2600000', $fields['suburb.latest_high']);
        $this->assertSame('3100000', $fields['suburb.latest_max']);
    }

    public function test_number_of_sales_variant_label_is_tolerated(): void
    {
        $text = <<<TXT
Residential sales in SHELLY BEACH for the last 12 months

Erf 22, 1 First Ave   2025/03/01   R 1 100 000
Erf 23, 2 Second Ave  2025/04/01   R 1 300 000

Number of sales: 7   Median price: R 1 200 000
TXT;

        $fields = $this->extractor->parseSuburbSales($text);

        $this->assertSame('7', $fields['suburb.latest_sales_count']);
        $this->assertSame('1200000', $fields['suburb.latest_median_price']);
    }

    /**
     * Guard: the footer path is a fallback only — the existing tabular
     * "Residential Sales Analysis" layout must still win and not be
     * disturbed by the new step.
     */
    public function test_tabular_layout_still_parses_and_takes_precedence(): void
    {
        $text = <<<TXT
Residential Sales Analysis
Year   NoOfSales   R-Median   Percentage   Index
2024   45          R 1 750 000   12   105
2025   38          R 1 900 000   8    110

No of sales: 99   Median price: R 9 999 999
TXT;

        $fields = $this->extractor->parseSuburbSales($text);

        // Tabular Step-1 wins: count 38 (latest year >=10), median 1 900 000 —
        // NOT the footer's 99 / 9 999 999.
        $this->assertSame('2025', $fields['suburb.latest_year']);
        $this->assertSame('38', $fields['suburb.latest_sales_count']);
        $this->assertSame('1900000', $fields['suburb.latest_median_price']);
    }
}

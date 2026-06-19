<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Services\MarketReports\Parsers\CmaInfoPropertyValuationParser;
use App\Services\MarketReports\Parsers\CmaInfoSectionalTitleSalesParser;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Stacked multi-section sectional-title sales (AT-59 follow-up — pres 98 /
 * PUMULA, reports 162 + 163).
 *
 * When one owner owns several sections of a scheme and sells the combined
 * unit, CMA Info prints the sale as a THREE-line block under pdftotext
 * -layout: the "Section [Flat] No" + "Extent" for each owned section sit on
 * the physical lines ABOVE and BELOW the "anchor" line (scheme + SS no/year +
 * sale date + price + R/m²). The anchor's own Section and Extent columns are
 * BLANK. The old single-line regexes either dropped the sale entirely
 * (CmaInfoPropertyValuationParser comparative table) or mis-read the SS-year
 * tail as a phantom section then nulled it (CmaInfoSectionalTitleSalesParser
 * radius table) — leaving section_number + extent_m2 NULL on a real comp
 * (the bare "PUMULA" rows with blank Unit + blank m² on pres 98).
 *
 * The sale is ONE transaction: the price covers the combined unit and the
 * printed R/m² is price ÷ (sum of the section extents) — R 500 000 ÷
 * (65 + 22 = 87 m²) = R 5 747. So we emit ONE comp carrying the joined
 * section label ("8/14") and BOTH extents for display ("65/22"); extent_m2
 * holds the SUMMED 87 as the math basis for size / R-per-m² only. The CMA's
 * stacked-vs-separate layout is mirrored exactly — separate rows stay separate.
 *
 * Both parsers operate on extracted text; the extraction methods are private,
 * so we drive them via reflection with synthetic blocks reproducing the exact
 * pdftotext shapes (the same fixtures verified live against staging reports
 * 162 + 163) — deterministic, no PDF fixture needed.
 */
final class CmaInfoStackedSectionSalesTest extends TestCase
{
    // ── Sectional Title (radius) — report 163 shape ─────────────────────────

    private function extractSectional(string $text): array
    {
        $isInScheme = (bool) preg_match('/Sectional\s+Title\s+sales\s+in\.?\s+[A-Z]/i', $text);
        $method = new ReflectionMethod(CmaInfoSectionalTitleSalesParser::class, 'extractCompRows');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoSectionalTitleSalesParser::class), $text, $isInScheme, null);
    }

    /**
     * Radius block: a stacked PUMULA sale (sections 8 + 14 wrapped above /
     * below the anchor) interleaved with a numbered single-line LOSCONA row,
     * exactly as pdftotext -layout renders report 163.
     */
    private function radiusStackedBlock(): string
    {
        return implode("\n", [
            'PUMULA, DUKE ROAD, MARGATE NORTH BEACH',
            'Sectional Title sales within. 300m',
            '                                                     Section     SS    SS                            Last Sale',
            '  Scheme Name                                                                   Type        Extent                   Last Sale    R/m²',
            '                                                         8                    Residence     65 m²',
            '  PUMULA, DUKE ROAD, MARGATE NORTH BEACH                            2    1976                           2025/06/27   R 500 000     R 5 747',
            '                                                         14                   Residence     22 m²',
            '  LOSCONA, 1 SAINT ANDREWS AVENUE, MARGATE',
            '                                                          12      132   1985   Residence     71 m²     2025/05/25   R 480 000     R 6 761',
        ]);
    }

    public function test_radius_stacked_sale_carries_joined_section_and_summed_extent(): void
    {
        $rows    = $this->extractSectional($this->radiusStackedBlock());
        $stacked = $this->rowWithPrice($rows, 500000);

        $this->assertNotNull($stacked, 'the stacked PUMULA sale (R500 000) must be parsed');
        $this->assertSame('PUMULA', $stacked['scheme_name']);
        $this->assertSame('8/14', $stacked['section_number'],
            'both owned sections are preserved, joined "8/14" (mirrors the source)');
        $this->assertSame('65/22', $stacked['extent_display'],
            'BOTH extents are preserved for display — "65/22", NOT summed');
        $this->assertSame(87, $stacked['extent_m2'],
            'extent_m2 is the SUM (65 + 22) — the math basis for size / R-per-m² only');
        $this->assertSame(5747, $stacked['r_per_m2'],
            'R/m² is the printed 5 747 = 500 000 / 87 (combined extent)');
    }

    public function test_radius_no_pumula_row_loses_section_or_extent(): void
    {
        $rows = $this->extractSectional($this->radiusStackedBlock());

        foreach ($rows as $row) {
            if (($row['scheme_name'] ?? null) !== 'PUMULA') continue;
            $this->assertNotNull($row['section_number'], 'no PUMULA comp may have a NULL section');
            $this->assertNotNull($row['extent_m2'], 'no PUMULA comp may have a NULL extent');
            $this->assertNotSame('976', (string) $row['section_number'],
                'the SS-year tail "976" must never resurface as a phantom section');
        }
    }

    public function test_radius_single_line_neighbour_row_is_unaffected(): void
    {
        $rows    = $this->extractSectional($this->radiusStackedBlock());
        $loscona = $this->rowWithPrice($rows, 480000);

        $this->assertNotNull($loscona, 'the numbered single-line LOSCONA row still parses');
        $this->assertSame('LOSCONA', $loscona['scheme_name']);
        $this->assertSame('12', (string) $loscona['section_number'],
            'a single-section row keeps its own section — never stolen by a neighbouring stacked sale');
        $this->assertSame(71, $loscona['extent_m2']);
    }

    // ── Property Valuation (comparative) — report 162 shape ──────────────────

    private function extractValuation(string $text, ?string $subjectScheme = null): array
    {
        $method = new ReflectionMethod(CmaInfoPropertyValuationParser::class, 'extractCmaCompRows');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoPropertyValuationParser::class), $text, $subjectScheme);
    }

    /**
     * Comparative-table block: a SUBJECT PROPERTY header row (section 12), then
     * a stacked comp (sections 8 + 14) and a single comp (section 1), exactly
     * as pdftotext -layout renders report 162 page 6.
     */
    private function comparativeBlock(): string
    {
        return implode("\n", [
            '  CMA - Comparative Market Analysis',
            '                                            Section',
            '                                                       SS       SS                            Last        Last      Estimated',
            '  Scheme Name, Address                       [Flat]                     Type      Extent                                         R/m²',
            '                                                       No      Year                         Sale Date     Sale        Value',
            '                                              No',
            '  SUBJECT PROPERTY',
            '  PUMULA, DUKE ROAD, MARGATE NORTH',
            '                                               12       2      1976   Residence    22 m²   2026/01/11   R 500 000',
            '  BEACH',
            '  COMPARATIVE PROPERTIES',
            '  PUMULA, DUKE ROAD MARGATE NORTH              8                      Residence    65 m²                                         R 7 100',
            '                                                        2      1976                        2025/06/27   R 500 000   R 518 000',
            '  BEACH                                        14                     Residence    22 m²                                         R 2 500',
            '  PUMULA, DUKE ROAD MARGATE NORTH',
            '                                               1        2      1976   Residence    65 m²   2024/10/05   R 470 000   R 497 000    R 7 600',
            '  BEACH',
            '                                                                                                         Lower Range:       R 524 000',
            '             Comparative Market Analysis Value R 557 000 as at 2026/06/17                                Middle Range:      R 557 000',
        ]);
    }

    public function test_comparative_stacked_comp_is_recovered_with_section_and_extent(): void
    {
        $rows    = $this->extractValuation($this->comparativeBlock());
        $stacked = $this->rowWithPrice($rows, 500000);

        $this->assertNotNull($stacked, 'the stacked comp (R500 000) must be recovered, not dropped');
        $this->assertSame('PUMULA', $stacked['scheme_name']);
        $this->assertSame('8/14', $stacked['section_number']);
        $this->assertSame('65/22', $stacked['extent_display'], 'BOTH extents preserved for display');
        $this->assertSame(87, $stacked['extent_m2'], 'summed math basis only');
        $this->assertSame(518000, $stacked['estimated_value'],
            'the second R-figure on the comparative anchor is the estimated value');
        $this->assertSame(5747, $stacked['r_per_m2'], 'R/m² = 500 000 / 87 (combined extent)');
    }

    public function test_comparative_single_line_comp_still_parses(): void
    {
        $rows   = $this->extractValuation($this->comparativeBlock());
        $single = $this->rowWithPrice($rows, 470000);

        $this->assertNotNull($single);
        $this->assertSame('1', (string) $single['section_number']);
        $this->assertSame(65, $single['extent_m2']);
    }

    public function test_comparative_subject_is_not_double_counted_as_a_comp(): void
    {
        $rows = $this->extractValuation($this->comparativeBlock());

        // The subject (section 12, sold 2026/01/11) is printed under the
        // SUBJECT PROPERTY header; it must NOT appear among the comparables.
        foreach ($rows as $row) {
            $this->assertNotSame('2026-01-11', $row['sale_date'] ?? null,
                'the subject-property row must not be captured as a comparable sale');
            $this->assertNotSame('12', (string) ($row['section_number'] ?? ''),
                'the subject section (12) must not appear as a comp');
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function rowWithPrice(array $rows, int $price): ?array
    {
        foreach ($rows as $row) {
            if ((int) ($row['sale_price'] ?? 0) === $price) {
                return $row;
            }
        }

        return null;
    }
}

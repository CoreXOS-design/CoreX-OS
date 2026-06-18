<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Services\MarketReports\Parsers\CmaInfoSectionalTitleSalesParser;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Scheme-capture gap in the RADIUS variant of the sectional-title-sales report
 * (AT-58 follow-up — pres 98 / PUMULA / report 163).
 *
 * The "Sectional Title sales within 300m" table lists comps from several
 * schemes, each row prefixed with its own "SCHEME, STREET, SUBURB" line. The
 * per-row scheme lookback required the street to start with a NUMBER
 * ("[0-9]{1,4}\s+[A-Z]"), so:
 *   - LOSCONA, 1 SAINT ANDREWS AVENUE  → matched   (has a number)
 *   - SUNTIDE CABANAS, 18 DUKE ROAD    → matched   (has a number)
 *   - PUMULA, DUKE ROAD                → NO MATCH  (numberless road) → scheme NULL
 *
 * The PUMULA rows therefore landed with scheme_name = NULL and were rendered as
 * "Section 976, margate" (the "976" being the tail of the SS-year "1976" that
 * Pattern B grabbed because the section column had wrapped away). They then fell
 * to the vicinity group instead of the subject's complex group.
 *
 * Fix: make the street number OPTIONAL in the scheme lookback, and drop a
 * year-tail phantom section. extractCompRows() is private and operates on the
 * extracted text, so this drives it via reflection with synthetic blocks that
 * reproduce the exact pdftotext shapes — deterministic, no PDF fixture needed.
 */
final class CmaInfoSectionalTitleSchemeCaptureTest extends TestCase
{
    private function extract(string $text): array
    {
        $isInScheme = (bool) preg_match('/Sectional\s+Title\s+sales\s+in\.?\s+[A-Z]/i', $text);

        $method = new ReflectionMethod(CmaInfoSectionalTitleSalesParser::class, 'extractCompRows');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoSectionalTitleSalesParser::class), $text, $isInScheme, null);
    }

    /**
     * Radius variant reproducing report 163's exact mangled shape: a numberless
     * PUMULA row interleaved with numbered LOSCONA / SUNTIDE rows.
     */
    private function radiusBlock(): string
    {
        return implode("\n", [
            'PUMULA, DUKE ROAD, MARGATE NORTH BEACH',
            'Sectional Title sales within. 300m',
            'PUMULA, DUKE ROAD, MARGATE NORTH BEACH                    2    1976                           2024/10/05   R 470 000     R 7 231',
            'LOSCONA, 1 SAINT ANDREWS AVENUE, MARGATE',
            '                                                          12      132   1985   Residence     71 m²     2025/05/25   R 480 000     R 6 761',
            'SUNTIDE CABANAS, 18 DUKE ROAD, MARGATE NORTH',
            '                                                          13      55    2022   Residence     37 m²     2025/01/22   R 500 000    R 13 514',
        ]);
    }

    public function test_numberless_road_scheme_is_captured_for_pumula_row(): void
    {
        $rows  = $this->extract($this->radiusBlock());
        $pumula = $this->rowWithPrice($rows, 470000);

        $this->assertNotNull($pumula, 'the PUMULA row (R470 000) must be parsed');
        $this->assertSame('PUMULA', $pumula['scheme_name'],
            'a numberless-road scheme ("PUMULA, DUKE ROAD") must still bind its scheme');
    }

    public function test_year_tail_does_not_become_a_phantom_section(): void
    {
        $rows  = $this->extract($this->radiusBlock());
        $pumula = $this->rowWithPrice($rows, 470000);

        $this->assertNotNull($pumula);
        $this->assertNotSame('976', (string) ($pumula['section_number'] ?? ''),
            'the SS-year tail "976" must not be stored as a section number');
        $this->assertNull($pumula['section_number'],
            'a year-tail phantom section is dropped to null rather than emitting "Unit 976"');
    }

    public function test_numbered_schemes_keep_their_own_scheme_no_overreach(): void
    {
        $rows = $this->extract($this->radiusBlock());

        $loscona = $this->rowWithPrice($rows, 480000);
        $suntide = $this->rowWithPrice($rows, 500000);

        $this->assertNotNull($loscona);
        $this->assertSame('LOSCONA', $loscona['scheme_name'],
            'a numbered-street scheme keeps its OWN scheme — never re-stamped as PUMULA');
        $this->assertNotNull($suntide);
        $this->assertSame('SUNTIDE CABANAS', $suntide['scheme_name']);
    }

    /** @param  array<int, array<string, mixed>>  $rows */
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

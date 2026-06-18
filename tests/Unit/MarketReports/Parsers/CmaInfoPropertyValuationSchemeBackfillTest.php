<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Services\MarketReports\Parsers\CmaInfoPropertyValuationParser;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Scheme-capture gap regression (Fix B).
 *
 * The "CMA - Comparative Market Analysis" block of a sectional Property
 * Valuation report lists the subject scheme's recent sales under a single
 * section header — the in-scheme units carry NO per-row scheme line and NO
 * own street. The per-row scheme lookback therefore found nothing and the
 * units were written with scheme_name = NULL (so CompLabel rendered them as
 * "Section 976, margate" with the complex name lost — see pres 98 / report
 * 162 / PUMULA on staging).
 *
 * Fix: when the report subject is sectional, attribute those scheme-less,
 * street-less rows to the subject scheme — mirroring
 * CmaInfoSectionalTitleSalesParser L299. Out-of-scheme comps carry their own
 * "SCHEME, <num> STREET" line, so the lookback fills both scheme + address
 * and they skip the fallback (never re-stamped).
 *
 * extractCmaCompRows() is private and operates on the extracted PDF text, so
 * this drives it via reflection with a synthetic block reproducing the two
 * row shapes — deterministic, no PDF fixture required.
 */
final class CmaInfoPropertyValuationSchemeBackfillTest extends TestCase
{
    private function extract(string $text, ?string $subjectScheme): array
    {
        $method = new ReflectionMethod(CmaInfoPropertyValuationParser::class, 'extractCmaCompRows');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoPropertyValuationParser::class), $text, $subjectScheme);
    }

    /**
     * Synthetic CMA comparative block:
     *   - row A: in-scheme PUMULA unit — section + tuple, no scheme line, no street.
     *   - row B: out-of-scheme comp — preceded by its own "SCHEME, <num> STREET" line.
     */
    private function block(): string
    {
        return implode("\n", [
            'CMA - Comparative Market Analysis',
            '9 976 2021 Residence 85 m² 2023/05/12 R 1 200 000',
            'SUNTIDE CABANAS, 18 DUKE ROAD',
            '13 977 2020 Residence 90 m² 2022/03/01 R 1 500 000',
            'Comparative Market Analysis Value R 1 300 000',
        ]);
    }

    public function test_in_scheme_unit_inherits_subject_scheme_when_subject_is_sectional(): void
    {
        $rows = $this->extract($this->block(), 'PUMULA');

        $inScheme = $this->rowWithSection($rows, '9');
        $this->assertNotNull($inScheme, 'expected the in-scheme unit (section 9) to be parsed');
        $this->assertSame('PUMULA', $inScheme['scheme_name'],
            'in-scheme unit with no per-row scheme/street must inherit the subject scheme');
    }

    public function test_out_of_scheme_comp_keeps_its_own_scheme_not_subject(): void
    {
        $rows = $this->extract($this->block(), 'PUMULA');

        $outScheme = $this->rowWithSection($rows, '13');
        $this->assertNotNull($outScheme, 'expected the out-of-scheme comp (section 13) to be parsed');
        $this->assertSame('SUNTIDE CABANAS', $outScheme['scheme_name'],
            'out-of-scheme comp carries its own per-row scheme — must NOT be re-stamped with the subject scheme');
        $this->assertSame('18 DUKE ROAD', $outScheme['address'],
            'out-of-scheme comp keeps its own street address');
    }

    public function test_freehold_subject_leaves_scheme_null(): void
    {
        // No subject scheme passed (freehold Property Valuation) → the
        // scheme-less in-scheme row stays null; nothing is invented.
        $rows = $this->extract($this->block(), null);

        $row = $this->rowWithSection($rows, '9');
        $this->assertNotNull($row);
        $this->assertNull($row['scheme_name'],
            'with no subject scheme, a scheme-less row must remain scheme_name = null');
    }

    /** @param  array<int, array<string, mixed>>  $rows */
    private function rowWithSection(array $rows, string $section): ?array
    {
        foreach ($rows as $row) {
            if ((string) ($row['section_number'] ?? '') === $section) {
                return $row;
            }
        }

        return null;
    }
}

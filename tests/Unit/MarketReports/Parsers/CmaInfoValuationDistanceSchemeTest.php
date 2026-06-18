<?php

declare(strict_types=1);

namespace Tests\Unit\MarketReports\Parsers;

use App\Services\MarketReports\Parsers\CmaInfoPropertyValuationParser;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Scheme capture for the distance-anchored SOLD PROPERTIES + FOR SALE blocks of
 * a Property Valuation report (AT-58 follow-up — report 162 / pres 98).
 *
 * Those tables print "SCHEME, <opt number> STREET, SUBURB" either inline after
 * the "<dist> m" anchor or wrapped on the line before it. The old extractors
 * captured only a lossy inline `address` (dropping leading letters —
 * "GOLDEN"→"OLDEN", "LUCIEN"→"CIEN", "SOLANO"→"NO") and missed wrapped schemes
 * entirely ("ITHACA"→""), leaving scheme_name NULL and the row rendered as a
 * bare "Section 7". Fix: resolve scheme + street off the full body text around
 * the anchor (where the spelling is intact).
 */
final class CmaInfoValuationDistanceSchemeTest extends TestCase
{
    private function sold(string $text): array
    {
        $method = new ReflectionMethod(CmaInfoPropertyValuationParser::class, 'extractSoldWithDistance');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoPropertyValuationParser::class), $text);
    }

    private function listings(string $text): array
    {
        $method = new ReflectionMethod(CmaInfoPropertyValuationParser::class, 'extractActiveListings');
        $method->setAccessible(true);

        return $method->invoke(app(CmaInfoPropertyValuationParser::class), $text);
    }

    public function test_wrapped_scheme_before_anchor_is_recovered(): void
    {
        // ITHACA's scheme wraps onto the line BEFORE its "565 m" anchor.
        $text = implode("\n", [
            'SOLD PROPERTIES',
            'ITHACA, 2 WILKIE ROAD, MARGATE',
            '565 m   7   Residence   54 m²   2025/05/01   2001   R 385 000   R 415 000   7.79%',
            'NORTH BEACH',
            'FOR SALE',
        ]);

        $rows = $this->sold($text);
        $this->assertNotEmpty($rows);
        $this->assertSame('ITHACA', $rows[0]['scheme_name'],
            'a scheme that wrapped before the distance anchor must still be captured');
        $this->assertSame('2 WILKIE ROAD', $rows[0]['address']);
    }

    public function test_inline_scheme_keeps_full_spelling_not_lossy_capture(): void
    {
        // SOLANO / GOLDEN sit inline after their anchors — the full body text
        // carries the correct leading letters even though the old inline group
        // dropped them.
        $text = implode("\n", [
            'SOLD PROPERTIES',
            '366 m   SOLANO, 6 BANK STREET, MARGATE   16   Residence   45 m²   2022/08/11   22   R 420 000   R 350 000   -16.67%',
            'FOR SALE',
        ]);

        $rows = $this->sold($text);
        $this->assertNotEmpty($rows);
        $this->assertSame('SOLANO', $rows[0]['scheme_name'],
            'inline scheme keeps its full spelling (not "NO")');
    }

    public function test_for_sale_listing_binds_scheme(): void
    {
        $text = implode("\n", [
            'FOR SALE',
            '315 m   GOLDEN SANDS, FOREST ROAD, MARGATE NORTH BEACH   11   Residence   169 m²   2025/06/11   R 1 350 000   371',
        ]);

        $rows = $this->listings($text);
        $this->assertNotEmpty($rows);
        $this->assertSame('GOLDEN SANDS', $rows[0]['scheme_name'],
            'a FOR SALE listing binds its scheme with the full spelling (not "OLDEN")');
        $this->assertSame('FOREST ROAD', $rows[0]['address']);
    }
}

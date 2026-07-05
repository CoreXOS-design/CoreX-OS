<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\Money\Zar;
use PHPUnit\Framework\TestCase;

/**
 * WS0 — the canonical ZAR money helper (AT-177 fixes the "R . number_format" duplication class).
 */
final class ZarTest extends TestCase
{
    public function test_format(): void
    {
        $this->assertSame('R 1,250,000.00', Zar::format(1250000));
        $this->assertSame('R 1,250,000', Zar::formatWhole(1250000));
        $this->assertSame('R 0.00', Zar::format(0));
        $this->assertSame('R 950,000.50', Zar::format(950000.5));
    }

    public function test_parse_sa_and_us_style(): void
    {
        $this->assertSame(1250000.0, Zar::parse('R 1,250,000'));
        $this->assertSame(1250000.5, Zar::parse('R 1,250,000.50'));
        $this->assertSame(1250000.0, Zar::parse('1250000'));
    }

    public function test_parse_space_thousands_and_comma_decimal(): void
    {
        $this->assertSame(1250000.0, Zar::parse('R1 250 000,00'));
        $this->assertSame(1250000.0, Zar::parse('1.250.000,00')); // euro style
    }

    public function test_parse_returns_null_for_garbage_and_empty(): void
    {
        $this->assertNull(Zar::parse('abc'));
        $this->assertNull(Zar::parse(''));
        $this->assertNull(Zar::parse(null));
        $this->assertNull(Zar::parse('R'));
    }

    public function test_is_valid(): void
    {
        $this->assertTrue(Zar::isValid('R 950,000'));
        $this->assertTrue(Zar::isValid('0'));
        $this->assertFalse(Zar::isValid('-5'));
        $this->assertFalse(Zar::isValid('abc'));
    }

    public function test_vat_derivations(): void
    {
        $this->assertSame(115.0, Zar::withVat(100.0));
        $this->assertSame(15.0, Zar::vatPortionOfInclusive(115.0));
    }
}

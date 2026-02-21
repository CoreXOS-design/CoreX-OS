<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\SuburbStockParserV1;
use PHPUnit\Framework\TestCase;

class SuburbStockParserV1Test extends TestCase
{
    private SuburbStockParserV1 $parser;

    protected function setUp(): void
    {
        $this->parser = new SuburbStockParserV1();
    }

    // ── Price detection ───────────────────────────────────────────────────────

    public function test_parses_line_with_price(): void
    {
        $rows = $this->parser->parseText("123 Main Street   R1,800,000   3 bed   active\n");

        $this->assertCount(1, $rows);
        $this->assertSame(1800000, $rows[0]['list_price_inc']);
    }

    public function test_line_without_price_is_skipped(): void
    {
        $rows = $this->parser->parseText("123 Main Street   3 bed\n");
        $this->assertCount(0, $rows);
    }

    public function test_price_below_threshold_is_ignored(): void
    {
        $rows = $this->parser->parseText("R500 deposit\n");
        $this->assertCount(0, $rows);
    }

    // ── Optional fields ───────────────────────────────────────────────────────

    public function test_extracts_beds(): void
    {
        $rows = $this->parser->parseText("456 Oak Ave   R2,500,000   4 bed\n");
        $this->assertSame(4, $rows[0]['beds']);
    }

    public function test_extracts_status(): void
    {
        $rows = $this->parser->parseText("456 Oak Ave   R2,500,000   active\n");
        $this->assertSame('active', $rows[0]['status']);
    }

    public function test_extracts_size_m2(): void
    {
        $rows = $this->parser->parseText("456 Oak Ave   R2,500,000   180 sqm\n");
        $this->assertSame(180, $rows[0]['size_m2']);
    }

    public function test_extracts_date_when_present(): void
    {
        $rows = $this->parser->parseText("01/03/2024   R1,200,000\n");
        $this->assertSame('2024-03-01', $rows[0]['listing_date']);
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $rows = $this->parser->parseText("R1,200,000\n");

        $this->assertNull($rows[0]['listing_date']);
        $this->assertNull($rows[0]['beds']);
        $this->assertNull($rows[0]['baths']);
        $this->assertNull($rows[0]['size_m2']);
        $this->assertNull($rows[0]['suburb']);
        $this->assertNull($rows[0]['property_type']);
        $this->assertNull($rows[0]['status']);
    }

    // ── Multi-line ────────────────────────────────────────────────────────────

    public function test_parses_multiple_valid_lines(): void
    {
        $text = implode("\n", [
            'Active Listings Report',
            '123 Main Street   R1,800,000   3 bed',
            'Report generated: 2024-01-01',
            '456 Oak Ave   R2,500,000   4 bed   180 sqm',
            '',
        ]);

        $rows = $this->parser->parseText($text);
        $this->assertCount(2, $rows);
    }

    public function test_empty_text_returns_no_rows(): void
    {
        $rows = $this->parser->parseText('');
        $this->assertCount(0, $rows);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_text_always_produces_same_rows(): void
    {
        $text = "R1,800,000   3 bed   active\nR2,500,000   4 bed\n";

        $r1 = $this->parser->parseText($text);
        $r2 = $this->parser->parseText($text);

        $this->assertSame($r1, $r2);
    }
}

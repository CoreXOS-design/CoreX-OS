<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\SalesReportParserV1;
use PHPUnit\Framework\TestCase;

class SalesReportParserV1Test extends TestCase
{
    private SalesReportParserV1 $parser;

    protected function setUp(): void
    {
        $this->parser = new SalesReportParserV1();
    }

    // ── Date + price detection ────────────────────────────────────────────────

    public function test_parses_dd_mm_yyyy_date_and_price(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R1,450,000   3 bed   150m²\n");

        $this->assertCount(1, $rows);
        $this->assertSame('2024-01-15', $rows[0]['sold_date']);
        $this->assertSame(1450000, $rows[0]['sold_price_inc']);
    }

    public function test_parses_iso_date_and_price(): void
    {
        $rows = $this->parser->parseText("2024-03-22   R2,100,000\n");

        $this->assertCount(1, $rows);
        $this->assertSame('2024-03-22', $rows[0]['sold_date']);
        $this->assertSame(2100000, $rows[0]['sold_price_inc']);
    }

    public function test_line_without_price_is_skipped(): void
    {
        $rows = $this->parser->parseText("15/01/2024   No price here\n");
        $this->assertCount(0, $rows);
    }

    public function test_line_without_date_is_skipped(): void
    {
        $rows = $this->parser->parseText("R1,450,000   3 bed   150m²\n");
        $this->assertCount(0, $rows);
    }

    public function test_price_below_threshold_is_ignored(): void
    {
        // R999 is too small (< 10 000) to be a property price
        $rows = $this->parser->parseText("15/01/2024   R999\n");
        $this->assertCount(0, $rows);
    }

    // ── Optional fields ───────────────────────────────────────────────────────

    public function test_extracts_beds(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R1,500,000   3 bed\n");
        $this->assertSame(3, $rows[0]['beds']);
    }

    public function test_extracts_baths(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R1,500,000   2 bath\n");
        $this->assertSame(2, $rows[0]['baths']);
    }

    public function test_extracts_size_m2(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R1,500,000   120m2\n");
        $this->assertSame(120, $rows[0]['size_m2']);
    }

    public function test_missing_optional_fields_are_null(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R1,500,000\n");

        $this->assertNull($rows[0]['beds']);
        $this->assertNull($rows[0]['baths']);
        $this->assertNull($rows[0]['size_m2']);
        $this->assertNull($rows[0]['suburb']);
        $this->assertNull($rows[0]['property_type']);
        $this->assertNull($rows[0]['listed_date']);
    }

    // ── Multi-line + empty lines ──────────────────────────────────────────────

    public function test_parses_multiple_valid_lines(): void
    {
        $text = implode("\n", [
            'Sales Report',
            '15/01/2024   R1,450,000   3 bed',
            'Total properties: 2',
            '22/02/2024   R2,100,000   4 bed   200m²',
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
        $text = "15/01/2024   R1,450,000   3 bed   150m²\n22/02/2024   R2,100,000\n";

        $r1 = $this->parser->parseText($text);
        $r2 = $this->parser->parseText($text);

        $this->assertSame($r1, $r2);
    }

    // ── Price format variants ─────────────────────────────────────────────────

    public function test_price_with_spaces_as_separator(): void
    {
        $rows = $this->parser->parseText("15/01/2024   R 1 450 000\n");

        $this->assertCount(1, $rows);
        $this->assertSame(1450000, $rows[0]['sold_price_inc']);
    }
}

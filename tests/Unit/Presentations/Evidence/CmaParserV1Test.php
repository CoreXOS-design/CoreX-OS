<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\CmaParserV1;
use PHPUnit\Framework\TestCase;

class CmaParserV1Test extends TestCase
{
    private CmaParserV1 $parser;

    protected function setUp(): void
    {
        $this->parser = new CmaParserV1();
    }

    // ── Price band extraction ─────────────────────────────────────────────────

    public function test_extracts_price_band_with_to_separator(): void
    {
        $text   = "Suggested value: R2,000,000 to R2,500,000";
        $result = $this->parser->parseText($text);

        $this->assertNotNull($result['suggested_band']);
        $this->assertSame(2000000, $result['suggested_band']['low']);
        $this->assertSame(2500000, $result['suggested_band']['high']);
    }

    public function test_extracts_price_band_with_dash_separator(): void
    {
        $text   = "Market range: R1,800,000 - R2,200,000";
        $result = $this->parser->parseText($text);

        $this->assertNotNull($result['suggested_band']);
        $this->assertSame(1800000, $result['suggested_band']['low']);
        $this->assertSame(2200000, $result['suggested_band']['high']);
    }

    public function test_no_band_returns_null(): void
    {
        $result = $this->parser->parseText("CMA Report\nNo price information\n");
        $this->assertNull($result['suggested_band']);
    }

    public function test_inverted_range_is_ignored(): void
    {
        // high < low — should not parse
        $result = $this->parser->parseText("R2,500,000 to R1,000,000");
        $this->assertNull($result['suggested_band']);
    }

    // ── Recommended value notes ───────────────────────────────────────────────

    public function test_extracts_recommended_price_note(): void
    {
        $text   = "Recommended price: R2,150,000";
        $result = $this->parser->parseText($text);

        $this->assertContains('suggested_value:2150000', $result['notes']);
    }

    public function test_extracts_suggested_value_note(): void
    {
        $text   = "Suggested value: R3,000,000";
        $result = $this->parser->parseText($text);

        $this->assertContains('suggested_value:3000000', $result['notes']);
    }

    public function test_no_recommended_value_returns_empty_notes(): void
    {
        $result = $this->parser->parseText("CMA Report\nMarket analysis\n");
        $this->assertSame([], $result['notes']);
    }

    // ── Parse output structure ────────────────────────────────────────────────

    public function test_parsetext_returns_required_keys(): void
    {
        $result = $this->parser->parseText('');

        $this->assertArrayHasKey('suggested_band', $result);
        $this->assertArrayHasKey('notes', $result);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_text_always_produces_same_output(): void
    {
        $text = "Recommended price: R2,150,000\nR1,800,000 to R2,200,000";

        $r1 = $this->parser->parseText($text);
        $r2 = $this->parser->parseText($text);

        $this->assertSame($r1, $r2);
    }
}

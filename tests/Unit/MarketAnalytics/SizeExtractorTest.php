<?php

namespace Tests\Unit\MarketAnalytics;

use App\Services\MarketAnalytics\Helpers\SizeExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SizeExtractor — pure function, no DB.
 */
class SizeExtractorTest extends TestCase
{
    // =========================================================================
    // Null / empty / invalid inputs
    // =========================================================================

    public function test_returns_null_for_null_payload(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(null));
    }

    public function test_returns_null_for_empty_string(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(''));
    }

    public function test_returns_null_for_invalid_json(): void
    {
        $this->assertNull(SizeExtractor::fromPayload('not json at all'));
    }

    public function test_returns_null_for_json_array(): void
    {
        // JSON arrays (not objects) decode to a list, not an associative array
        $this->assertNull(SizeExtractor::fromPayload('[1, 2, 3]'));
    }

    public function test_returns_null_when_no_recognised_key(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(json_encode(['bedrooms' => 3, 'bathrooms' => 2])));
    }

    // =========================================================================
    // Key recognition
    // =========================================================================

    public function test_extracts_floor_area(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['floor_area' => 120]));
        $this->assertSame(120.0, $result);
    }

    public function test_extracts_size(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['size' => 200]));
        $this->assertSame(200.0, $result);
    }

    public function test_extracts_m2(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['m2' => 85]));
        $this->assertSame(85.0, $result);
    }

    public function test_extracts_sqm(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['sqm' => 150]));
        $this->assertSame(150.0, $result);
    }

    public function test_extracts_erf_size(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['erf_size' => 450]));
        $this->assertSame(450.0, $result);
    }

    public function test_extracts_floor_size(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['floor_size' => 95]));
        $this->assertSame(95.0, $result);
    }

    public function test_extracts_area(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['area' => 300]));
        $this->assertSame(300.0, $result);
    }

    // =========================================================================
    // Key normalisation (case + spaces)
    // =========================================================================

    public function test_key_comparison_is_case_insensitive(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['Floor_Area' => 110]));
        $this->assertSame(110.0, $result);
    }

    public function test_key_spaces_are_treated_as_underscores(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['floor area' => 130]));
        $this->assertSame(130.0, $result);
    }

    // =========================================================================
    // Preference order (floor_area before size)
    // =========================================================================

    public function test_floor_area_preferred_over_size(): void
    {
        $payload = json_encode(['size' => 50, 'floor_area' => 120]);
        $result  = SizeExtractor::fromPayload($payload);
        // floor_area is first in the key list; size should NOT be returned
        $this->assertSame(120.0, $result);
    }

    // =========================================================================
    // Range clamping
    // =========================================================================

    public function test_returns_null_when_value_below_min(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(json_encode(['size' => 9])));
    }

    public function test_returns_value_at_exact_min(): void
    {
        $this->assertSame(10.0, SizeExtractor::fromPayload(json_encode(['size' => 10])));
    }

    public function test_returns_value_at_exact_max(): void
    {
        $this->assertSame(5000.0, SizeExtractor::fromPayload(json_encode(['size' => 5000])));
    }

    public function test_returns_null_when_value_above_max(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(json_encode(['size' => 5001])));
    }

    // =========================================================================
    // Non-numeric values
    // =========================================================================

    public function test_returns_null_when_value_is_non_numeric_string(): void
    {
        $this->assertNull(SizeExtractor::fromPayload(json_encode(['size' => 'unknown'])));
    }

    public function test_accepts_float_value(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['size' => 142.5]));
        $this->assertSame(142.5, $result);
    }

    public function test_accepts_numeric_string(): void
    {
        $result = SizeExtractor::fromPayload(json_encode(['size' => '200']));
        $this->assertSame(200.0, $result);
    }
}

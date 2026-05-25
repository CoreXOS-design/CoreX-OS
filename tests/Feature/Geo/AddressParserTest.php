<?php

declare(strict_types=1);

namespace Tests\Feature\Geo;

use App\Support\Geocoding\AddressNormaliser;
use Tests\TestCase;

/**
 * Phase 11a Part F — G1-G3.
 *
 * Pure unit-style tests against AddressNormaliser::parse() — no DB, no cache,
 * no HTTP. Sectioned by the test plan in .ai/specs/geocoding-spec.md.
 */
final class AddressParserTest extends TestCase
{
    /** G1 — canonical SA SS form with unit, scheme, street, suburb. */
    public function test_g1_parses_canonical_ss_address_with_unit_scheme_street_and_suburb(): void
    {
        $parsed = AddressNormaliser::parse('36 Ss Topanga, 2587 Colin Road', 'Uvongo');

        $this->assertSame('36', $parsed['unit_number']);
        $this->assertSame('Topanga', $parsed['scheme_name']);
        $this->assertSame('2587 Colin Road', $parsed['street_address']);
        $this->assertSame('Uvongo', $parsed['suburb']);
        $this->assertTrue($parsed['is_sectional_title']);
        $this->assertTrue($parsed['is_geocodable']);
        $this->assertSame('2587 Colin Road, Uvongo', $parsed['geocode_target']);
    }

    /** G2 — scheme-name-only input must be flagged not geocodable. */
    public function test_g2_scheme_only_input_is_marked_not_geocodable(): void
    {
        $parsed = AddressNormaliser::parse('Ss Madeira Gardens');

        $this->assertTrue($parsed['is_sectional_title']);
        $this->assertSame('Madeira Gardens', $parsed['scheme_name']);
        $this->assertNull($parsed['street_address']);
        $this->assertFalse($parsed['is_geocodable']);
        $this->assertNull($parsed['geocode_target']);
    }

    /** G3 — plain street address (no SS prefix) falls through cleanly. */
    public function test_g3_plain_street_address_with_inline_suburb_parses_without_ss_match(): void
    {
        $parsed = AddressNormaliser::parse('2587 Colin Road, Uvongo');

        $this->assertFalse($parsed['is_sectional_title']);
        $this->assertNull($parsed['unit_number']);
        $this->assertNull($parsed['scheme_name']);
        $this->assertSame('2587 Colin Road', $parsed['street_address']);
        $this->assertSame('Uvongo', $parsed['suburb']);
        $this->assertTrue($parsed['is_geocodable']);
        $this->assertSame('2587 Colin Road, Uvongo', $parsed['geocode_target']);
    }
}

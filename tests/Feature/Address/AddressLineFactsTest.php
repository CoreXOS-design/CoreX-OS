<?php

declare(strict_types=1);

namespace Tests\Feature\Address;

use App\Services\Address\AddressLineFacts;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §5.2: the ingest paths used to take the FIRST number of a typed line as the street
 * number. "303 / Pebble Beach, 10 Heleza Boulevard" became street number 303 and street "/ Pebble Beach, 10 Heleza
 * Boulevard" — the unit read as the street number, which is how 68 apartment blocks collapsed into one property file.
 */
final class AddressLineFactsTest extends TestCase
{
    public function test_a_unit_is_not_the_street_number(): void
    {
        $f = AddressLineFacts::split('303 / Pebble Beach, 10 Heleza Boulevard', 'Umhlanga');

        $this->assertNotNull($f);
        $this->assertSame('10', $f['street_number']);
        $this->assertSame('303', $f['unit_number']);
        $this->assertStringContainsString('Heleza', (string) $f['street_name']);
        $this->assertStringContainsString('Pebble Beach', (string) $f['complex_name']);
    }

    public function test_a_plain_house_address_reads_as_before(): void
    {
        $f = AddressLineFacts::split('12 Beach Road', 'Uvongo');

        $this->assertSame('12', $f['street_number']);
        $this->assertSame('Beach Road', $f['street_name']);
        $this->assertNull($f['unit_number']);
    }

    public function test_a_letter_after_the_number_is_kept(): void
    {
        $this->assertSame('12A', AddressLineFacts::split('12A Beach Road', 'Uvongo')['street_number']);
    }

    public function test_unit_prefix_words_are_lifted_out(): void
    {
        $f = AddressLineFacts::split('Unit 5, 21 Crown Road', 'Margate');

        $this->assertSame('21', $f['street_number']);
        $this->assertSame('5', $f['unit_number']);
    }

    public function test_nothing_is_invented_when_the_line_is_unreadable_or_empty(): void
    {
        $this->assertNull(AddressLineFacts::split('', 'Uvongo'));
        $this->assertNull(AddressLineFacts::split('   ', 'Uvongo'));
        $this->assertNull(AddressLineFacts::split(null, 'Uvongo'));
        $this->assertNull(AddressLineFacts::split('Address not available', 'Uvongo'));
    }
}

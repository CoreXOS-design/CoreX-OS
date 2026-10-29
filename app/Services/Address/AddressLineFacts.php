<?php

declare(strict_types=1);

namespace App\Services\Address;

/**
 * ONE reading of a typed address line for an ingest path (.ai/specs/property-identity.md §5.2).
 *
 * Replaces the "first number is the street number" guess that six ingest paths each carried
 * (`/^(\d+\w*)\s+(.+)$/`): on "303 / Pebble Beach, 10 Heleza Boulevard" it took the UNIT 303 as the street
 * number and "/ Pebble Beach, 10 Heleza Boulevard" as the street — the cause of 68 apartment blocks
 * collapsing into one property file. AddressParser reads unit, complex, street number and street apart; when
 * it cannot decide (two different numbers), nothing is guessed and the matcher gets the raw line only.
 */
final class AddressLineFacts
{
    /**
     * @return array{street_number: ?string, street_name: ?string, unit_number: ?string, complex_name: ?string}|null
     *         null = no readable line, or the parser could not decide (review) — pass the raw address only
     */
    public static function split(?string $line, ?string $suburb = null): ?array
    {
        $line = trim((string) $line);
        if ($line === '' || strcasecmp($line, 'Address not available') === 0) {
            return null;
        }

        $r = (new AddressParser())->parse(['address' => $line, 'suburb' => $suburb]);
        if (($r['status'] ?? null) === 'review' || ($r['street_number'] === null && ($r['street_name_typed'] ?? null) === null)) {
            return null;
        }

        return [
            'street_number' => $r['street_number'],
            'street_name'   => $r['street_name_typed'] ?? null,
            'unit_number'   => $r['unit_number'],
            'complex_name'  => $r['complex_name'],
        ];
    }
}

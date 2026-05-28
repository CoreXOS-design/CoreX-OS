<?php

declare(strict_types=1);

namespace App\Support\Geocoding;

use App\Models\Prospecting\TrackedPropertyAddress;

/**
 * Composite normalised-address key for cross-source dedup.
 *
 * **This class invents NO normaliser of its own.** It is a thin composition
 * of two existing pieces:
 *   - `TrackedPropertyAddress::normaliseStreet()` + `normaliseSuburb()` —
 *     the matcher's canonical normalisers (already used by
 *     TrackedPropertyMatchOrCreateService Strategy 4 against TPs).
 *   - `App\Support\Geocoding\AddressNormaliser::parse()` — the existing
 *     address-string DECOMPOSER (handles "Ss Scheme" prefixes, splits
 *     street_address vs suburb, etc.).
 *
 * `fromComponents()` is the WRITE-TIME entry — used by the Property model's
 * save hook + the migration backfill. Inputs are already-decomposed
 * (street_number / street_name / suburb / unit_number) so it just normalises
 * and composes the four pieces.
 *
 * `fromAddressString()` is the READ-TIME entry — used by LocationGrouper to
 * group records that arrive with only a freeform `address` string. It calls
 * AddressNormaliser to DECOMPOSE, then routes the decomposed parts through
 * `fromComponents()` so the read-time and write-time keys match exactly.
 *
 * **Composite shape** (pipe-separated, lowercased on every component):
 *
 *     "<street_number>|<street_name_normalised>|<suburb_normalised>|<unit_number>"
 *
 * unit_number is the LAST component intentionally — two flats in the same
 * building (same street_number + street_name + suburb, different unit) get
 * DIFFERENT keys, so the map renders them as TWO pins. This is the
 * "unit-granularity at the grouper" choice Johan made in Phase B Decision 2;
 * the matcher (TPMC Strategy 4) stays unit-blind by design (a TP record
 * represents the "property record" not "the flat record").
 *
 * Returns null when ANY required part (street_number, street_name, suburb)
 * is missing or normalises to null — null comparison is treated as "not
 * equal" in every consumer (the grouper falls back to GPS-only merging,
 * the model leaves the cached `*_normalised` columns null).
 */
final class PropertyAddressKey
{
    /**
     * Write-time key. Inputs are pre-decomposed (Property columns or facts
     * arrays). Suburb / street_name are normalised through the matcher's
     * canonical normalisers; street_number and unit_number are stored raw
     * with whitespace trimmed.
     *
     * @param array{
     *   street_number?: ?string,
     *   street_name?:   ?string,
     *   suburb?:        ?string,
     *   unit_number?:   ?string|int,
     * } $facts
     */
    public static function fromComponents(array $facts): ?string
    {
        $streetNumber = isset($facts['street_number']) ? trim((string) $facts['street_number']) : '';
        $streetName   = TrackedPropertyAddress::normaliseStreet($facts['street_name']   ?? null);
        $suburb       = TrackedPropertyAddress::normaliseSuburb($facts['suburb']        ?? null);
        $unitNumber   = isset($facts['unit_number']) ? trim((string) $facts['unit_number']) : '';

        // Required: street_number + street_name + suburb. Without all three
        // the key isn't usable for cross-source dedup.
        if ($streetNumber === '' || $streetName === null || $suburb === null) {
            return null;
        }

        return strtolower(implode('|', [
            $streetNumber,
            $streetName,
            $suburb,
            $unitNumber,        // may be empty — empty string is a valid "no unit" position
        ]));
    }

    /**
     * Read-time key for records that arrive with only a freeform address
     * string (the shape LocationGrouper sees). Internally decomposes via
     * AddressNormaliser then composes through fromComponents() so the
     * output shape matches the write-time key exactly.
     */
    public static function fromAddressString(?string $address, ?string $suburb = null): ?string
    {
        if ($address === null || trim($address) === '') {
            return null;
        }

        $parsed = AddressNormaliser::parse($address, $suburb);

        // AddressNormaliser yields:
        //   - street_address: "12 Hibiscus Avenue" (number + name together)
        //   - unit_number:    extracted from "Ss" prefix or null
        //   - suburb:         from input suburb or parsed trailing comma
        // We split the leading street_number off the street_address so we
        // can hand fromComponents() the same decomposed shape it expects
        // from Property columns.
        $streetAddress = $parsed['street_address'] ?? null;
        if ($streetAddress === null) {
            return null;
        }

        // Leading "12", "12A", "1/3", "12-14". Same pattern as
        // EntryPointController::parseStreet() so write-time and read-time
        // splits agree on how a numeric prefix is recognised.
        $streetNumber = null;
        $streetName   = $streetAddress;
        if (preg_match('/^\s*(\d+[A-Za-z]?(?:\s*[\/-]\s*\d+[A-Za-z]?)?)\s+(.+)$/u', $streetAddress, $m)) {
            $streetNumber = trim($m[1]);
            $streetName   = trim($m[2]);
        }

        return self::fromComponents([
            'street_number' => $streetNumber,
            'street_name'   => $streetName,
            'suburb'        => $parsed['suburb'] ?? $suburb,
            'unit_number'   => $parsed['unit_number'] ?? null,
        ]);
    }
}

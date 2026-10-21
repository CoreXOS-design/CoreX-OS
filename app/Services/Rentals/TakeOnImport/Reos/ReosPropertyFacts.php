<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Services\Address\AddressParser;

/**
 * AT-479 — the property facts for a reOS address, built the same way for the preview match and for the import, so the
 * two can never disagree. The free text ("3 Aqua Breeze, 13 Stafford Avenue", "504/3 Robin Road") is read by the one
 * address reader (AddressParser); the suburb / town / province come from the user's pick, never from guesswork.
 */
final class ReosPropertyFacts
{
    /**
     * @param array<string,mixed> $location  decisions_json.property: suburb, town, province (all optional)
     * @return array{facts:array<string,mixed>, status:string, notes:list<string>}
     */
    public static function build(?string $address, array $location = [], ?AddressParser $parser = null): array
    {
        $parser ??= new AddressParser();
        $address = trim((string) $address);
        $suburb = self::clean($location['suburb'] ?? null);
        $town = self::clean($location['town'] ?? null);
        $province = self::clean($location['province'] ?? null);

        $parsed = $parser->parse(array_filter(['address' => $address, 'suburb' => $suburb, 'town' => $town, 'province' => $province], fn ($v) => $v !== null && $v !== ''));

        // "504/3 Robin Road" is unit 504 at number 3 (Johan, 21 Oct): a number pair with no unit of its own is split that way,
        // so the property is created, and found again, as unit 504 / no. 3 — not as a street number "504/3".
        [$number, $unit] = self::splitNumberAndUnit($parsed['street_number'] ?? null, $parsed['unit_number'] ?? null);

        $facts = [
            'street_number' => $number,
            'street_name' => $parsed['street_name_typed'] ?? ($parsed['street_name_clean'] ?? null),
            'unit_number' => $unit,
            'complex_name' => $parsed['complex_name'] ?? null,
            'suburb' => $suburb,
            'town' => $town,
            'province' => $province,
            'address' => $address,
        ];

        return [
            'facts' => array_filter($facts, static fn ($v) => $v !== null && $v !== ''),
            'status' => (string) ($parsed['status'] ?? 'unparseable'),
            'notes' => array_values($parsed['notes'] ?? []),
            // What the suburb-less property lookup compares on (ReosPropertyCandidates).
            'parts' => [
                'number' => $number,
                'unit' => $unit,
                'complex' => $parsed['complex_name'] ?? null,
                'core' => $parsed['street_core'] ?? null,
                'type' => $parsed['street_type'] ?? null,
            ],
        ];
    }

    /**
     * A street number written "504/3" (or "504-3") with no unit of its own is unit 504, number 3.
     *
     * @return array{0:?string, 1:?string} [street number, unit]
     */
    public static function splitNumberAndUnit(?string $number, ?string $unit): array
    {
        $number = $number === null || trim($number) === '' ? null : trim($number);
        $unit = $unit === null || trim($unit) === '' ? null : trim($unit);

        if ($number !== null && $unit === null && preg_match('/^(\d+[A-Za-z]?)\s*[\/-]\s*(\d+[A-Za-z]?)$/', $number, $m)) {
            return [$m[2], $m[1]];
        }

        return [$number, $unit];
    }

    private static function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}

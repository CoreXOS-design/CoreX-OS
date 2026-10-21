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

        $facts = [
            'street_number' => $parsed['street_number'] ?? null,
            'street_name' => $parsed['street_name_typed'] ?? ($parsed['street_name_clean'] ?? null),
            'unit_number' => $parsed['unit_number'] ?? null,
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
        ];
    }

    private static function clean(mixed $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}

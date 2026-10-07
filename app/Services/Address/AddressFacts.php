<?php

declare(strict_types=1);

namespace App\Services\Address;

use App\Models\Prospecting\TrackedPropertyAddress;
use Illuminate\Database\Eloquent\Model;

/**
 * The address of ONE thing — a Property, a TrackedProperty, an address-history row or a capture
 * payload — in the one shape the scorer compares (.ai/specs/structured-address-matching.md §6.1).
 *
 * Built from the structured columns when the row has them and parsed on the fly when it does not,
 * so the scorer is correct before AND after the backfill. Nothing here touches the database except
 * `fromPayload()`'s optional neighbour lookup (once per capture, never per candidate).
 */
final class AddressFacts
{
    /**
     * @param  array<int, string>  $suburbKeys      every normalised spelling of the suburb (twins, aliases)
     * @param  array<int, string>  $neighbourKeys   normalised names of Property24's neighbouring suburbs
     */
    public function __construct(
        public readonly ?int $p24SuburbId,
        public readonly ?string $suburbText,
        public readonly array $suburbKeys,
        public array $neighbourKeys,
        public readonly ?string $streetNumber,
        public readonly ?string $streetCore,
        public readonly ?string $streetType,
        public readonly ?string $unit,
        public readonly ?string $complex,
        public readonly ?string $schemeNumber,
        public readonly ?string $erf,
        public readonly ?string $portion,
        public readonly ?string $lpi,
        public readonly bool $sectional,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly ?string $label = null,
        public readonly ?string $complexRaw = null,
    ) {
    }

    /** Facts of a stored row (any of the address models). Parses on the fly when the row is not structured yet. */
    public static function fromModel(Model $m): self
    {
        $structurer = new AddressStructurer();
        $in = $structurer->inputFrom($m);
        $parsed = (new AddressParser())->parse($in);

        // Structured columns win when present; the parser fills the gaps for rows not backfilled yet.
        $core = self::col($m, 'street_core') ?? $parsed['street_core'];
        $type = self::col($m, 'street_type') ?? $parsed['street_type'];
        $number = self::col($m, 'street_number');
        $number = $number !== null ? AddressParser::numberKey($number) : null;
        // an old row can hold the number only inside the street text
        if ($number === null || ! preg_match('/^\d+[a-z]?([\/-]\d+[a-z]?)?$/', $number)) {
            $number = AddressParser::numberKey($parsed['street_number']);
        }
        $unit = self::col($m, 'section_number') ?? self::col($m, 'unit_number') ?? $parsed['unit_number'];
        $complex = self::col($m, 'complex_name') ?? self::col($m, 'scheme_name') ?? $parsed['complex_name'];
        $scheme = self::col($m, 'scheme_number') ?? $parsed['scheme_number'];
        $erf = self::col($m, 'erf_number') ?? $parsed['erf_number'];
        $portion = self::col($m, 'erf_portion') ?? $parsed['erf_portion'];
        $lpi = self::col($m, 'lpi_code') ?? $parsed['lpi_code'];

        $suburb = self::col($m, 'suburb');
        $p24 = self::col($m, 'p24_suburb_id');

        return self::build(
            p24: $p24 !== null ? (int) $p24 : null,
            suburb: $suburb,
            neighbours: [],
            number: $number, core: $core, type: $type, unit: $unit, complex: $complex, scheme: $scheme,
            erf: $erf, portion: $portion, lpi: $lpi,
            lat: self::coord(self::col($m, 'cma_gps_lat') ?? self::col($m, 'latitude')),
            lng: self::coord(self::col($m, 'cma_gps_lng') ?? self::col($m, 'longitude')),
            label: self::labelOf($core, $type, $number, $suburb),
            complexRaw: $complex,
        );
    }

    /**
     * Facts of a capture / payload array (the extension's property block, or the parsed facts the
     * match-or-create hub works with). Resolves the P24 suburb and its neighbours ONCE.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fromPayload(array $facts, bool $withNeighbours = true): self
    {
        $s = (new AddressStructurer())->structure($facts + ['street_name' => null]);
        $suburb = isset($facts['suburb']) && trim((string) $facts['suburb']) !== '' ? trim((string) $facts['suburb']) : null;
        $lat = self::coord($facts['cma_gps_lat'] ?? $facts['latitude'] ?? null);
        $lng = self::coord($facts['cma_gps_lng'] ?? $facts['longitude'] ?? null);
        $neighbours = ($withNeighbours && $suburb !== null)
            ? TrackedPropertyAddress::neighbouringSuburbKeys($suburb, $lat, $lng)
            : [];

        $unit = $s['section_number'] ?? $s['unit_number'];

        return self::build(
            p24: $s['p24_suburb_id'],
            suburb: $suburb,
            neighbours: $neighbours,
            number: AddressParser::numberKey($s['street_number']),
            core: $s['street_core'], type: $s['street_type'], unit: $unit,
            complex: $s['complex_name'], scheme: $s['scheme_number'],
            erf: $s['erf_number'], portion: $s['erf_portion'], lpi: $s['lpi_code'],
            lat: $lat, lng: $lng,
            label: self::labelOf($s['street_core'], $s['street_type'], AddressParser::numberKey($s['street_number']), $suburb),
            complexRaw: $s['complex_name'],
        );
    }

    private static function build(
        ?int $p24, ?string $suburb, array $neighbours, ?string $number, ?string $core, ?string $type, ?string $unit,
        ?string $complex, ?string $scheme, ?string $erf, ?string $portion, ?string $lpi, ?float $lat, ?float $lng, ?string $label,
        ?string $complexRaw = null,
    ): self {
        $unitKey = $unit !== null ? TrackedPropertyAddress::normaliseNumericIdentifier($unit) : null;
        $erfKey = $erf !== null ? TrackedPropertyAddress::normaliseNumericIdentifier($erf) : null;
        $portionKey = $portion !== null ? TrackedPropertyAddress::normaliseNumericIdentifier($portion) : null;
        $schemeKey = $scheme !== null && trim($scheme) !== '' ? mb_strtolower(trim($scheme)) : null;
        $complexKey = $complex !== null && trim($complex) !== '' ? AddressParser::placeKey($complex) : null;
        $sectional = $schemeKey !== null || ($unitKey !== null && $complexKey !== null && preg_match('/\d/', (string) $unitKey) === 1);

        return new self(
            p24SuburbId: $p24,
            suburbText: $suburb,
            suburbKeys: $suburb !== null ? TrackedPropertyAddress::suburbSpellingKeys($suburb) : [],
            neighbourKeys: $neighbours,
            streetNumber: $number,
            streetCore: $core,
            streetType: $type,
            unit: $unitKey,
            complex: $complexKey,
            schemeNumber: $schemeKey,
            erf: $erfKey,
            portion: $portionKey,
            lpi: $lpi !== null && $lpi !== '' ? mb_strtolower($lpi) : null,
            sectional: $sectional,
            lat: $lat,
            lng: $lng,
            label: $label,
            complexRaw: $complexRaw,
        );
    }

    private static function col(Model $m, string $col): mixed
    {
        if (! $m->offsetExists($col) && ! array_key_exists($col, $m->getAttributes())) {
            return null;
        }
        $v = $m->getAttribute($col);
        if (is_string($v)) {
            $v = trim($v);
            if ($v === '' || in_array(mb_strtolower($v), ['-', 'n/a', 'na'], true)) {
                return null;
            }
        }

        return $v;
    }

    private static function coord(mixed $v): ?float
    {
        return $v !== null && $v !== '' && is_numeric($v) ? (float) $v : null;
    }

    private static function labelOf(?string $core, ?string $type, ?string $number, ?string $suburb): ?string
    {
        $street = AddressParser::display($core, $type);
        $line = trim(($number !== null ? strtoupper($number) . ' ' : '') . ($street ?? ''));

        return trim($line . ($suburb !== null && $suburb !== '' ? ($line !== '' ? ', ' : '') . $suburb : '')) ?: null;
    }
}

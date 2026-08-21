<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Prospecting\TrackedPropertyOwner;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21, side-by-side comparison panel).
 *
 * Builds the evidence PropertyMatchDecisionService records for a tracked_property-to-
 * property match (strategy + a snapshot of both sides' compared values), AND the
 * richer, display-ready comparison the deeds-capture screen's side-by-side panel
 * renders: per-field match strength, which fields the matcher actually used, and
 * whether more than one plausible candidate exists.
 *
 * Read-only and side-effect-free throughout. Mirrors (does not call)
 * TrackedPropertyMatchOrCreateService::resolvePropertyMatch()'s condition order and
 * queries, so what's shown can never disagree with what actually happens when the
 * agent presses a button — that method is private and returns only the matched
 * Property, not which branch produced it or how many rows it would have returned,
 * so this infers both independently rather than changing that method's return shape
 * for every other caller.
 */
class PropertyDuplicateMatchEvidence
{
    public function strategyFor(TrackedProperty $tp): string
    {
        $isSectional = filled($tp->scheme_number)
            || (filled($tp->section_number) && preg_match('/\d/', (string) $tp->section_number));

        if ($isSectional && filled($tp->complex_name ?: $tp->scheme_name) && filled($tp->section_number)) {
            return 'sectional';
        }
        if (!$isSectional && filled($tp->erf_number) && filled($tp->suburb)) {
            return 'freehold_erf';
        }

        return 'address_fallback';
    }

    /** @return array<string, array{tracked_property: mixed, property: mixed}> */
    public function comparedValues(TrackedProperty $tp, Property $property): array
    {
        $fields = [
            'erf_number', 'street_number', 'street_name', 'suburb', 'town',
            'title_deed_number', 'complex_name', 'unit_number', 'latitude', 'longitude',
        ];

        $out = [];
        foreach ($fields as $field) {
            $out[$field] = ['tracked_property' => $tp->{$field} ?? null, 'property' => $property->{$field} ?? null];
        }
        $out['owner_name'] = ['tracked_property' => $this->trackedOwnerNames($tp), 'property' => $this->propertyOwnerNames($property)];
        $out['status'] = ['tracked_property' => null, 'property' => $property->status];

        return $out;
    }

    /**
     * How many Property rows the WINNING strategy's own query would return — not just
     * the one resolvePropertyMatch() happened to pick first. Johan: "if more than one
     * candidate exists, SAY SO on screen." Runs the exact same WHERE clause
     * resolvePropertyMatch() uses for that strategy, ->count() instead of ->first(),
     * so it can never disagree about what "candidate" means. One extra COUNT query
     * per matched row on the screen — negligible (a few dozen rows at most).
     */
    public function candidateCount(TrackedProperty $tp, string $strategy, int $agencyId): int
    {
        return match ($strategy) {
            'sectional' => Property::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereRaw('LOWER(complex_name) = ?', [mb_strtolower(trim((string) ($tp->complex_name ?: $tp->scheme_name)))])
                ->where('unit_number', trim((string) $tp->section_number))
                ->count(),
            'freehold_erf' => Property::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('erf_number', trim((string) $tp->erf_number))
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->count(),
            default => Property::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('street_number', trim((string) $tp->street_number))
                ->where('street_name_normalised', TrackedPropertyAddress::normaliseStreet($tp->street_name))
                ->where('suburb_normalised', TrackedPropertyAddress::normaliseSuburb($tp->suburb))
                ->count(),
        };
    }

    /** Which comparedValues() keys the given strategy actually reads to propose a match. */
    public function usedFieldsFor(string $strategy): array
    {
        return match ($strategy) {
            'sectional' => ['complex_name', 'unit_number'],
            'freehold_erf' => ['erf_number', 'suburb'],
            default => ['street_number', 'street_name', 'suburb'],
        };
    }

    /**
     * Display-ready side-by-side rows for the deeds-capture panel. Each row: label,
     * both sides' values (formatted, "Not recorded" for blanks — never a bare blank
     * that reads as a mismatch), whether the matcher used this field, and a match
     * strength: strong (an identity signal that agrees), weak (partial/ambiguous
     * agreement — Johan's example: matching street name, different number), differs,
     * or unknown (one or both sides blank — never scored as a mismatch).
     *
     * @return array<int, array{key: string, label: string, existing: ?string, scraped: ?string, used: bool, strength: string}>
     */
    public function panelRows(TrackedProperty $tp, Property $property): array
    {
        $used = $this->usedFieldsFor($this->strategyFor($tp));
        $streetStrength = $this->streetStrength($tp->street_number, $tp->street_name, $property->street_number, $property->street_name);

        $rows = [
            [
                'key' => 'street', 'label' => 'Street address',
                'existing' => $this->formatValue(trim((string) $property->street_number . ' ' . (string) $property->street_name)),
                'scraped' => $this->formatValue(trim((string) $tp->street_number . ' ' . (string) $tp->street_name)),
                'used' => in_array('street_number', $used, true) || in_array('street_name', $used, true),
                'strength' => $streetStrength,
            ],
            $this->identityRow('suburb', 'Suburb / township', $tp->suburb, $property->suburb, $used, corroborating: true),
            $this->identityRow('erf_number', 'Erf / stand number', $tp->erf_number, $property->erf_number, $used, corroborating: false),
            $this->identityRow('title_deed_number', 'Title deed number', $tp->title_deed_number, $property->title_deed_number, $used, corroborating: false),
            $this->identityRow('complex_name', 'Complex / scheme', $tp->complex_name ?: $tp->scheme_name, $property->complex_name, $used, corroborating: false),
            $this->identityRow('unit_number', 'Unit / section number', $tp->section_number ?: $tp->unit_number, $property->unit_number, $used, corroborating: false),
            $this->gpsRow($tp, $property, $used),
            $this->identityRow('extent', 'Extent / erf size', $this->extentValue($tp), $this->formatValue($property->erf_size_m2 ? $property->erf_size_m2 . ' m²' : null), $used, corroborating: false, alreadyFormatted: true),
            $this->identityRow('property_type', 'Property type', $tp->property_type, $property->property_type, $used, corroborating: true),
            $this->identityRow('owner_name', 'Owner name(s)', $this->trackedOwnerNames($tp), $this->propertyOwnerNames($property), $used, corroborating: false),
            [
                'key' => 'last_sale', 'label' => 'Last sale',
                'existing' => 'Not tracked on this record',
                'scraped' => $this->formatValue($tp->last_known_sold_price
                    ? 'R' . number_format((float) $tp->last_known_sold_price, 0, '.', ',') . ($tp->last_known_sold_date ? ' on ' . \Illuminate\Support\Carbon::parse($tp->last_known_sold_date)->format('Y-m-d') : '')
                    : null),
                'used' => false, 'strength' => 'unknown',
            ],
        ];

        return $rows;
    }

    /**
     * Johan's own example, made literal: "a matching street name with a different
     * number is weak." Number and name are judged independently, then combined —
     * both agree = strong, one agrees and one disagrees (or is missing) = weak, both
     * disagree = differs, nothing comparable on either side = unknown.
     */
    private function streetStrength(?string $tpNumber, ?string $tpName, ?string $propNumber, ?string $propName): string
    {
        $norm = fn (?string $v) => $v === null || trim($v) === '' ? null : mb_strtolower(trim($v));
        [$tpNumber, $tpName, $propNumber, $propName] = [$norm($tpNumber), $norm($tpName), $norm($propNumber), $norm($propName)];

        $numberBoth = $tpNumber !== null && $propNumber !== null;
        $nameBoth = $tpName !== null && $propName !== null;

        if (!$numberBoth && !$nameBoth) {
            return 'unknown';
        }

        $numberMatch = $numberBoth && $tpNumber === $propNumber;
        $nameMatch = $nameBoth && $tpName === $propName;

        if ($numberBoth && $nameBoth) {
            return match (true) {
                $numberMatch && $nameMatch => 'strong',
                $numberMatch || $nameMatch => 'weak',
                default => 'differs',
            };
        }

        // Only one of the two components is comparable on both sides.
        return $numberBoth ? ($numberMatch ? 'weak' : 'differs') : ($nameMatch ? 'weak' : 'differs');
    }

    /**
     * A single-value identity/corroborating field. $corroborating=true means "agrees,
     * but on its own is only medium evidence" (suburb, property type) — an exact
     * match still renders 'strong' since Johan's ask is "highlight what matched", not
     * a numeric score; $corroborating only affects nothing here today but keeps the
     * call sites self-documenting for the next field added.
     */
    private function identityRow(string $key, string $label, $tpValue, $propertyValue, array $used, bool $corroborating, bool $alreadyFormatted = false): array
    {
        $tpNorm = $tpValue === null || $tpValue === '' ? null : mb_strtolower(trim((string) $tpValue));
        $propNorm = $propertyValue === null || $propertyValue === '' ? null : mb_strtolower(trim((string) $propertyValue));

        $strength = match (true) {
            $tpNorm === null || $propNorm === null => 'unknown',
            $tpNorm === $propNorm => 'strong',
            default => 'differs',
        };

        return [
            'key' => $key, 'label' => $label,
            'existing' => $alreadyFormatted ? $propertyValue : $this->formatValue($propertyValue),
            'scraped' => $alreadyFormatted ? $tpValue : $this->formatValue($tpValue),
            'used' => in_array($key, $used, true),
            'strength' => $strength,
        ];
    }

    private function gpsRow(TrackedProperty $tp, Property $property, array $used): array
    {
        $tpGps = ($tp->latitude !== null && $tp->longitude !== null) ? "{$tp->latitude}, {$tp->longitude}" : null;
        $propGps = ($property->latitude !== null && $property->longitude !== null) ? "{$property->latitude}, {$property->longitude}" : null;

        $strength = 'unknown';
        if ($tpGps !== null && $propGps !== null) {
            $metres = $this->haversineMetres((float) $tp->latitude, (float) $tp->longitude, (float) $property->latitude, (float) $property->longitude);
            $strength = $metres <= 25 ? 'strong' : ($metres <= 150 ? 'weak' : 'differs');
        }

        return [
            'key' => 'gps', 'label' => 'GPS coordinates',
            'existing' => $this->formatValue($propGps),
            'scraped' => $this->formatValue($tpGps),
            'used' => false, // resolvePropertyMatch() (TP-to-Property) does not use GPS today — informational only.
            'strength' => $strength,
        ];
    }

    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function extentValue(TrackedProperty $tp): ?string
    {
        // Extent contract (.ai/specs/deeds-capture.md §6) — freehold and sectional
        // extents are two DIFFERENT columns, never crossed. erf_size_m2 (freehold)
        // takes priority; section_extent_m2 (sectional "floor size") is the fallback,
        // never cadastral_extent (raw scraped text, not the parsed numeric value).
        $extent = $tp->erf_size_m2 ?? $tp->section_extent_m2 ?? null;

        return $extent ? $this->formatValue($extent . ' m²') : null;
    }

    private function formatValue($value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function trackedOwnerNames(TrackedProperty $tp): ?string
    {
        return $tp->owners()
            ->where('ownership_status', TrackedPropertyOwner::OWNERSHIP_CURRENT)
            ->with('contact')
            ->get()
            ->map(fn ($o) => $o->contact ? trim($o->contact->first_name . ' ' . (string) $o->contact->last_name) : null)
            ->filter()
            ->implode(', ') ?: null;
    }

    private function propertyOwnerNames(Property $property): ?string
    {
        return DB::table('contact_property')
            ->join('contacts', 'contacts.id', '=', 'contact_property.contact_id')
            ->where('contact_property.property_id', $property->id)
            ->where('contact_property.role', 'owner')
            ->selectRaw("GROUP_CONCAT(TRIM(CONCAT(contacts.first_name, ' ', contacts.last_name)) SEPARATOR ', ') as names")
            ->value('names');
    }
}

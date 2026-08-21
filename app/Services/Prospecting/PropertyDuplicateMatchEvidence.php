<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;

/**
 * .ai/specs/2026-08-20-property-status-prospecting.md — deeds-capture duplicate-match
 * take rule (Johan, 2026-08-21). Builds the evidence PropertyMatchDecisionService
 * records for a tracked_property-to-property match: which strategy fired, and a
 * snapshot of both sides' compared values AT THIS MOMENT (never a live reference —
 * the underlying rows will keep changing).
 *
 * Read-only and side-effect-free. Mirrors (does not call) the SAME condition order
 * TrackedPropertyMatchOrCreateService::resolvePropertyMatch() uses, so the reported
 * strategy matches what actually fired — that method is private and returns only the
 * matched Property, not which branch produced it, so this infers it independently
 * from the same signals rather than changing that method's return shape for every
 * other caller.
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
        $ownerName = fn (TrackedProperty $tp) => $tp->owners()
            ->where('ownership_status', \App\Models\Prospecting\TrackedPropertyOwner::OWNERSHIP_CURRENT)
            ->with('contact')
            ->get()
            ->map(fn ($o) => $o->contact ? trim($o->contact->first_name . ' ' . (string) $o->contact->last_name) : null)
            ->filter()
            ->implode(', ') ?: null;

        $propertyOwnerName = \Illuminate\Support\Facades\DB::table('contact_property')
            ->join('contacts', 'contacts.id', '=', 'contact_property.contact_id')
            ->where('contact_property.property_id', $property->id)
            ->where('contact_property.role', 'owner')
            ->selectRaw("GROUP_CONCAT(TRIM(CONCAT(contacts.first_name, ' ', contacts.last_name)) SEPARATOR ', ') as names")
            ->value('names');

        $fields = [
            'erf_number', 'street_number', 'street_name', 'suburb', 'town',
            'title_deed_number', 'complex_name', 'unit_number', 'latitude', 'longitude',
        ];

        $out = [];
        foreach ($fields as $field) {
            $tpValue = $tp->{$field} ?? null;
            $propertyValue = match ($field) {
                'unit_number' => $property->unit_number,
                default => $property->{$field} ?? null,
            };
            $out[$field] = ['tracked_property' => $tpValue, 'property' => $propertyValue];
        }
        $out['owner_name'] = ['tracked_property' => $ownerName($tp), 'property' => $propertyOwnerName];
        $out['status'] = ['tracked_property' => null, 'property' => $property->status];

        return $out;
    }
}

<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Property;

/**
 * AT-481 — what tells the CoreX properties that fit a reOS address apart, for the "Which property is '3 Union Street'?" question: the
 * suburb, the kind of property and its size, how many leases it already has, and — when two of them are the SAME property entered twice
 * (same address in the same suburb) — which they are, so the screen says so instead of leaving the user to guess.
 * Read-only; agency-scoped by an explicit id.
 */
final class ReosCandidateDetails
{
    /**
     * @param list<array{id:int,label:string,suburb:string,town:?string}> $candidates  decisions_json.property.candidates
     * @return list<array{id:int,label:string,suburb:string,town:?string,detail:string,leases:int,duplicate_of:list<int>}>
     */
    public static function for(int $agencyId, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }
        $props = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->whereIn('id', array_column($candidates, 'id'))
            ->withCount('leases')
            ->get()->keyBy('id');

        $out = [];
        foreach ($candidates as $c) {
            $p = $props->get($c['id']);
            $bits = [];
            if ($p) {
                if (filled($p->property_type)) {
                    $bits[] = (string) $p->property_type;
                }
                if (filled($p->beds)) {
                    $bits[] = $p->beds . ' bed';
                }
                if (filled($p->erf_size_m2)) {
                    $bits[] = 'erf ' . $p->erf_size_m2 . ' m²';
                }
                if (filled($p->complex_name)) {
                    $bits[] = (string) $p->complex_name;
                }
            }
            $out[] = $c + ['detail' => implode(' · ', $bits), 'leases' => (int) ($p?->leases_count ?? 0), 'duplicate_of' => []];
        }

        // The same property entered twice: the same address in the same suburb.
        $byKey = [];
        foreach ($out as $i => $c) {
            $byKey[self::key($c)][] = $i;
        }
        foreach ($byKey as $idx) {
            if (count($idx) < 2) {
                continue;
            }
            foreach ($idx as $i) {
                $out[$i]['duplicate_of'] = array_values(array_map(fn ($j) => $out[$j]['id'], array_diff($idx, [$i])));
            }
        }

        return $out;
    }

    private static function key(array $c): string
    {
        return mb_strtolower((string) preg_replace('/[^\pL\pN]+/u', '', $c['label'] . '|' . $c['suburb']));
    }
}

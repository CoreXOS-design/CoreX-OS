<?php

namespace App\Services\Rentals\TakeOnImport\Reos;

use App\Models\Property;

/**
 * AT-479 (Johan, 21 Oct) — "step 1 is: check if the property exists", WITHOUT needing a suburb. reOS addresses carry none and
 * CoreX's general matcher only searches within a suburb, so this looks across every suburb of the agency's own properties:
 *
 *   street name + street number, plus unit / complex when the reOS address has them, all normalised
 *   ("504/3 Robin Road" = unit 504, no. 3, Robin Road; "Ave" = "Avenue"; case and punctuation ignored).
 *
 * Read-only and agency-scoped by an explicit id. Archived properties never match. Conservative on purpose: no street number
 * in the reOS address means no lookup (a street alone is not a property); a unit in the reOS address needs the same unit in
 * CoreX; an address with no unit does not match a CoreX unit in a block (a different dwelling).
 * Returns every property that fits, so the review screen can show one ("In CoreX"), several (pick) or none.
 */
final class ReosPropertyCandidates
{
    private const LIMIT = 8;

    /** @return list<array{id:int, label:string, suburb:string, town:?string}> */
    public static function find(int $agencyId, ?string $address): array
    {
        $want = ReosPropertyFacts::build($address)['parts'];
        if (($want['core'] ?? null) === null || ($want['number'] ?? null) === null) {
            return [];
        }

        $core = mb_strtolower((string) $want['core']);

        $rows = Property::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($core) {
                // street_core is filled by the address reader on save; rows saved before it existed fall back to the street name.
                $q->where('street_core', $core)
                    ->orWhere(fn ($n) => $n->whereNull('street_core')->where('street_name', 'like', '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $core) . '%'));
            })
            ->limit(200)
            ->get();

        $out = [];
        foreach ($rows as $p) {
            if (! self::fits($want, $p)) {
                continue;
            }
            $label = trim((string) ($p->buildDisplayAddress() ?: $p->address ?: $p->title ?: "Property #{$p->id}"));
            $out[] = ['id' => (int) $p->id, 'label' => $label, 'suburb' => (string) $p->suburb, 'town' => $p->town ?: null];
            if (count($out) >= self::LIMIT) {
                break;
            }
        }

        return $out;
    }

    /** @param array{number:?string,unit:?string,complex:?string,core:?string,type:?string} $want */
    private static function fits(array $want, Property $p): bool
    {
        // Street: the same core name, and the same street type when both sides state one.
        $haveCore = $p->street_core;
        $haveType = $p->street_type;
        if ($haveCore === null) {
            $read = ReosPropertyFacts::build('1 ' . $p->street_name)['parts'];
            $haveCore = $read['core'];
            $haveType = $haveType ?? $read['type'];
        }
        if (mb_strtolower((string) $haveCore) !== mb_strtolower((string) $want['core'])) {
            return false;
        }
        if ($want['type'] && $haveType && mb_strtolower($want['type']) !== mb_strtolower($haveType)) {
            return false;
        }

        // Number and unit: CoreX may hold "504/3" in the number alone, or unit 504 + number 3.
        [$haveNumber, $haveUnit] = ReosPropertyFacts::splitNumberAndUnit($p->street_number, $p->unit_number);
        if (self::key($haveNumber) !== self::key($want['number'])) {
            return false;
        }
        if (self::key($haveUnit) !== self::key($want['unit'])) {
            return false;
        }

        // Complex: only a contradiction rules a property out (CoreX may simply not hold the complex name).
        $wantComplex = self::key($want['complex']);
        $haveComplex = self::key($p->complex_name);
        if ($wantComplex !== '' && $haveComplex !== '' && $wantComplex !== $haveComplex) {
            return false;
        }

        return true;
    }

    /** lower-case letters and digits only; "Unit 5" / "5" / "05" agree, "Ave"/"Avenue" are handled by the street type above. */
    private static function key(?string $v): string
    {
        $v = mb_strtolower((string) $v);
        $v = (string) preg_replace('/\b(unit|flat|apt|apartment|no|number|section|sec)\b\.?/u', '', $v);
        $v = (string) preg_replace('/[^\pL\pN]+/u', '', $v);

        return ltrim($v, '0') === '' && $v !== '' ? '0' : ltrim($v, '0');
    }
}

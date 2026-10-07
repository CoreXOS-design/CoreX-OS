<?php

namespace App\Support\Presentations;

use App\Models\PresentationSoldComp;

/**
 * Carries an agent's sold-comp tick selection (included_comp_ids_json) across a
 * re-hydration of the presentation's comps.
 *
 * Re-hydration (Generate / Regenerate / comp-freshness) soft-deletes every
 * MIC + deal-register comp row and inserts fresh copies of the same sales with
 * NEW ids. A selection that still lists the old ids would then point only at
 * retired rows — the Review screen dropped them as "unavailable", the list
 * collapsed to [] ("agent unticked everything") and the CMA Lower / Middle /
 * Upper tiles went blank (presentation 213, version 505 — Johan, 2026-10-07).
 *
 * The rule:
 *   - null stays null (no opinion — use all comps).
 *   - an id that is still live stays as-is.
 *   - a retired id is swapped for the live comp of the SAME sale on the same
 *     presentation (CompFingerprint::sourceAgnosticKey — address / sectional
 *     scheme+section, sale date, sale price). Each live comp is claimed once,
 *     so two retired picks of an identical sale do not collapse into one.
 *   - a retired id with no live counterpart is dropped (genuinely gone).
 *   - a NON-empty selection that maps to nothing at all becomes null, never
 *     []: [] means the agent deliberately unticked everything, and we must not
 *     manufacture that decision on their behalf. An already-empty selection
 *     stays [] (that WAS the agent's decision).
 */
class CompSelectionCarryForward
{
    /**
     * @param  array<int|string>|null  $ids
     * @return array{ids: array<int>|null, remapped: array<int,int>, dropped: array<int>}
     */
    public static function remap(int $presentationId, ?array $ids): array
    {
        if ($ids === null) {
            return ['ids' => null, 'remapped' => [], 'dropped' => []];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return ['ids' => [], 'remapped' => [], 'dropped' => []];
        }

        $live = PresentationSoldComp::query()
            ->withoutGlobalScopes()
            ->where('presentation_id', $presentationId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'sold_date', 'sold_price_inc', 'raw_row_json']);
        $liveIds = $live->pluck('id')->map(fn ($v) => (int) $v)->all();
        $liveSet = array_flip($liveIds);

        $retiredIds = array_values(array_filter($ids, fn ($id) => !isset($liveSet[$id])));
        if ($retiredIds === []) {
            return ['ids' => $ids, 'remapped' => [], 'dropped' => []];
        }

        // Live comps by sale fingerprint, minus any already ticked directly.
        $claimed = array_flip(array_filter($ids, fn ($id) => isset($liveSet[$id])));
        $pool = [];
        foreach ($live as $c) {
            if (isset($claimed[(int) $c->id])) {
                continue;
            }
            $pool[self::key($c)][] = (int) $c->id;
        }

        $retired = PresentationSoldComp::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('presentation_id', $presentationId)
            ->whereIn('id', $retiredIds)
            ->get(['id', 'sold_date', 'sold_price_inc', 'raw_row_json'])
            ->keyBy('id');

        $out = [];
        $remapped = [];
        $dropped = [];
        foreach ($ids as $id) {
            if (isset($liveSet[$id])) {
                $out[] = $id;
                continue;
            }
            $row = $retired->get($id);
            $key = $row ? self::key($row) : null;
            if ($key !== null && !empty($pool[$key])) {
                $newId = array_shift($pool[$key]);
                $out[] = $newId;
                $remapped[$id] = $newId;
            } else {
                $dropped[] = $id;
            }
        }

        return [
            'ids'      => $out === [] ? null : $out,
            'remapped' => $remapped,
            'dropped'  => $dropped,
        ];
    }

    private static function key(PresentationSoldComp $c): string
    {
        $raw = is_string($c->raw_row_json) ? json_decode($c->raw_row_json, true) : $c->raw_row_json;
        $raw = is_array($raw) ? $raw : [];
        $date = $c->sold_date instanceof \DateTimeInterface
            ? $c->sold_date->format('Y-m-d')
            : (string) $c->sold_date;

        return CompFingerprint::sourceAgnosticKey(
            address: isset($raw['address']) ? (string) $raw['address'] : null,
            schemeName: isset($raw['scheme_name']) ? (string) $raw['scheme_name'] : null,
            sectionNumber: isset($raw['section_number']) ? (string) $raw['section_number'] : null,
            saleDate: $date,
            salePrice: (int) $c->sold_price_inc,
        );
    }
}

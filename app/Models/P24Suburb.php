<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class P24Suburb extends Model
{
    use SoftDeletes;


    protected $table = 'p24_suburbs';

    protected $fillable = [
        'name',
        'slug',
        'p24_id',
        'p24_city_id',
        'region',
        'surrounding_ids',
        'confirmed',
        'latitude',
        'longitude',
        'centroid_source',
        'centroid_geocoded_at',
        'p24_verified_at',
    ];

    protected $casts = [
        'p24_id'          => 'integer',
        'p24_city_id'     => 'integer',
        'surrounding_ids' => 'array',
        'confirmed'       => 'boolean',
        'latitude'        => 'float',
        'longitude'       => 'float',
        'centroid_geocoded_at' => 'datetime',
        'p24_verified_at' => 'datetime',
    ];

    public function city(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(P24City::class, 'p24_city_id');
    }

    /**
     * Look up a suburb by name (case-insensitive) or slug.
     *
     * Property #21014/#15774 investigation (2026-09-07) — the same suburb
     * NAME can exist in more than one province ("Melville" is both a
     * Johannesburg, Gauteng suburb and a Port Shepstone, KwaZulu-Natal one).
     * Matching on name alone and taking the first row silently filed KZN
     * properties under the Johannesburg row every time, because it has the
     * lower id — nothing about province or location was ever consulted.
     *
     * Disambiguation, in order, NEVER guessing between two candidates:
     *   1. Exactly one name match → that one (no collision to resolve).
     *   2. Multiple matches + a known province → narrow to candidates whose
     *      city belongs to that province. Unique after narrowing → that one.
     *   3. Still ambiguous (or no province given) + known coordinates →
     *      accept the nearest candidate ONLY if it's plausibly the same
     *      physical suburb (<=25km). A p24_suburbs centroid can itself be
     *      wrong (geocoded from mis-attributed listings via this very
     *      method — see GeocodeSuburbCentroids' fallback pass), so this is
     *      a last-resort tie-breaker, not a primary signal, and it only
     *      ever accepts a close match or refuses — never picks "the least
     *      far" of several implausible ones.
     *   4. Still unresolved → null. The caller must treat this as
     *      unresolved (flag it, e.g. `p24_suburb_mismatch`), never silently
     *      file the property under whichever row happens to sort first.
     */
    public static function lookup(string $suburbName, ?string $provinceName = null, ?float $lat = null, ?float $lng = null): ?self
    {
        $key = strtolower(trim($suburbName));
        $slug = str_replace(' ', '-', $key);

        $candidates = static::where('slug', $slug)
            ->orWhereRaw('LOWER(name) = ?', [$key])
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        if ($provinceName !== null && trim($provinceName) !== '') {
            $wanted = self::normaliseProvinceName($provinceName);
            $byProvince = $candidates->filter(
                fn (self $c) => $c->city && $c->city->province
                    && self::normaliseProvinceName($c->city->province->name) === $wanted
            );
            if ($byProvince->count() === 1) {
                return $byProvince->first();
            }
            if ($byProvince->count() > 1) {
                $candidates = $byProvince->values();
            }
        }

        if ($lat !== null && $lng !== null) {
            $nearest = null;
            $nearestKm = null;
            foreach ($candidates as $c) {
                if ($c->latitude === null || $c->longitude === null) {
                    continue;
                }
                $d = self::haversineKm($lat, $lng, $c->latitude, $c->longitude);
                if ($nearestKm === null || $d < $nearestKm) {
                    $nearestKm = $d;
                    $nearest = $c;
                }
            }
            if ($nearest !== null && $nearestKm <= 25.0) {
                return $nearest;
            }
        }

        return null;
    }

    private static function normaliseProvinceName(string $name): string
    {
        return strtolower(trim(str_replace('-', ' ', $name)));
    }

    private static function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Process-level memo for withParentAreaIds(), keyed by the sorted id set. */
    private static array $parentAreaCache = [];

    /**
     * Expand a wishlist's suburb ids to include their PARENT AREA.
     *
     * Johan's ruling, 2026-09-10 (live: contact 18900 / match 671 showed zero
     * rentals): P24 files "Margate Beach" (23618) and "Margate North Beach"
     * (26831) as suburbs entirely separate from "Margate" (5), and "Uvongo
     * Beach" (10) separately from "Uvongo" (2). A buyer who ticks the beachfront
     * name was therefore shown nothing from the parent suburb next door, even
     * though that is plainly the area they asked about.
     *
     * A suburb P is the parent of suburb C when P's name is a strict
     * WORD-boundary prefix of C's name — "Margate" parents both "Margate Beach"
     * and "Margate North Beach"; "Ramsgate" (one word) parents nothing, and
     * "Ram" could never match it. Candidate parent names are computed in PHP and
     * looked up by exact name, so this is an indexed IN lookup, never a LIKE
     * scan over 20k rows.
     *
     * Scoped to the child's own p24_city_id, because a bare name is NOT unique
     * countrywide — see lookup() above, where "Melville" exists in both
     * Johannesburg and Port Shepstone. A child row carrying no city id is not
     * expanded at all rather than risk pulling in a same-named suburb from
     * another province.
     *
     * Widening only ever runs child -> parent. A buyer who ticks the broad
     * "Margate" is NOT given the beachfront sub-suburbs; that is a separate
     * question and a separate decision.
     *
     * @param  int[]  $ids
     * @return int[]  the original ids plus any parent-area ids
     */
    public static function withParentAreaIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }

        sort($ids);
        $key = implode(',', $ids);
        if (isset(self::$parentAreaCache[$key])) {
            return self::$parentAreaCache[$key];
        }

        $rows = static::query()->whereIn('id', $ids)->get(['id', 'name', 'p24_city_id']);

        // (city id => candidate parent names) — only for children we can scope.
        $byCity = [];
        foreach ($rows as $row) {
            $cityId = (int) ($row->p24_city_id ?? 0);
            if ($cityId === 0) {
                continue; // unscopeable; never widen on a bare name
            }
            $words = preg_split('/\s+/', trim((string) $row->name)) ?: [];
            for ($i = 1; $i < count($words); $i++) { // strict prefixes only
                $byCity[$cityId][] = implode(' ', array_slice($words, 0, $i));
            }
        }

        $expanded = $ids;
        if (!empty($byCity)) {
            $parents = static::query()
                ->where(function ($q) use ($byCity) {
                    foreach ($byCity as $cityId => $names) {
                        $q->orWhere(function ($sub) use ($cityId, $names) {
                            $sub->where('p24_city_id', $cityId)
                                ->whereIn('name', array_values(array_unique($names)));
                        });
                    }
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $expanded = array_merge($expanded, $parents);
        }

        $expanded = array_values(array_unique($expanded));
        sort($expanded);

        return self::$parentAreaCache[$key] = $expanded;
    }
}

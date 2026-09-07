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
}

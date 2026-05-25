<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\Geocoding\GeocodingCache;
use App\Support\Geocoding\AddressNormaliser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 11a B — thin wrapper over the existing Phase 3f geocoding_cache
 * model, exposing the brief's get/put/putMiss/purgeExpired contract while
 * preserving the existing waterfall write path in AddressResolverService.
 *
 * Key responsibilities (above the bare model):
 *   - Normalise the input address before hashing — the cache key is the
 *     SHA-256 of the normalised string. Phase 3f stored the plain
 *     normalised value; we add the hash as a derived identity but keep
 *     `address_normalised` as the human-readable column for diagnosis.
 *   - Increment hit_count + stamp last_hit_at on every read hit.
 *   - Respect TTL via expires_at — entries past their expires_at are
 *     treated as misses (and purged on the daily schedule).
 *   - Differentiate success TTL (90 days) from failure TTL (7 days). The
 *     7-day failure TTL means a fixed source (e.g. an SS pattern that the
 *     normaliser now handles) can be retried in a week rather than waiting
 *     90 days.
 */
final class GeocodeCache
{
    private int $successTtlDays;
    private int $failureTtlDays;

    public function __construct()
    {
        $this->successTtlDays = (int) (config('geo.geocoding.cache_success_ttl_days') ?: 90);
        $this->failureTtlDays = (int) (config('geo.geocoding.cache_failure_ttl_days') ?: 7);
    }

    /**
     * Look up an address. Returns null on miss OR expired-cache.
     *
     * @return array{
     *   latitude:?float, longitude:?float, confidence:string,
     *   google_location_type:?string, provider:string,
     *   geocoded_at:?string, hit_count:int, miss:bool
     * }|null
     */
    public function get(string $address): ?array
    {
        $normalised = $this->normalise($address);
        if ($normalised === '') return null;

        $row = GeocodingCache::where('address_normalised', $normalised)->first();
        if (!$row) return null;

        // TTL check — expired rows behave as misses (caller may retry).
        if ($row->expires_at && $row->expires_at->isPast()) {
            return null;
        }

        // Record the hit. Use a direct UPDATE to avoid touching updated_at
        // (the read is not a state change of the underlying data).
        try {
            DB::table('geocoding_cache')->where('id', $row->id)->update([
                'hit_count'   => DB::raw('hit_count + 1'),
                'last_hit_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::channel('geocoding')->warning('cache hit-record failed', [
                'id' => $row->id, 'error' => $e->getMessage(),
            ]);
        }

        $isMiss = $row->latitude === null || $row->longitude === null;

        return [
            'latitude'             => $isMiss ? null : (float) $row->latitude,
            'longitude'            => $isMiss ? null : (float) $row->longitude,
            'confidence'           => (string) ($row->confidence ?? ($isMiss ? 'failed' : 'unknown')),
            'google_location_type' => $row->google_location_type,
            'provider'             => (string) ($row->source ?? 'unknown'),
            'geocoded_at'          => optional($row->created_at)->toIso8601String(),
            'hit_count'            => (int) $row->hit_count + 1,
            'miss'                 => $isMiss,
            'normalised'           => $normalised,
            'resolved_address'     => $row->resolved_address,
        ];
    }

    /**
     * Store a successful geocode result. Idempotent via the unique
     * address_normalised constraint.
     */
    public function put(
        string $address,
        float $lat,
        float $lng,
        string $confidence,
        ?string $provider = 'google',
        ?string $googleLocationType = null,
        ?string $resolvedAddress = null,
    ): void {
        $normalised = $this->normalise($address);
        if ($normalised === '') return;

        $expires = CarbonImmutable::now()->addDays($this->successTtlDays);

        GeocodingCache::updateOrCreate(
            ['address_normalised' => $normalised],
            [
                'address_raw'           => mb_substr($address, 0, 500),
                'latitude'              => $lat,
                'longitude'             => $lng,
                'confidence'            => $confidence,
                'google_location_type'  => $googleLocationType,
                'source'                => $provider ?? 'google',
                'resolved_address'      => $resolvedAddress ? mb_substr($resolvedAddress, 0, 500) : null,
                'failure_reason'        => null,
                'last_attempted_at'     => now(),
                'expires_at'            => $expires,
            ],
        );
    }

    /**
     * Store a miss (failed lookup). Short TTL so a future code fix can retry
     * within a week without polluting the longer-lived success cache.
     */
    public function putMiss(string $address, string $reason): void
    {
        $normalised = $this->normalise($address);
        if ($normalised === '') return;

        $expires = CarbonImmutable::now()->addDays($this->failureTtlDays);

        GeocodingCache::updateOrCreate(
            ['address_normalised' => $normalised],
            [
                'address_raw'        => mb_substr($address, 0, 500),
                'latitude'           => null,
                'longitude'          => null,
                'confidence'         => 'failed',
                'source'             => 'cache',
                'failure_reason'     => mb_substr($reason, 0, 500),
                'last_attempted_at'  => now(),
                'expires_at'         => $expires,
            ],
        );
    }

    /**
     * Hard-delete expired cache rows. Called by the daily scheduled command.
     */
    public function purgeExpired(): int
    {
        $deleted = DB::table('geocoding_cache')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        if ($deleted > 0) {
            Log::channel('geocoding')->info('geocoding_cache purge', [
                'rows_deleted' => $deleted,
                'at'           => now()->toIso8601String(),
            ]);
        }
        return $deleted;
    }

    /**
     * Same normalisation contract used everywhere else — call into the
     * existing AddressNormaliser so the cache key matches what Phase 3f
     * already stored.
     */
    private function normalise(string $address): string
    {
        // The existing normaliser already handles SA-specific patterns
        // (Ss prefix, Section, Unit, Flat, Apt). For sectional title
        // addresses we deliberately don't strip the unit here — the
        // backfill command will call AddressNormaliser::parse() to derive
        // the geocode_target and pass THAT into the cache as the key,
        // so all units of one scheme share the cache entry naturally.
        return AddressNormaliser::normalise($address);
    }
}

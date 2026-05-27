<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\Geocoding\GeocodingCache;
use App\Models\MarketReports\MarketReport;
use App\Models\PortalCapture;
use App\Support\Geocoding\AddressNormaliser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3f A3 — address → GPS waterfall resolver.
 *
 * Waterfall (each step caches its outcome, success OR failure):
 *   1. Cache hit on normalised key.
 *   2. market_reports.subject_address LIKE the normalised fragments
 *      — analyst-vetted, building-precise.
 *   3. portal_captures.extracted_fields_json — agent-captured P24/PP listings
 *      with embedded lat/lng.
 *   4. Google Geocoding API (requires GOOGLE_GEOCODING_API_KEY).
 *   5. Nominatim (OSM) — opt-in via NOMINATIM_ENABLED=true; throttled 1 req/sec
 *      and uses User-Agent per OSM policy.
 *   6. Cache as failed.
 *
 * Architectural decisions (not in the spec, called out here):
 *   - The `imported_listings` table named in the spec doesn't exist in this
 *     codebase — substituted portal_captures. P24Listing rows have no GPS so
 *     they're skipped (only suburb info).
 *   - The municipalities table doesn't exist; municipality_id resolution is
 *     deferred per CLAUDE.md non-negotiable #6. The resolver still extracts
 *     the municipality NAME from the geocoder response for future use.
 *   - Google quota/5xx failures DO NOT cache as 'failed' — we leave the slot
 *     empty so the next caller retries. Only definitive "no result" responses
 *     are cached as failed.
 */
final class AddressResolverService
{
    private const NETWORK_TIMEOUT_SEC = 5;

    /** Track the last Nominatim call timestamp for the 1 req/sec throttle. */
    private static ?float $nominatimLastCallAt = null;

    public function resolve(
        string $address,
        ?string $suburb = null,
        ?string $town = null,
        ?string $context = null,
    ): GeocodingResult {
        $normalised = AddressNormaliser::normalise($address, $suburb, $town);
        $suburbNorm = $suburb !== null ? mb_strtolower(trim($suburb)) : null;

        if ($normalised === '') {
            return new GeocodingResult(null, null, 'failed', 'cache', null, false, 'empty input');
        }

        // ── 1. Cache hit ────────────────────────────────────────────────────
        $cached = GeocodingCache::where('address_normalised', $normalised)->first();
        if ($cached !== null) {
            return new GeocodingResult(
                latitude:        $cached->latitude !== null ? (float) $cached->latitude : null,
                longitude:       $cached->longitude !== null ? (float) $cached->longitude : null,
                confidence:      (string) $cached->confidence,
                source:          (string) $cached->source,
                municipality:    $cached->municipality_name,
                cached:          true,
                failureReason:   $cached->failure_reason,
                resolvedAddress: $cached->resolved_address,
            );
        }

        // ── 2. market_reports.subject_address ───────────────────────────────
        $micResult = $this->resolveFromMarketReports($address, $suburbNorm);
        if ($micResult !== null) {
            return $this->cacheAndReturn($normalised, $address, $micResult, $suburbNorm);
        }

        // ── 3. portal_captures ──────────────────────────────────────────────
        $portalResult = $this->resolveFromPortalCaptures($address, $suburbNorm);
        if ($portalResult !== null) {
            return $this->cacheAndReturn($normalised, $address, $portalResult, $suburbNorm);
        }

        // Track whether ANY external resolver actually ran. The fail-cache
        // path below should only persist when an external attempt was made
        // (Google or Nominatim returned no result). If both branches were
        // skipped (no key, disabled, rate-limit-blocked) the failure must
        // NOT be cached — otherwise the next caller is short-circuited by
        // a stale row written under a transient condition.
        $externalAttempted = false;
        $googleStatus    = 'no_key';
        $nominatimStatus = 'disabled';

        // ── 4. Google Geocoding (rate-limited per Phase 11a) ────────────────
        $googleKey = config('services.google.geocoding_api_key') ?: config('geo.geocoding.google_api_key');
        if (!empty($googleKey)) {
            /** @var GeocodeRateLimiter $limiter */
            $limiter = app(GeocodeRateLimiter::class);
            if ($limiter->canGeocode()) {
                $externalAttempted = true;
                $googleStatus      = 'attempted';
                $googleResult = $this->callGoogle($normalised, (string) $googleKey);
                // Record AFTER the call. Definitive failures still consume
                // quota (Google bills failed-but-attempted). Transient nulls
                // are recorded too — they hit the same upstream endpoint.
                $limiter->recordCall();
                if ($googleResult !== null) {
                    return $this->cacheAndReturn($normalised, $address, $googleResult, $suburbNorm);
                }
                // null = transient error → don't cache, retry next time.
            } else {
                $googleStatus = 'rate_limited';
                Log::channel('geocoding')->info('Google skipped — daily cap reached', [
                    'normalised' => $normalised,
                    'remaining'  => $limiter->getRemainingToday(),
                ]);
            }
        }

        // ── 5. Nominatim (also rate-limited; free but politeness matters) ──
        if (config('services.nominatim.enabled')) {
            /** @var GeocodeRateLimiter $limiter */
            $limiter = app(GeocodeRateLimiter::class);
            if ($limiter->canGeocode()) {
                $externalAttempted = true;
                $nominatimStatus   = 'attempted';
                $nomResult = $this->callNominatim($normalised);
                $limiter->recordCall();
                if ($nomResult !== null) {
                    return $this->cacheAndReturn($normalised, $address, $nomResult, $suburbNorm);
                }
            } else {
                $nominatimStatus = 'rate_limited';
                Log::channel('geocoding')->info('Nominatim skipped — daily cap reached', [
                    'normalised' => $normalised,
                ]);
            }
        }

        // ── 6. Cache as failed — ONLY when an external resolver ran ──────
        // When no external resolver ran (no key + disabled, both rate-limited,
        // etc.) we return the failure WITHOUT caching so the next attempt —
        // once the missing source comes online — re-runs the waterfall
        // instead of being short-circuited by a stale failure row.
        $failure = new GeocodingResult(null, null, 'failed', 'cache', null, false, 'no source matched');
        if (!$externalAttempted) {
            Log::channel('geocoding')->info('resolver returned failure without caching — no external resolver ran', [
                'normalised'       => $normalised,
                'suburb'           => $suburbNorm,
                'google_status'    => $googleStatus,
                'nominatim_status' => $nominatimStatus,
            ]);
            return $failure;
        }
        return $this->cacheAndReturn($normalised, $address, $failure, $suburbNorm);
    }

    /**
     * Branch 2 — search MIC subject GPS.
     *
     * Same address-needle extraction as MicSnapshotHydrator: strip leading
     * unit numbers, split on commas, LIKE-match against subject_address.
     */
    private function resolveFromMarketReports(string $address, ?string $suburbNorm): ?GeocodingResult
    {
        $needles = $this->addressNeedles($address);
        if (empty($needles) && $suburbNorm === null) return null;

        $query = MarketReport::query()
            ->withoutGlobalScopes()
            ->whereNotNull('subject_latitude')
            ->whereNotNull('subject_longitude');

        $query->where(function ($q) use ($needles, $suburbNorm) {
            foreach ($needles as $n) {
                $q->orWhereRaw('LOWER(subject_address) LIKE ?', ['%' . $n . '%']);
            }
            if ($suburbNorm !== null && $suburbNorm !== '') {
                $q->orWhereRaw('LOWER(source_suburb) = ?', [$suburbNorm]);
            }
        });

        $report = $query->orderByDesc('id')->first();
        if (!$report) return null;

        return new GeocodingResult(
            latitude:        (float) $report->subject_latitude,
            longitude:       (float) $report->subject_longitude,
            confidence:      'exact',
            source:          'market_report',
            municipality:    null,
            cached:          false,
            failureReason:   null,
            resolvedAddress: $report->subject_address,
        );
    }

    /**
     * Branch 3 — portal captures with lat/lng in extracted_fields_json.
     * We only consult property-detail pages (page_type='property'); the
     * search-page items rarely carry GPS.
     */
    private function resolveFromPortalCaptures(string $address, ?string $suburbNorm): ?GeocodingResult
    {
        $needles = $this->addressNeedles($address);
        if (empty($needles)) return null;

        $captures = PortalCapture::query()
            ->withoutGlobalScopes()
            ->where('page_type', 'property')
            ->where('parse_status', 'parsed')
            ->latest('id')
            ->limit(50)
            ->get(['id', 'extracted_fields_json']);

        foreach ($captures as $cap) {
            $fields = is_array($cap->extracted_fields_json)
                ? $cap->extracted_fields_json
                : ((array) $cap->extracted_fields_json ?: []);

            $title    = mb_strtolower((string) ($fields['title'] ?? ''));
            $location = mb_strtolower((string) ($fields['location'] ?? ''));
            $lat      = $fields['latitude'] ?? $fields['lat'] ?? null;
            $lng      = $fields['longitude'] ?? $fields['lng'] ?? null;

            if ($lat === null || $lng === null) continue;

            $haystack = $title . ' ' . $location;
            $hit = false;
            foreach ($needles as $n) {
                if (str_contains($haystack, $n)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) continue;

            return new GeocodingResult(
                latitude:        (float) $lat,
                longitude:       (float) $lng,
                confidence:      'street',
                source:          'portal_capture',
                municipality:    null,
                cached:          false,
                failureReason:   null,
                resolvedAddress: $fields['title'] ?? $fields['location'] ?? null,
            );
        }
        return null;
    }

    /**
     * Branch 4 — Google Geocoding API. Returns null on transient error so
     * the caller doesn't cache; returns a 'failed' result only on a
     * definitive "no result" response.
     */
    private function callGoogle(string $normalised, string $key): ?GeocodingResult
    {
        $url = 'https://maps.googleapis.com/maps/api/geocode/json';
        $address = $normalised . ', South Africa';

        try {
            $response = $this->http()->get($url, [
                'address' => $address,
                'key'     => $key,
                'region'  => 'za',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Google geocode network error', ['err' => $e->getMessage(), 'addr' => $normalised]);
            return null;
        }

        if ($response->failed() || $response->serverError()) {
            Log::warning('Google geocode HTTP error', ['status' => $response->status(), 'addr' => $normalised]);
            return null;
        }

        $body   = $response->json() ?: [];
        $status = $body['status'] ?? 'UNKNOWN';

        // Transient — retryable.
        if (in_array($status, ['OVER_QUERY_LIMIT', 'OVER_DAILY_LIMIT', 'REQUEST_DENIED', 'UNKNOWN_ERROR'], true)) {
            Log::warning('Google geocode transient status; not caching', ['status' => $status, 'addr' => $normalised]);
            return null;
        }

        if ($status !== 'OK' || empty($body['results'])) {
            return new GeocodingResult(null, null, 'failed', 'google', null, false, "google:{$status}");
        }

        $top  = $body['results'][0];
        $loc  = $top['geometry']['location'] ?? null;
        $type = $top['geometry']['location_type'] ?? 'APPROXIMATE';
        if (!$loc) {
            return new GeocodingResult(null, null, 'failed', 'google', null, false, 'no geometry');
        }

        $confidence = match ($type) {
            'ROOFTOP', 'RANGE_INTERPOLATED' => 'exact',
            'GEOMETRIC_CENTER'              => 'street',
            default                         => 'suburb',
        };

        $municipality = null;
        foreach (($top['address_components'] ?? []) as $comp) {
            $types = $comp['types'] ?? [];
            if (in_array('administrative_area_level_2', $types, true)
                || in_array('locality', $types, true)) {
                $municipality = $comp['long_name'] ?? null;
                if ($municipality) break;
            }
        }

        return new GeocodingResult(
            latitude:        (float) $loc['lat'],
            longitude:       (float) $loc['lng'],
            confidence:      $confidence,
            source:          'google',
            municipality:    $municipality,
            cached:          false,
            failureReason:   null,
            resolvedAddress: $top['formatted_address'] ?? null,
        );
    }

    /**
     * Branch 5 — Nominatim with 1 req/sec throttle + User-Agent. Same
     * null-vs-failed semantics as Google.
     */
    private function callNominatim(string $normalised): ?GeocodingResult
    {
        $this->throttleNominatim();
        $userAgent = (string) (config('services.nominatim.user_agent') ?: 'CoreXOS/1.0');

        try {
            $response = $this->http()
                ->withHeaders(['User-Agent' => $userAgent])
                ->get('https://nominatim.openstreetmap.org/search', [
                    'q'              => $normalised . ', South Africa',
                    'format'         => 'json',
                    'limit'          => 1,
                    'addressdetails' => 1,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Nominatim network error', ['err' => $e->getMessage(), 'addr' => $normalised]);
            return null;
        }

        if ($response->failed() || $response->serverError()) {
            return null;
        }

        $results = $response->json();
        if (!is_array($results) || empty($results)) {
            return new GeocodingResult(null, null, 'failed', 'nominatim', null, false, 'no result');
        }
        $top = $results[0];
        if (!isset($top['lat'], $top['lon'])) {
            return new GeocodingResult(null, null, 'failed', 'nominatim', null, false, 'no coords');
        }

        $municipality = $top['address']['municipality']
            ?? $top['address']['city']
            ?? $top['address']['town']
            ?? null;

        // Nominatim 'class' / 'type' gives us a hint about precision.
        $type = (string) ($top['type'] ?? '');
        $confidence = in_array($type, ['house', 'building'], true)
            ? 'exact'
            : (in_array($type, ['road', 'residential'], true) ? 'street' : 'suburb');

        return new GeocodingResult(
            latitude:        (float) $top['lat'],
            longitude:       (float) $top['lon'],
            confidence:      $confidence,
            source:          'nominatim',
            municipality:    $municipality,
            cached:          false,
            failureReason:   null,
            resolvedAddress: $top['display_name'] ?? null,
        );
    }

    private function throttleNominatim(): void
    {
        $now = microtime(true);
        if (self::$nominatimLastCallAt !== null) {
            $delta = $now - self::$nominatimLastCallAt;
            if ($delta < 1.05) {
                usleep((int) round((1.05 - $delta) * 1_000_000));
            }
        }
        self::$nominatimLastCallAt = microtime(true);
    }

    private function http(): PendingRequest
    {
        return Http::timeout(self::NETWORK_TIMEOUT_SEC)
            ->retry(1, 250, throw: false);
    }

    /**
     * Persist the result to cache + return a fresh DTO that flags cached=false
     * (this was a live resolution, even though future calls will see cached=true).
     */
    private function cacheAndReturn(
        string $normalised,
        string $rawAddress,
        GeocodingResult $result,
        ?string $suburbNorm,
    ): GeocodingResult {
        try {
            GeocodingCache::updateOrCreate(
                ['address_normalised' => $normalised],
                [
                    'address_raw'        => mb_substr($rawAddress, 0, 500),
                    'latitude'           => $result->latitude,
                    'longitude'          => $result->longitude,
                    'confidence'         => $result->confidence,
                    'source'             => $result->source,
                    'source_ref'         => null,
                    'resolved_address'   => $result->resolvedAddress
                        ? mb_substr($result->resolvedAddress, 0, 500)
                        : null,
                    'municipality_name'  => $result->municipality,
                    'suburb_normalised'  => $suburbNorm,
                    'failure_reason'     => $result->failureReason,
                    'last_attempted_at'  => now(),
                ],
            );
        } catch (\Throwable $e) {
            Log::warning('GeocodingCache write failed', ['err' => $e->getMessage(), 'addr' => $normalised]);
        }
        return $result;
    }

    /**
     * Same fragmenting logic as MicSnapshotHydrator: lowercased fragments
     * with leading numbers stripped, length ≥ 8.
     */
    private function addressNeedles(string $address): array
    {
        $address = trim($address);
        if ($address === '') return [];
        $needles = [];
        foreach (explode(',', $address) as $piece) {
            $piece = mb_strtolower(trim($piece));
            if (mb_strlen($piece) >= 8) $needles[] = $piece;
            $stripped = preg_replace('/^\d+\s+/', '', $piece);
            if ($stripped !== null && $stripped !== $piece && mb_strlen($stripped) >= 8) {
                $needles[] = $stripped;
            }
        }
        return array_values(array_unique($needles));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use App\Models\MarketReports\MarketReport;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Support\Geocoding\AddressNormaliser;
use App\Support\MarketAnalytics\HaversineDistance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Phase 3f B2/B3 — resolve GPS + municipal value for a single Property
 * or TrackedProperty by running its address through AddressResolverService
 * and (when successful) joining nearby MIC subject municipal_valuation
 * back to the row.
 *
 * Architectural decision (not pre-decided in spec):
 *   - Municipal valuations live as `market_data_points` metric_key=
 *     'municipal_valuation' (not on the market_reports row itself). We
 *     read them from that table and prefer the row whose source report
 *     has a subject GPS within 50m of the property's resolved GPS, with
 *     subject_address LIKE the property's normalised address as a
 *     pre-GPS fallback. Most recent metric_date wins.
 */
final class PropertyGeoBackfillService
{
    private const MUNICIPAL_GPS_MATCH_METRES = 50;

    public function __construct(
        private readonly AddressResolverService $resolver = new AddressResolverService(),
    ) {}

    /**
     * @return array{
     *   lat_lng_resolved: bool,
     *   municipal_value_resolved: bool,
     *   municipality_resolved: bool,
     *   source: ?string,
     *   confidence: ?string,
     *   latency_ms: int,
     *   batch_id?: string,
     * }
     */
    public function backfillProperty(Property $property, ?string $batchId = null, bool $force = false): array
    {
        $started = microtime(true);

        $latLngBefore = $this->hasGps($property);
        $munBefore    = !empty($property->municipal_valuation);

        // Force re-resolve overrides the !hasGps guard. Used by the
        // geocode endpoint when address fields change AND by the
        // suburb_centroid cleanup pass.
        $shouldResolve = !$latLngBefore || $force;

        $result = null;
        if ($shouldResolve) {
            $addressForResolve = $this->composePropertyAddress($property);
            $result = $this->resolver->resolve(
                $addressForResolve,
                $property->suburb,
                $property->town,
                context: 'property:' . $property->id . ($force ? ':force' : ''),
            );
            if ($result->hasGps()) {
                $property->latitude        = $result->latitude;
                $property->longitude       = $result->longitude;
                $property->geo_source      = $result->source;
                $property->geo_confidence  = $result->confidence;
                $property->geo_resolved_at = now();
            } else {
                // Preserve existing coords on force-resolve failure —
                // a transient Google quota error shouldn't blank out
                // a previously-valid pin. Only clear them on the
                // initial-resolve path (when there were no coords).
                if (!$latLngBefore) {
                    $property->geo_source      = 'unresolved';
                    $property->geo_confidence  = 'failed';
                    $property->geo_resolved_at = now();
                }
            }
            $property->saveQuietly();
        }

        // Municipal valuation backfill — only attempt if not already set.
        $munResolved = false;
        if (!$munBefore) {
            $mun = $this->findNearbyMunicipalValue(
                (float) ($property->latitude ?? 0),
                (float) ($property->longitude ?? 0),
                $property->address,
                $property->suburb,
            );
            if ($mun !== null) {
                $property->municipal_valuation      = $mun['value'];
                $property->municipal_valuation_year = $mun['year'];
                $property->saveQuietly();
                $munResolved = true;
            }
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $row = [
            'lat_lng_resolved'         => $latLngBefore || ($result !== null && $result->hasGps()),
            'municipal_value_resolved' => $munResolved,
            'municipality_resolved'    => $result?->municipality !== null,
            'source'                   => $result?->source ?? ($latLngBefore ? 'pre_existing' : null),
            'confidence'               => $result?->confidence ?? ($latLngBefore ? 'pre_existing' : null),
            'latency_ms'               => $latency,
        ];
        $this->log($batchId, 'property', $property->id, $property->address ?? '', $result, $latency);
        return $row;
    }

    /**
     * Same logic, mirrored for TrackedProperty. The TP row's "address" is
     * derived from street_number + street_name (plus complex_name when
     * present). Caller already handles the no-GPS case → unresolved.
     *
     * @return array{
     *   lat_lng_resolved: bool,
     *   municipal_value_resolved: bool,
     *   municipality_resolved: bool,
     *   source: ?string,
     *   confidence: ?string,
     *   latency_ms: int,
     * }
     */
    public function backfillTrackedProperty(TrackedProperty $tp, ?string $batchId = null): array
    {
        $started = microtime(true);

        $latLngBefore = $this->hasGps($tp);
        $munBefore    = !empty($tp->municipal_valuation);

        $address = trim(implode(' ', array_filter([
            $tp->street_number,
            $tp->street_name,
        ])));

        $result = null;
        // Gate opens when EITHER the composed street-address is non-empty
        // OR the TP has a suburb — so suburb-only TPs reach the resolver
        // (Google can still resolve them to suburb-level GPS). Mirrors the
        // staging hot-patch deployed 2026-05-27.
        if (!$latLngBefore && ($address !== '' || !empty($tp->suburb))) {
            $result = $this->resolver->resolve($address, $tp->suburb, $tp->town, 'tracked_property:' . $tp->id);
            if ($result->hasGps()) {
                $tp->latitude        = $result->latitude;
                $tp->longitude       = $result->longitude;
                $tp->geo_source      = $result->source;
                $tp->geo_confidence  = $result->confidence;
                $tp->geo_resolved_at = now();
            } else {
                $tp->geo_source      = 'unresolved';
                $tp->geo_confidence  = 'failed';
                $tp->geo_resolved_at = now();
            }
            $tp->saveQuietly();
        }

        $munResolved = false;
        if (!$munBefore) {
            $mun = $this->findNearbyMunicipalValue(
                (float) ($tp->latitude ?? 0),
                (float) ($tp->longitude ?? 0),
                $address,
                $tp->suburb,
            );
            if ($mun !== null) {
                $tp->municipal_valuation      = $mun['value'];
                $tp->municipal_valuation_year = $mun['year'];
                $tp->saveQuietly();
                $munResolved = true;
            }
        }

        $latency = (int) round((microtime(true) - $started) * 1000);
        $row = [
            'lat_lng_resolved'         => $latLngBefore || ($result !== null && $result->hasGps()),
            'municipal_value_resolved' => $munResolved,
            'municipality_resolved'    => $result?->municipality !== null,
            'source'                   => $result?->source ?? ($latLngBefore ? 'pre_existing' : null),
            'confidence'               => $result?->confidence ?? ($latLngBefore ? 'pre_existing' : null),
            'latency_ms'               => $latency,
        ];
        $this->log($batchId, 'tracked_property', $tp->id, $address, $result, $latency);
        return $row;
    }

    /**
     * Find a municipal_valuation metric_value tied to a market report whose
     * subject is geographically close to (or address-similar to) the target.
     *
     * @return array{value: int, year: ?int}|null
     */
    private function findNearbyMunicipalValue(
        float $lat,
        float $lng,
        ?string $address,
        ?string $suburb,
    ): ?array {
        $needles = $this->addressNeedles((string) $address);

        $candidateReports = MarketReport::query()
            ->withoutGlobalScopes()
            ->whereNotNull('subject_latitude')
            ->whereNotNull('subject_longitude')
            ->get(['id', 'subject_latitude', 'subject_longitude', 'subject_address']);

        $matchedReportId = null;
        // 1. GPS proximity (only when we have GPS for both sides).
        if ($lat !== 0.0 && $lng !== 0.0) {
            foreach ($candidateReports as $r) {
                $d = HaversineDistance::distanceMetres(
                    $lat,
                    $lng,
                    (float) $r->subject_latitude,
                    (float) $r->subject_longitude,
                );
                if ($d <= self::MUNICIPAL_GPS_MATCH_METRES) {
                    $matchedReportId = (int) $r->id;
                    break;
                }
            }
        }

        // 2. Address-needle fallback (no GPS or no nearby match).
        if ($matchedReportId === null && !empty($needles)) {
            foreach ($candidateReports as $r) {
                $subj = mb_strtolower((string) $r->subject_address);
                foreach ($needles as $n) {
                    if ($subj !== '' && str_contains($subj, $n)) {
                        $matchedReportId = (int) $r->id;
                        break 2;
                    }
                }
            }
        }

        if ($matchedReportId === null) return null;

        $row = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('report_id', $matchedReportId)
            ->where('metric_key', 'municipal_valuation')
            ->orderByDesc('id')
            ->first(['metric_value_numeric', 'metric_date']);
        if (!$row || empty($row->metric_value_numeric)) return null;

        return [
            'value' => (int) $row->metric_value_numeric,
            'year'  => $row->metric_date ? (int) substr((string) $row->metric_date, 0, 4) : null,
        ];
    }

    private function hasGps(Property|TrackedProperty $entity): bool
    {
        return $entity->latitude !== null
            && $entity->longitude !== null
            && (float) $entity->latitude !== 0.0
            && (float) $entity->longitude !== 0.0;
    }

    /**
     * Compose the most precise address string available for a property.
     * Street_number + street_name win when present (building-level
     * accuracy); fall back to the property.address free-text column
     * (often agent-typed). Suburb + town are passed separately so
     * AddressResolverService can append them with its own normalisation.
     */
    private function composePropertyAddress(Property $property): string
    {
        $structured = trim(implode(' ', array_filter([
            $property->street_number,
            $property->street_name,
        ])));
        if ($structured !== '') {
            return $structured;
        }
        return (string) ($property->address ?? '');
    }

    /**
     * "Is this pin suspicious enough to warrant re-resolution?"
     * Suburb-centroid pins (from the pre-fix Map strip JS) + unresolved
     * + low-confidence rows are all candidates. Used by the CLI batch
     * cleanup + by the observer hook.
     */
    public function pinIsSuspect(Property $property): bool
    {
        if (!$this->hasGps($property)) return false;
        $source = (string) ($property->geo_source ?? '');
        if (in_array($source, ['suburb_centroid', 'unresolved', '', 'nominatim_suburb'], true)) {
            return true;
        }
        $confidence = (string) ($property->geo_confidence ?? '');
        if (in_array($confidence, ['failed', 'low', ''], true)) {
            return true;
        }
        return false;
    }

    private function addressNeedles(string $address): array
    {
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

    private function log(
        ?string $batchId,
        string $entityType,
        int $entityId,
        string $address,
        ?GeocodingResult $result,
        int $latencyMs,
    ): void {
        try {
            DB::table('geocoding_runs')->insert([
                'batch_id'     => $batchId ?? (string) Str::uuid(),
                'entity_type'  => $entityType,
                'entity_id'    => $entityId,
                'address'      => mb_substr($address, 0, 500),
                'result'       => $result === null
                    ? 'cached'
                    : ($result->hasGps() ? 'resolved' : 'failed'),
                'source'       => $result?->source,
                'confidence'   => $result?->confidence,
                'latency_ms'   => $latencyMs,
                'created_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('geocoding_runs insert failed', ['err' => $e->getMessage()]);
        }
    }
}

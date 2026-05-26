<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Events\Prospecting\TrackedPropertyCreated;
use App\Events\Prospecting\TrackedPropertyEnriched;
use App\Events\Prospecting\TrackedPropertyPromotedToStock;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE central hub for Universal Match-or-Create.
 *
 * Every ingestion path in CoreX (CMA propagation, P24 alerts, PP feed,
 * Chrome capture, manual entry, mandate signing) calls matchOrCreate()
 * before deciding what to do with incoming property data.
 *
 * Resolution strategy (priority order, first match wins):
 *   1. Source-ref match — exact match in tracked_property_external_refs
 *   2. GPS proximity match — within ~5m on cma_gps_lat/lng OR lat/lng
 *   3. Erf number + suburb match
 *   4. Normalised address match (street_number + street_name + suburb_normalised)
 *   5. Token-overlap address match (loose, last resort)
 *
 * Always returns the TrackedProperty. Always appends to source_chain.
 * Always creates/updates the corresponding tracked_property_external_refs row.
 * Always fires the appropriate domain event.
 *
 * Multi-tenancy: every read query is built via queryWithoutAgencyScope() and
 * filtered by the explicit $agencyId parameter — safe to call from queue workers
 * and console commands where there is no Auth context.
 *
 * Spec: CLAUDE.md Universal Match-or-Create Rule;
 *       .ai/specs/market-intelligence-discovery.md Section 13.2 (gap 5);
 *       VS Code build prompt Build D.1 (2026-05-14).
 */
final class TrackedPropertyMatchOrCreateService
{
    /** ~5m GPS tolerance — small enough to distinguish neighbours, large enough to absorb portal vs deeds rounding drift. */
    private const GPS_TOLERANCE_DEGREES = 0.00005;

    /**
     * Fields where a newer source's value wins over an older value.
     * Other fields keep their first non-null value (first source wins for stable identifiers).
     */
    private const NEWER_WINS_FIELDS = [
        'municipal_valuation',
        'municipal_valuation_year',
        'last_known_asking_price',
        'last_known_sold_price',
        'last_known_sold_date',
    ];

    /**
     * Match or create a TrackedProperty. Always returns a TrackedProperty.
     *
     * @param int $agencyId
     * @param array $facts  Canonical property facts. Recognised keys (all optional):
     *                      street_number, street_name, unit_number, complex_name,
     *                      suburb, town, province, postal_code,
     *                      latitude, longitude, cma_gps_lat, cma_gps_lng,
     *                      erf_number, title_deed_number, cadastral_extent,
     *                      municipal_valuation, municipal_valuation_year,
     *                      last_known_asking_price, last_known_sold_price, last_known_sold_date,
     *                      property_type, bedrooms, bathrooms, garages,
     *                      floor_size_m2, erf_size_m2,
     *                      address  (free-text fallback used only for token-overlap match)
     * @param array $source Source descriptor: ['type' => string, 'ref' => string, 'payload' => array|null]
     * @param ?int $actorUserId
     */
    public function matchOrCreate(
        int $agencyId,
        array $facts,
        array $source,
        ?int $actorUserId = null,
    ): TrackedProperty {
        return DB::transaction(function () use ($agencyId, $facts, $source, $actorUserId) {
            $matched = $this->resolveMatch($agencyId, $facts, $source);

            return $matched
                ? $this->enrich($matched, $facts, $source, $actorUserId)
                : $this->create($agencyId, $facts, $source, $actorUserId);
        });
    }

    /**
     * 5-strategy resolution. First match wins. Returns null on no match.
     *
     * All queries bypass the global AgencyScope and filter by the explicit
     * $agencyId so the service is safe to invoke from background workers.
     */
    private function resolveMatch(int $agencyId, array $facts, array $source): ?TrackedProperty
    {
        // Strategy 1: Source-ref exact match (the strongest signal — a portal told us this is the same listing)
        if (!empty($source['type']) && !empty($source['ref'])) {
            $ref = TrackedPropertyExternalRef::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->where('source_type', (string) $source['type'])
                ->where('source_ref', (string) $source['ref'])
                ->whereNull('deleted_at')
                ->first();
            if ($ref) {
                $tp = TrackedProperty::queryWithoutAgencyScope()
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->find($ref->tracked_property_id);
                if ($tp) return $tp;
            }
        }

        // Strategy 2: GPS proximity (cma_gps preferred, then lat/lng).
        $lat = $facts['cma_gps_lat'] ?? $facts['latitude'] ?? null;
        $lng = $facts['cma_gps_lng'] ?? $facts['longitude'] ?? null;
        if ($lat !== null && $lng !== null) {
            $tol = self::GPS_TOLERANCE_DEGREES;
            $byCmaGps = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereBetween('cma_gps_lat', [$lat - $tol, $lat + $tol])
                ->whereBetween('cma_gps_lng', [$lng - $tol, $lng + $tol])
                ->first();
            if ($byCmaGps) return $byCmaGps;

            $byGps = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->whereBetween('latitude', [$lat - $tol, $lat + $tol])
                ->whereBetween('longitude', [$lng - $tol, $lng + $tol])
                ->first();
            if ($byGps) return $byGps;
        }

        // Strategy 3: Erf number + suburb (works even when address is unknown).
        if (!empty($facts['erf_number']) && !empty($facts['suburb'])) {
            $erfMatch = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('erf_number', trim((string) $facts['erf_number']))
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->first();
            if ($erfMatch) return $erfMatch;
        }

        // Strategy 4: Normalised structured address.
        if (!empty($facts['street_number']) && !empty($facts['street_name']) && !empty($facts['suburb'])) {
            $addressMatch = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('street_number', trim((string) $facts['street_number']))
                ->where('street_name', $this->normaliseStreetName($facts['street_name']))
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->first();
            if ($addressMatch) return $addressMatch;
        }

        // Strategy 5: Token-overlap address match (loose, last resort).
        if (!empty($facts['suburb']) && (!empty($facts['street_name']) || !empty($facts['address']))) {
            $candidates = TrackedProperty::queryWithoutAgencyScope()
                ->where('agency_id', $agencyId)
                ->whereNull('deleted_at')
                ->where('suburb_normalised', TrackedProperty::normaliseSuburb($facts['suburb']))
                ->limit(50)
                ->get();

            $factTokens = $this->extractAddressTokens(
                ($facts['street_number'] ?? '') . ' ' . ($facts['street_name'] ?? $facts['address'] ?? '')
            );

            if (!empty($factTokens)) {
                foreach ($candidates as $cand) {
                    $candTokens = $this->extractAddressTokens(
                        ($cand->street_number ?? '') . ' ' . ($cand->street_name ?? '')
                    );
                    $overlap = array_intersect($factTokens, $candTokens);
                    if (count($overlap) >= 2) {
                        return $cand;
                    }
                }
            }
        }

        return null;
    }

    private function create(int $agencyId, array $facts, array $source, ?int $actorUserId): TrackedProperty
    {
        $tp = TrackedProperty::create(array_merge(
            $this->canonicalFactsForWrite($facts),
            [
                'agency_id'              => $agencyId,
                'source_chain'           => [$this->buildSourceChainEntry($source, $facts)],
                'first_seen_at'          => now(),
                'last_enriched_at'       => now(),
                'last_enrichment_source' => $source['type'] ?? 'unknown',
                'status'                 => TrackedProperty::STATUS_ACTIVE,
            ]
        ));

        $this->writeExternalRef($agencyId, (int) $tp->id, $source);

        event(new TrackedPropertyCreated(
            trackedPropertyId: (int) $tp->id,
            agencyId: $agencyId,
            sourceType: (string) ($source['type'] ?? 'unknown'),
            actorUserId: $actorUserId,
        ));

        return $tp;
    }

    private function enrich(
        TrackedProperty $tp,
        array $newFacts,
        array $source,
        ?int $actorUserId,
    ): TrackedProperty {
        $sanitised = $this->canonicalFactsForWrite($newFacts);

        // Walk the sanitised facts only (all scalars). Build a diff against the
        // current TP values using fillable-column comparisons. Skips array-cast
        // columns by construction — canonicalFactsForWrite is scalar-only.
        $diff = [];
        foreach ($sanitised as $key => $newVal) {
            $existing = $tp->{$key} ?? null;
            // Empty existing → adopt the new value (covers most enrichments).
            if ($existing === null || $existing === '') {
                $diff[$key] = $newVal;
                continue;
            }
            // For newer-wins fields, write whenever the value differs.
            if (in_array($key, self::NEWER_WINS_FIELDS, true)
                && (string) $existing !== (string) $newVal) {
                $diff[$key] = $newVal;
                continue;
            }
            // Otherwise the existing value stands (first source wins for stable identifiers).
        }

        // Bookkeeping: always set on enrich.
        $diff['last_enriched_at']       = now();
        $diff['last_enrichment_source'] = $source['type'] ?? 'unknown';

        // Append-only source_chain.
        $chain   = $tp->source_chain ?? [];
        $chain[] = $this->buildSourceChainEntry($source, $newFacts);
        $diff['source_chain'] = $chain;

        $tp->update($diff);
        $this->writeExternalRef((int) $tp->agency_id, (int) $tp->id, $source);

        // Field-additions excludes the bookkeeping columns so consumers see only
        // the meaningful business-data delta.
        $bookkeeping = ['last_enriched_at', 'last_enrichment_source', 'source_chain'];
        $fieldsAdded = array_values(array_diff(array_keys($diff), $bookkeeping));

        event(new TrackedPropertyEnriched(
            trackedPropertyId: (int) $tp->id,
            agencyId: (int) $tp->agency_id,
            sourceType: (string) ($source['type'] ?? 'unknown'),
            fieldsAdded: $fieldsAdded,
            actorUserId: $actorUserId,
        ));

        return $tp->fresh();
    }

    private function writeExternalRef(int $agencyId, int $trackedPropertyId, array $source): void
    {
        if (empty($source['type']) || empty($source['ref'])) return;

        $existing = TrackedPropertyExternalRef::queryWithoutAgencyScope()
            ->where('agency_id', $agencyId)
            ->where('source_type', (string) $source['type'])
            ->where('source_ref', (string) $source['ref'])
            ->whereNull('deleted_at')
            ->first();

        if ($existing) {
            $existing->update([
                'tracked_property_id' => $trackedPropertyId,
                'source_payload'      => $source['payload'] ?? $existing->source_payload,
                'last_seen_at'        => now(),
            ]);
            return;
        }

        TrackedPropertyExternalRef::create([
            'agency_id'           => $agencyId,
            'tracked_property_id' => $trackedPropertyId,
            'source_type'         => (string) $source['type'],
            'source_ref'          => (string) $source['ref'],
            'source_payload'      => $source['payload'] ?? null,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
        ]);
    }

    private function buildSourceChainEntry(array $source, array $facts): array
    {
        return [
            'type'               => (string) ($source['type'] ?? 'unknown'),
            'ref'                => isset($source['ref']) ? (string) $source['ref'] : null,
            'date'               => Carbon::now()->toIso8601String(),
            'fields_contributed' => array_keys(array_filter($facts, fn ($v) => $v !== null && $v !== '')),
        ];
    }

    /**
     * Whitelist + sanitise the fact array to TrackedProperty's writable columns.
     */
    private function canonicalFactsForWrite(array $facts): array
    {
        static $writable = [
            'street_number', 'street_name', 'unit_number', 'complex_name',
            'suburb', 'town', 'province', 'postal_code',
            'latitude', 'longitude', 'cma_gps_lat', 'cma_gps_lng',
            'erf_number', 'title_deed_number', 'cadastral_extent',
            'municipal_valuation', 'municipal_valuation_year',
            'last_known_asking_price', 'last_known_sold_price', 'last_known_sold_date',
            'property_type', 'bedrooms', 'bathrooms', 'garages',
            'floor_size_m2', 'erf_size_m2',
        ];

        $out = [];
        foreach ($writable as $col) {
            if (array_key_exists($col, $facts) && $facts[$col] !== null && $facts[$col] !== '') {
                $out[$col] = $facts[$col];
            }
        }

        // Normalise street name on write so identical addresses written under different
        // spellings land in the same row when matched later.
        if (!empty($out['street_name'])) {
            $out['street_name'] = $this->normaliseStreetName($out['street_name']);
        }

        return $out;
    }

    /**
     * Canonicalise street name so "Mitchell St" and "MITCHELL STREET" land in the same bucket.
     */
    private function normaliseStreetName(?string $name): ?string
    {
        if ($name === null || $name === '') return null;
        $name = trim($name);
        $name = preg_replace('/\bst\.?\b/i', 'Street', $name);
        $name = preg_replace('/\brd\.?\b/i', 'Road', $name);
        $name = preg_replace('/\bave\.?\b/i', 'Avenue', $name);
        $name = preg_replace('/\bdr\.?\b/i', 'Drive', $name);
        $name = preg_replace('/\blane\.?\b/i', 'Lane', $name);
        $name = preg_replace('/\bcl(?:o)?se?\.?\b/i', 'Close', $name);
        return ucwords(mb_strtolower((string) $name));
    }

    private function extractAddressTokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = preg_replace('/[^\w\s]/u', ' ', $s);
        $tokens = preg_split('/\s+/', trim((string) $s)) ?: [];
        return array_values(array_filter($tokens, fn ($t) => strlen($t) >= 3));
    }

    /**
     * Promote a Tracked Property to Agency Stock.
     *
     * Creates a Property row, links the TP to it via promoted_to_property_id,
     * and preserves the entire source_chain for audit.
     *
     * Defence-of-NOT-NULL: properties.agent_id and properties.branch_id are NOT NULL
     * on the schema with no defaults. The promoting user supplies both — agent_id
     * defaults to the promoting user, branch_id to their branch. Caller may override
     * either via $propertyFields.
     */
    public function promoteToStock(
        int $trackedPropertyId,
        int $promotingUserId,
        array $propertyFields = [],
    ): Property {
        return DB::transaction(function () use ($trackedPropertyId, $promotingUserId, $propertyFields) {
            $tp = TrackedProperty::queryWithoutAgencyScope()->findOrFail($trackedPropertyId);

            if ($tp->isPromoted()) {
                return $tp->promotedProperty;
            }

            $user = User::find($promotingUserId);
            $defaultBranchId = $user?->branch_id ?? null;
            if ($defaultBranchId === null && !array_key_exists('branch_id', $propertyFields)) {
                throw new \DomainException(
                    "Cannot promote TrackedProperty #{$trackedPropertyId}: user #{$promotingUserId} has no branch_id and none was supplied in propertyFields."
                );
            }

            $property = Property::create(array_merge(
                [
                    'agency_id'                => $tp->agency_id,
                    'agent_id'                 => $promotingUserId,
                    'branch_id'                => $defaultBranchId,
                    'address'                  => $tp->displayAddress(),
                    'street_number'            => $tp->street_number,
                    'street_name'              => $tp->street_name,
                    'suburb'                   => $tp->suburb,
                    'town'                     => $tp->town,
                    'province'                 => $tp->province,
                    'latitude'                 => $tp->latitude,
                    'longitude'                => $tp->longitude,
                    'cma_gps_lat'              => $tp->cma_gps_lat,
                    'cma_gps_lng'              => $tp->cma_gps_lng,
                    'erf_number'               => $tp->erf_number,
                    'title_deed_number'        => $tp->title_deed_number,
                    'municipal_valuation'      => $tp->municipal_valuation,
                    'municipal_valuation_year' => $tp->municipal_valuation_year,
                    'property_type'            => $tp->property_type ?? 'house',
                    'beds'                     => $tp->bedrooms ?? 0,
                    'baths'                    => $tp->bathrooms ?? 0,
                    'garages'                  => $tp->garages ?? 0,
                    'price'                    => $tp->last_known_asking_price ?? 0,
                    'title'                    => $tp->displayAddress(),
                    'status'                   => 'draft',
                    'listing_type'             => 'sale',
                    // external_id auto-generated by Property's creating hook (char(36) UUID).
                    // The TP↔Property linkage is preserved by tracked_properties.promoted_to_property_id.
                ],
                $propertyFields
            ));

            $tp->update([
                'promoted_to_property_id' => $property->id,
                'promoted_at'             => now(),
                'promoted_by_user_id'     => $promotingUserId,
                'status'                  => TrackedProperty::STATUS_PROMOTED,
            ]);

            event(new TrackedPropertyPromotedToStock(
                trackedPropertyId: (int) $tp->id,
                propertyId: (int) $property->id,
                agencyId: (int) $tp->agency_id,
                actorUserId: $promotingUserId,
            ));

            // Mandate pillar: promotion from tracked → stock is the architectural
            // conversion moment. Spec .ai/specs/corex-domain-events-spec.md.
            event(new \App\Events\Mandate\MandateConverted(
                mandate: $tp,
                deal: null,
                agencyIdHint: (int) $tp->agency_id,
                actorUserId: $promotingUserId,
            ));

            return $property;
        });
    }
}

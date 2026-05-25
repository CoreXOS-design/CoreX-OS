<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Support\Geocoding\AddressNormaliser;

/**
 * Phase A.1 — composite-pin grouping for the Map module.
 *
 * Takes a flat list of map records (from MapPinService — one per pin per
 * category, the V1 shape) and collapses them into one entry per geographic
 * "location" so every data point sharing a building / street address renders
 * as ONE composite pin with `record_count >= 2` + `is_composite=true`.
 *
 * Grouping authority (spec-locked):
 *   1. AddressNormaliser::parse($record['address'])['geocode_target']
 *      — for sectional title schemes this collapses every unit onto the
 *      scheme's street address, so "36 Ss Topanga, 2587 Colin Rd",
 *      "37 Ss Topanga, 2587 Colin Rd", "Topanga § 12" all share one pin.
 *   2. Fallback when geocode_target is null (rare — happens when the row
 *      has no parseable address, e.g. scheme owners with scheme name only):
 *      lat/lng rounded to 5 decimal places (~1.1m precision).
 *
 * The grouper does NOT mutate input. It returns a list of location dicts:
 *
 *   [
 *     'location_key'       => '<sha256 of geocode_target | gps_round>',
 *     'latitude'           => float (from primary record — first by category priority),
 *     'longitude'          => float,
 *     'geocode_target'     => string|null,
 *     'record_count'       => int,
 *     'is_composite'       => bool   (record_count > 1),
 *     'primary_category'   => string (tallest-stack-wins by priority),
 *     'categories_present' => string[],
 *     'records'            => array<int, RecordDict>,
 *   ]
 *
 * Records inside each location keep their full original shape; the caller
 * (MapController response builder) decides how to serialise them.
 */
final class LocationGrouper
{
    /**
     * Category priority — higher = wins as primary record for the pin.
     * Same order as the V1 cross-layer coalesce, kept for visual continuity.
     */
    private const PRIORITY = [
        'hfc_listings'    => 1000,
        'active_listings' => 800,
        'sold_comps'      => 600,
        'mic_subjects'    => 400,
        'scheme_owners'   => 200,
    ];

    /**
     * @param array<int, array<string, mixed>> $records
     *   Each: ['category', 'lat', 'lng', 'address'?, ...arbitrary fields...]
     * @return array<int, array<string, mixed>>
     */
    public function group(array $records): array
    {
        $byKey = [];

        foreach ($records as $rec) {
            if (!isset($rec['lat'], $rec['lng'])) continue;
            $key = $this->keyFor($rec);
            if ($key === null) continue;

            if (!isset($byKey[$key])) {
                $byKey[$key] = [
                    'location_key'       => 'sha256:' . hash('sha256', $key),
                    'grouping_basis'     => str_starts_with($key, 'gps:') ? 'gps' : 'geocode_target',
                    'geocode_target'     => str_starts_with($key, 'geo:') ? substr($key, 4) : null,
                    'latitude'           => (float) $rec['lat'],
                    'longitude'          => (float) $rec['lng'],
                    'records'            => [],
                    'categories_present' => [],
                ];
            }

            $byKey[$key]['records'][] = $rec;
            if (!in_array($rec['category'], $byKey[$key]['categories_present'], true)) {
                $byKey[$key]['categories_present'][] = $rec['category'];
            }
        }

        $out = [];
        foreach ($byKey as $loc) {
            // Sort records by category priority (primary first), then by id
            // for deterministic ordering on retry/replay.
            usort($loc['records'], function ($a, $b) {
                $pa = self::PRIORITY[$a['category']] ?? 0;
                $pb = self::PRIORITY[$b['category']] ?? 0;
                if ($pa !== $pb) return $pb - $pa;
                return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
            });

            // Re-anchor latitude/longitude to the primary record so the pin
            // sits on whichever source has the most precise GPS (HFC > MIC >
            // schemes that inherited GPS from a sibling report).
            $primary = $loc['records'][0];
            $loc['latitude']  = (float) $primary['lat'];
            $loc['longitude'] = (float) $primary['lng'];
            $loc['primary_category'] = (string) $primary['category'];
            $loc['record_count']     = count($loc['records']);
            $loc['is_composite']     = $loc['record_count'] > 1;

            // A.2.3 Item 2 — distinct visual classes so the client can render
            // sectional schemes differently from cross-category composites.
            //   'single'    — one record only
            //   'scheme'    — multi-record AND every record is a scheme owner
            //                 (sectional title building — render as a labelled
            //                 building pin, not a generic composite)
            //   'composite' — multi-record, mixed categories
            if ($loc['record_count'] === 1) {
                $loc['display_as'] = 'single';
            } elseif (collect($loc['records'])->every(fn ($r) => ($r['category'] ?? null) === 'scheme_owners')) {
                $loc['display_as'] = 'scheme';
            } else {
                $loc['display_as'] = 'composite';
            }

            // For scheme displays, surface the canonical scheme name so the
            // client doesn't have to split " § " on every record's title.
            if ($loc['display_as'] === 'scheme') {
                $first = (string) ($primary['title'] ?? '');
                $loc['scheme_name'] = trim(explode(' § ', $first, 2)[0]) ?: 'Sectional Scheme';
            }

            $out[] = $loc;
        }

        // Stable order: composite first (more interesting), then by record
        // count desc, then north-to-south for visual top-down feel.
        usort($out, function ($a, $b) {
            if ($a['is_composite'] !== $b['is_composite']) {
                return $a['is_composite'] ? -1 : 1;
            }
            if ($a['record_count'] !== $b['record_count']) {
                return $b['record_count'] - $a['record_count'];
            }
            return $b['latitude'] <=> $a['latitude'];
        });

        return $out;
    }

    /**
     * Compute the grouping key for a record. Returns null when no usable
     * basis exists (no address, no GPS).
     *
     * **Precedence (A.1.1 fix — was reversed in initial A.1 ship):**
     *   1. GPS rounded to 5dp (~1m precision) — primary, because every
     *      mappable record by definition has lat/lng, and two records sharing
     *      the same building rarely disagree on coordinates.
     *   2. geocode_target as fallback — only consulted when lat/lng are null
     *      (ungeocoded TPs etc.).
     *
     * Why the swap: the initial geocode_target-first design produced spurious
     * duplicate pins when two records at identical GPS happened to parse to
     * different geocode_target strings (e.g. Property "Highland Park, 12" →
     * "highland park, shelly beach" vs MIC report "Highland Park" → "highland
     * park"). With GPS as the authority both collapse to one composite pin —
     * the spec's intended behaviour.
     *
     * Key prefix encodes the basis so the response can report it back:
     *   "gps:-30.87953:30.36548" — primary, the common case
     *   "geo:sunset manor"       — fallback, no GPS available
     */
    private function keyFor(array $rec): ?string
    {
        if (isset($rec['lat'], $rec['lng'])
            && is_numeric($rec['lat']) && is_numeric($rec['lng'])
            && (float) $rec['lat'] !== 0.0 && (float) $rec['lng'] !== 0.0) {
            return sprintf('gps:%.5f:%.5f', (float) $rec['lat'], (float) $rec['lng']);
        }

        $address = $rec['address'] ?? null;
        $suburb  = $rec['suburb']  ?? null;
        if (is_string($address) && trim($address) !== '') {
            $parsed = AddressNormaliser::parse($address, is_string($suburb) ? $suburb : null);
            $target = $parsed['geocode_target'];
            if (is_string($target) && trim($target) !== '') {
                return 'geo:' . mb_strtolower(trim($target));
            }
        }

        return null;
    }
}

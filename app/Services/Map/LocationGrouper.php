<?php

declare(strict_types=1);

namespace App\Services\Map;

use App\Support\Geocoding\AddressNormaliser;
use App\Support\Geocoding\PropertyAddressKey;

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
     *
     * Q3 taxonomy (post-map-pin-taxonomy investigation Finding #3):
     *   prospecting (H > P > S) wins primacy, CMA (M / O) is subordinate,
     *   T (tracked_properties) is the spine — present in many composites
     *   but never the primary pin when something else is at the same
     *   address. T defaults to 100 so it always loses primacy to every
     *   real-world layer; previously T was absent from this list and
     *   defaulted to 0 implicitly, which was the same numerical outcome
     *   but undocumented. Made explicit here.
     *
     * Priority governs COMPOSITING ONLY, never EXISTENCE — a T-alone
     * location still renders first-class as a tracked pin, and any
     * composite containing T carries a `has_tracked_record` badge data
     * flag for the UI to render (visual treatment lands in a later
     * visual track, per Q3's "DATA flag only this pass" rule).
     */
    private const PRIORITY = [
        'hfc_listings'       => 1000,
        'active_listings'    =>  800,
        'sold_comps'         =>  600,
        'mic_subjects'       =>  400,
        'scheme_owners'      =>  200,
        'tracked_properties' =>  100,
    ];

    /**
     * Categories considered CMA-information rather than prospecting peers.
     * M is the CMA subject. O (scheme_owners) is a CMA-derived listing of
     * units within a sectional title scheme. Per Q3 taxonomy, M collapses
     * into the primary pin when prospecting/own/tracked records share its
     * address; O stays as a peer because a scheme-owners pin is a useful
     * standalone "this scheme has N units" surface that doesn't duplicate
     * the H/S/P/T identity of a specific unit.
     *
     * Used by:
     *   - the M-collapse step in group() (only M is collapsed)
     *   - the is_cma_orphan flag (true when no NON-CMA peers exist; O at
     *     the same address as M still counts as a CMA-only location).
     */
    private const CMA_CATEGORIES = ['mic_subjects', 'scheme_owners'];

    /** Just the categories that collapse into `cma_info` (subset of CMA_CATEGORIES). */
    private const COLLAPSING_CMA_CATEGORIES = ['mic_subjects'];

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

        // ── Dedup foundation Q4 Phase B Step 5 — address-key second-pass merge ──
        //
        // CRITICAL ORDERING: this merge runs AFTER the GPS-bucket build
        // (above) and BEFORE the M-collapse / is_cma_orphan / priority
        // post-processing loop (below). Reason: an M-subject and an H-
        // property at the same real-world address can land in DIFFERENT
        // GPS buckets when their geocoders disagree by more than ~1m (the
        // 5dp GPS-bucket resolution). Without this merge, M-collapse fires
        // on each bucket independently — H bucket has no M to collapse,
        // M bucket has no H peer so M stays as an orphan pin → duplicate
        // pins at one real-world address. After the merge, the two
        // buckets are one entry and M-collapse correctly folds M into
        // the H pin's cma_info.
        //
        // The merge does NOT widen the GPS bucket — two flats in the same
        // building with different unit numbers produce DIFFERENT
        // PropertyAddressKey outputs (unit_number is in the key tail)
        // and stay as separate locations.
        $byKey = $this->mergeByAddressKey($byKey);

        $out = [];
        foreach ($byKey as $loc) {
            // ── Q3 M-collapse step ───────────────────────────────────────
            // CMA subject (M) is NOT a peer when a prospecting/own/tracked
            // record shares the same address. Extract M records into a
            // separate `cma_info` array and remove them from `records[]`
            // so the composite shows the agent ONE pin owned by the
            // prospecting layer with CMA context attached, not a competing
            // M pin at the same dot. Standalone M (no non-CMA peers) stays
            // in records[] so it renders as today + the is_cma_orphan flag.
            $hasNonCmaPeer = collect($loc['records'])->contains(
                fn ($r) => !in_array(($r['category'] ?? null), self::CMA_CATEGORIES, true),
            );
            $loc['cma_info'] = [];
            if ($hasNonCmaPeer) {
                $kept = [];
                foreach ($loc['records'] as $r) {
                    if (in_array(($r['category'] ?? null), self::COLLAPSING_CMA_CATEGORIES, true)) {
                        $loc['cma_info'][] = [
                            'id'               => $r['id']               ?? null,
                            'title'            => $r['title']             ?? null,
                            'subtitle'         => $r['subtitle']          ?? null,
                            'detail_url'       => $r['detail_url']        ?? null,
                            'parent_report_id' => $r['parent_report_id']  ?? ($r['id'] ?? null),
                            'date'             => $r['date']              ?? null,
                            'report_type_name' => $r['report_type_name']  ?? null,
                            'report_type_key'  => $r['report_type_key']   ?? null,
                        ];
                        continue;
                    }
                    $kept[] = $r;
                }
                $loc['records'] = $kept;
                // After collapsing, categories_present must drop any
                // collapsed CMA categories that no longer have peer records.
                $loc['categories_present'] = array_values(array_unique(array_map(
                    fn ($r) => $r['category'],
                    $loc['records'],
                )));
            }

            // ── Q3 orphan-CMA flag ──────────────────────────────────────
            // Set when every record at this location is in the CMA bucket
            // (M and/or O). Visual treatment ("faint" marker) is the later
            // visual track — this pass only emits the data flag.
            $loc['is_cma_orphan'] = !empty($loc['records']) && collect($loc['records'])->every(
                fn ($r) => in_array(($r['category'] ?? null), self::CMA_CATEGORIES, true),
            );

            // ── Q3 tracked-badge flag ───────────────────────────────────
            // Set when any record at this location is a tracked_properties
            // record AND the location is composite (multi-record). T-alone
            // (record_count=1) is NOT a badge — it's a first-class pin in
            // its own right. The badge is for "this location ALSO has a
            // tracked record beside its primary identity".
            $loc['has_tracked_record'] = count($loc['records']) > 1 && collect($loc['records'])->contains(
                fn ($r) => ($r['category'] ?? null) === 'tracked_properties',
            );

            // Locations whose ONLY record was an M that we just collapsed
            // into cma_info will have an empty records[] now. They become
            // CMA-orphan candidates rendered from the cma_info itself.
            // For shape consistency, drop them from the output — they have
            // no record to anchor a pin. The cma_info attaches via the
            // sibling location that triggered the collapse (the H/P/S/T
            // pin that "stole" the M peer record).
            if (empty($loc['records'])) {
                continue;
            }

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

            // A.2.6 — context-driven hover summary built server-side. Same
            // structured shape for single + composite pins so the client
            // renders them with one template. Five priority cases cascade:
            //   1) HFC active listing present  → HFC details headline
            //   2) Sectional Schemes only      → scheme name + unit count
            //   3) All-same-category, non-HFC  → category-specific summary
            //   4) Mixed categories            → category breakdown
            //   5) Single pin                  → title + subtitle (footer empty)
            $loc['hover_summary'] = $this->buildHoverSummary($loc);

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
     * Dedup foundation Q4 Phase B Step 5 — address-key second-pass merge.
     *
     * Walks the GPS-keyed buckets, computes a `PropertyAddressKey` for
     * each (using the highest-category-priority record's address as the
     * authoritative source — the H record's title beats the M record's
     * title for accuracy when both are present), and merges buckets that
     * share the same key into a single location.
     *
     * Buckets that compute a null address-key (no usable address) stay
     * separate — they're handled by the GPS-only path. The merge is
     * additive: when two buckets merge, the survivor keeps its
     * location_key + grouping_basis + initial lat/lng; the existing
     * post-processing loop later re-anchors lat/lng to the primary
     * record's coordinates.
     *
     * @param  array<string, array<string,mixed>> $byKey
     * @return array<string, array<string,mixed>>
     */
    private function mergeByAddressKey(array $byKey): array
    {
        $addrToKey = [];      // address-key → first $byKey key seen with it
        $mergePlan = [];      // source $byKey key → target $byKey key

        foreach ($byKey as $gpsKey => $loc) {
            // Pick the highest-priority record that has a parseable
            // address — its title is the canonical source for this
            // bucket's address-key.
            $bestRec = null;
            $bestPri = -1;
            foreach ($loc['records'] as $rec) {
                if (empty($rec['address']) && empty($rec['title'])) continue;
                $pri = self::PRIORITY[$rec['category'] ?? ''] ?? 0;
                if ($pri > $bestPri) {
                    $bestRec = $rec;
                    $bestPri = $pri;
                }
            }
            if ($bestRec === null) continue;

            $address = (string) ($bestRec['address'] ?? $bestRec['title'] ?? '');
            $suburb  = isset($bestRec['suburb']) && is_string($bestRec['suburb']) ? $bestRec['suburb'] : null;
            $key     = PropertyAddressKey::fromAddressString($address, $suburb);
            if ($key === null) continue;        // unparseable — leave on GPS-only path

            if (isset($addrToKey[$key])) {
                // This bucket needs to merge into the one that claimed
                // the address-key first. Defer the actual move until
                // after the scan so we don't mutate $byKey mid-iteration.
                $mergePlan[$gpsKey] = $addrToKey[$key];
            } else {
                $addrToKey[$key] = $gpsKey;
            }
        }

        // Execute the merges. Source bucket's records + categories_present
        // get concatenated into target; source is then dropped.
        foreach ($mergePlan as $source => $target) {
            if (!isset($byKey[$source]) || !isset($byKey[$target])) continue;
            foreach ($byKey[$source]['records'] as $r) {
                $byKey[$target]['records'][] = $r;
                $cat = $r['category'] ?? null;
                if ($cat !== null && !in_array($cat, $byKey[$target]['categories_present'], true)) {
                    $byKey[$target]['categories_present'][] = $cat;
                }
            }
            unset($byKey[$source]);
        }

        return $byKey;
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

    /**
     * A.2.6 — build the hover_summary block for a location. Returns
     * {title, subtitle, footer} where subtitle/footer may be empty strings.
     *
     * Priority order (first match wins):
     *   1. HFC active listing present → "HFC: 3bed · R 1,420,000 · listed 22d"
     *      + "+N other records" footer when N > 0.
     *   2. All scheme_owners (sectional schemes pin) → scheme name + "N units".
     *   3. All-same-category non-scheme → "5 sold comps · most recent {date}".
     *   4. Mixed categories → "5 records · 2 sold, 1 MIC, 1 portal".
     *   5. Single pin → record title + subtitle (no footer).
     *
     * @param array<string, mixed> $loc
     * @return array{title:string, subtitle:string, footer:string}
     */
    private function buildHoverSummary(array $loc): array
    {
        $records = $loc['records'] ?? [];
        $count   = (int) ($loc['record_count'] ?? count($records));

        // 5. Single pin — easiest case, full title + subtitle.
        if ($count === 1) {
            $rec = $records[0] ?? [];
            return [
                'title'    => $this->shortAddress((string) ($rec['title'] ?? '')),
                'subtitle' => (string) ($rec['subtitle'] ?? ''),
                'footer'   => '',
            ];
        }

        $address = $this->shortAddress(
            (string) ($loc['geocode_target'] ?? ($records[0]['title'] ?? ''))
        );

        // 1. HFC active listing wins the headline (sold HFC properties carry
        // status=sold and surface under sold_comps' category — for hover we
        // only single-out the LIVE listing case).
        $hfc = collect($records)->first(fn ($r) => ($r['category'] ?? null) === 'hfc_listings');
        if ($hfc) {
            $subtitle = $this->hfcHoverLine($hfc);
            $other    = $count - 1;
            return [
                'title'    => $address,
                'subtitle' => $subtitle,
                'footer'   => $other > 0 ? '+' . $other . ' other record' . ($other === 1 ? '' : 's') : '',
            ];
        }

        // 2. Sectional Schemes pin.
        if (($loc['display_as'] ?? '') === 'scheme') {
            $schemeName = (string) ($loc['scheme_name'] ?? 'Sectional Scheme');
            return [
                'title'    => $schemeName,
                'subtitle' => $count . ' unit' . ($count === 1 ? '' : 's'),
                'footer'   => '',
            ];
        }

        // 3. All-same-category non-scheme.
        $uniqueCats = collect($records)->pluck('category')->unique()->values();
        if ($uniqueCats->count() === 1) {
            $cat = $uniqueCats->first();
            $subtitle = match ($cat) {
                'sold_comps'      => $count . ' sold comps' . $this->mostRecentDate($records, ' · most recent '),
                'active_listings' => $count . ' Portal Stock listings',
                'mic_subjects'    => $count . ' MIC subjects',
                'scheme_owners'   => $count . ' scheme owners',
                default           => $count . ' records',
            };
            return [
                'title'    => $address,
                'subtitle' => $subtitle,
                'footer'   => '',
            ];
        }

        // 4. Mixed categories.
        return [
            'title'    => $address,
            'subtitle' => $count . ' records · ' . $this->categoryBreakdown($records),
            'footer'   => '',
        ];
    }

    /** "HFC: house · R 1,420,000" — uses the V1 subtitle we already compose
     *  in MapPinService::formatPropertySubtitle as the suffix. Falls back to
     *  a bare "HFC listing" when subtitle is empty. */
    private function hfcHoverLine(array $rec): string
    {
        $sub = trim((string) ($rec['subtitle'] ?? ''));
        return $sub !== '' ? 'HFC: ' . $sub : 'HFC listing';
    }

    /** "2 sold, 1 MIC, 1 portal" — short comma-separated category list. */
    private function categoryBreakdown(array $records): string
    {
        $counts = collect($records)->groupBy('category')->map->count();
        $labels = [
            'sold_comps'      => 'sold',
            'active_listings' => 'portal',
            'mic_subjects'    => 'MIC',
            'scheme_owners'   => 'scheme',
            'hfc_listings'    => 'HFC',
        ];
        $parts = [];
        foreach ($counts as $cat => $n) {
            $parts[] = $n . ' ' . ($labels[$cat] ?? $cat);
        }
        return implode(', ', $parts);
    }

    /** Returns " · most recent 12 May 2024" or '' if no usable date. */
    private function mostRecentDate(array $records, string $prefix): string
    {
        $best = null;
        foreach ($records as $r) {
            $d = $r['date'] ?? null;
            if (!$d) continue;
            try {
                $c = \Carbon\Carbon::parse($d);
                if ($best === null || $c->greaterThan($best)) $best = $c;
            } catch (\Throwable) { /* skip unparseable */ }
        }
        return $best ? $prefix . $best->format('j M Y') : '';
    }

    /**
     * Trim long addresses to the hover-essential bits — strip trailing postal
     * codes, drop unit/section prefixes when a street follows them, and cap
     * the result at ~60 chars so the tooltip stays one line.
     */
    private function shortAddress(string $raw): string
    {
        $s = trim($raw);
        if ($s === '') return '';

        // Strip trailing 4-digit postal code that appears after a comma.
        $s = preg_replace('/,\s*\d{4}\s*$/u', '', $s) ?? $s;

        // "Unit 36, Topanga, 2587 Colin Road, Uvongo" — if the last two
        // comma segments include a numeric street prefix, prefer those.
        $segments = array_map('trim', explode(',', $s));
        $segments = array_values(array_filter($segments, fn ($p) => $p !== ''));
        if (count($segments) > 3) {
            // Take the last three segments — usually "street, suburb" or
            // "street, suburb, town". Drops unit / scheme noise upfront.
            $segments = array_slice($segments, -3);
        }
        $s = implode(', ', $segments);

        if (mb_strlen($s) > 60) {
            $s = mb_substr($s, 0, 57) . '…';
        }
        return $s;
    }
}

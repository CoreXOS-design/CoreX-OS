<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedPropertyExternalRef;
use App\Support\Property\PortalRef;
use Illuminate\Support\Facades\DB;

/**
 * Fills the derived canonical advert reference on every source table that holds a portal advert number
 * (.ai/specs/property-identity.md §4, §8). Raw columns are never read for writing; only `canonical_ref`
 * (and the two `properties.*_canonical_ref` columns) are set — to the freshly computed value, or left
 * alone when it already equals it. Dry run (apply=false) writes nothing and reports the same numbers.
 *
 * The report answers, per source: rows read, normalised, already canonical, to change, unparseable (with
 * samples), no advert number; and across sources: how many canonical references appear in two or more
 * source tables (the cross-source matches the old spellings hid), the pairwise overlaps, and clashes — the
 * same advert held by two different property files of one agency (the input of the reconcile report).
 */
final class PortalRefBackfill
{
    /** @var array<string, array<string, mixed>> */
    private const SOURCES = [
        'tracked_property_external_refs' => ['table' => 'tracked_property_external_refs', 'targets' => ['canonical_ref'], 'fields' => ['agency_id', 'tracked_property_id', 'source_type', 'source_ref', 'source_payload', 'deleted_at']],
        'prospecting_listings'           => ['table' => 'prospecting_listings',           'targets' => ['canonical_ref'], 'fields' => ['portal_source', 'portal_ref', 'deleted_at']],
        'p24_listings'                   => ['table' => 'p24_listings',                   'targets' => ['canonical_ref'], 'fields' => ['p24_listing_number', 'deleted_at']],
        'portal_listings'                => ['table' => 'portal_listings',                'targets' => ['canonical_ref'], 'fields' => ['source_site', 'portal_listing_id', 'deleted_at']],
        'properties_p24'                 => ['table' => 'properties',                     'targets' => ['p24_canonical_ref'], 'fields' => ['p24_ref', 'p24_listing_number', 'deleted_at']],
        'properties_pp'                  => ['table' => 'properties',                     'targets' => ['pp_canonical_ref'],  'fields' => ['pp_ref', 'deleted_at']],
        'property_external_sources'      => ['table' => 'property_external_sources',      'targets' => ['canonical_ref'], 'fields' => ['portal', 'listing_ref', 'listing_url', 'deleted_at']],
    ];

    private const SAMPLE_LIMIT = 8;

    /** @return list<string> */
    public static function sourceKeys(): array
    {
        return array_keys(self::SOURCES);
    }

    /**
     * @param  list<string>|null  $only  source keys to run (null = all)
     * @return array<string, mixed>
     */
    public function run(bool $apply, ?array $only = null, int $chunk = 1000): array
    {
        $keys = array_values(array_filter(self::sourceKeys(), fn ($k) => $only === null || in_array($k, $only, true)));

        $report = ['mode' => $apply ? 'apply' : 'dry-run', 'sources' => [], 'cross' => []];
        $seen = [];       // canonical => bitmask of source index
        $index = array_flip(self::sourceKeys());
        $clashMap = [];   // "agency|canonical" => first tracked_property_id
        $clashes = [];    // "agency|canonical" => list of tracked_property_ids

        foreach ($keys as $key) {
            $def = self::SOURCES[$key];
            $stats = ['rows' => 0, 'archived' => 0, 'no_advert_number' => 0, 'normalised' => 0, 'unparseable' => 0,
                'already_canonical' => 0, 'to_change' => 0, 'written' => 0, 'distinct_canonical' => 0, 'samples' => [],
                'by_shape' => []];
            $distinct = [];

            DB::table($def['table'])->select(array_merge(['id'], $def['fields']))->orderBy('id')->chunk($chunk, function ($rows) use ($key, $def, $apply, &$stats, &$distinct, &$seen, $index, &$clashMap, &$clashes) {
                $updates = [];
                $storedByTarget = $this->stored($def, $rows->pluck('id')->all());

                foreach ($rows as $row) {
                    $stats['rows']++;
                    if ($row->deleted_at !== null) {
                        $stats['archived']++;
                    }

                    [$raw, $canonical] = $this->compute($key, $row);
                    $target = $def['targets'][0];
                    if ($raw === null || $raw === '') {
                        $stats['no_advert_number']++;
                    } elseif ($canonical === null) {
                        $stats['unparseable']++;
                        if (count($stats['samples']) < self::SAMPLE_LIMIT) {
                            $stats['samples'][] = $raw;
                        }
                    } else {
                        $stats['normalised']++;
                        $shape = $this->shape($raw);
                        $stats['by_shape'][$shape] = ($stats['by_shape'][$shape] ?? 0) + 1;
                        $distinct[$canonical] = true;
                        $seen[$canonical] = ($seen[$canonical] ?? 0) | (1 << $index[$key]);

                        if ($key === 'tracked_property_external_refs' && $row->deleted_at === null) {
                            $ck = $row->agency_id . '|' . $canonical;
                            if (! isset($clashMap[$ck])) {
                                $clashMap[$ck] = (int) $row->tracked_property_id;
                            } elseif ($clashMap[$ck] !== (int) $row->tracked_property_id) {
                                $clashes[$ck] = array_values(array_unique(array_merge($clashes[$ck] ?? [$clashMap[$ck]], [(int) $row->tracked_property_id])));
                            }
                        }
                    }

                    $current = $storedByTarget[$row->id] ?? null;
                    if ($canonical === $current) {
                        if ($canonical !== null) {
                            $stats['already_canonical']++;
                        }
                    } else {
                        $stats['to_change']++;
                        $updates[$row->id] = $canonical;
                    }
                }

                if ($apply && $updates) {
                    DB::transaction(function () use ($def, $updates, &$stats) {
                        foreach ($updates as $id => $canonical) {
                            DB::table($def['table'])->where('id', $id)->update([$def['targets'][0] => $canonical]);
                            $stats['written']++;
                        }
                    });
                }
            });

            $stats['distinct_canonical'] = count($distinct);
            $report['sources'][$key] = $stats;
        }

        $report['cross'] = $this->cross($seen, array_flip($index), $clashes);

        return $report;
    }

    /** @return array{0:?string,1:?string} [raw value shown in the report, canonical] */
    private function compute(string $key, object $row): array
    {
        switch ($key) {
            case 'tracked_property_external_refs':
                if (! in_array($row->source_type, TrackedPropertyExternalRef::ADVERT_SOURCE_TYPES, true)) {
                    return [null, null];
                }
                $payload = is_string($row->source_payload) ? json_decode($row->source_payload, true) : null;

                return [(string) $row->source_ref, TrackedPropertyExternalRef::canonicalFor((string) $row->source_type, (string) $row->source_ref, is_array($payload) ? $payload : null)];
            case 'prospecting_listings':
                return [$row->portal_ref, PortalRef::normalise($row->portal_ref, PortalRef::portal($row->portal_source))];
            case 'p24_listings':
                return [$row->p24_listing_number, PortalRef::normalise($row->p24_listing_number, PortalRef::P24)];
            case 'portal_listings':
                return [$row->portal_listing_id, PortalRef::normalise($row->portal_listing_id, PortalRef::portal($row->source_site))];
            case 'properties_p24':
                $raw = $row->p24_ref !== null && $row->p24_ref !== '' ? $row->p24_ref : $row->p24_listing_number;

                return [$raw, PortalRef::normalise($row->p24_ref, PortalRef::P24) ?? PortalRef::normalise($row->p24_listing_number, PortalRef::P24)];
            case 'properties_pp':
                return [$row->pp_ref, PortalRef::normalise($row->pp_ref, PortalRef::PP)];
            case 'property_external_sources':
                $hint = PortalRef::portal($row->portal);
                $raw = $row->listing_ref !== null && $row->listing_ref !== '' ? $row->listing_ref : $row->listing_url;

                return [$raw, PortalRef::normalise($row->listing_ref, $hint) ?? PortalRef::normalise($row->listing_url, $hint)];
        }

        return [null, null];
    }

    /** @return array<int, ?string> id => stored canonical */
    private function stored(array $def, array $ids): array
    {
        return DB::table($def['table'])->whereIn('id', $ids)->pluck($def['targets'][0], 'id')->all();
    }

    /** What the old spelling looked like — for the "formats seen" line of the report. */
    private function shape(string $raw): string
    {
        $s = trim($raw);
        if (preg_match('~^https?://~i', $s)) {
            return 'url';
        }

        return preg_replace(['/\d+/', '/[A-Za-z]+/'], ['9', 'A'], $s) ?? $s;
    }

    /**
     * @param  array<string,int>  $seen  canonical => bitmask
     * @param  array<int,string>  $names index => source key
     * @param  array<string, list<int>>  $clashes
     * @return array<string, mixed>
     */
    private function cross(array $seen, array $names, array $clashes): array
    {
        $inTwo = 0;
        $inThree = 0;
        $pairs = [];
        foreach ($seen as $mask) {
            $bits = [];
            foreach ($names as $i => $_) {
                if ($mask & (1 << $i)) {
                    $bits[] = $i;
                }
            }
            if (count($bits) >= 2) {
                $inTwo++;
            }
            if (count($bits) >= 3) {
                $inThree++;
            }
            for ($a = 0; $a < count($bits); $a++) {
                for ($b = $a + 1; $b < count($bits); $b++) {
                    $label = $names[$bits[$a]] . ' + ' . $names[$bits[$b]];
                    $pairs[$label] = ($pairs[$label] ?? 0) + 1;
                }
            }
        }
        arsort($pairs);

        $clashSamples = [];
        foreach (array_slice($clashes, 0, self::SAMPLE_LIMIT, true) as $k => $tps) {
            $clashSamples[] = $k . ' -> property files ' . implode(', ', $tps);
        }

        return [
            'distinct_canonical_refs' => count($seen),
            'in_two_or_more_sources'  => $inTwo,
            'in_three_or_more'        => $inThree,
            'pair_overlaps'           => $pairs,
            'clashes'                 => count($clashes),
            'clash_samples'           => $clashSamples,
        ];
    }
}

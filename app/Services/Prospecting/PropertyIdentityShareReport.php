<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\DB;

/**
 * Property identity A5 (.ai/specs/property-identity.md §14): the dry-run report for sharing the property / advert row
 * across agencies. READ-ONLY — nothing is written, and there is no apply in this build.
 *
 * What it answers, for the whole platform (the pool is shared, so the answer is not per agency):
 *   1. Adverts: how many distinct adverts would be in the shared pool, how many are already there, how many are seen by
 *      more than one agency, and how many internal contribution records that means.
 *   2. Shared properties: the adverts and property files are grouped (an advert held by two files joins them; an advert
 *      held by two agencies joins their files). How many shared properties that makes, how many span agencies, and how
 *      many cannot be created yet because they depend on a merge or split decision from the A4 report.
 *   3. What would be written to the shared side: the columns, and how complete the public facts are.
 *   4. A privacy self-check: no private column is on the shared side.
 */
final class PropertyIdentityShareReport
{
    /** columns that belong to an agency's own layer (spec §3) and must never be on the shared side */
    private const PRIVATE_COLUMNS = [
        'agency_id', 'tracked_property_id', 'owner_contact_id', 'erf_number', 'erf_portion', 'township', 'lpi_code', 'title_deed_number',
        'deeds_office', 'deeds_registered_date', 'bond_holder', 'bond_amount', 'sale_type', 'scheme_name', 'scheme_number', 'section_number',
        'cadastral_extent', 'section_extent_m2', 'municipal_valuation', 'municipal_valuation_year', 'last_known_sold_price', 'last_known_sold_date',
        'source_chain', 'status_prospecting', 'prospecting_status', 'claimed_by', 'comment',
    ];

    public function __construct(private readonly PropertyIdentityReconciler $reconciler)
    {
    }

    /** @return array<string, mixed> */
    public function run(int $samples = 15): array
    {
        $agencies = $this->reconciler->agencies();
        $overMerged = [];
        foreach ($agencies as $agencyId) {
            foreach ($this->reconciler->overMergedFileIds($agencyId) as $id) {
                $overMerged[$agencyId . ':' . $id] = true;
            }
        }

        // ---- union-find over (agency, property file) nodes joined by a shared canonical advert -------------------------
        $parent = [];
        $find = function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $union = function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };

        $advertAgencies = [];   // canonical => [agency => true]
        $advertNodes = [];      // canonical => first node
        $pairs = [];            // "canonical|agency" => true
        DB::table('tracked_property_external_refs')->whereNull('deleted_at')->whereNotNull('canonical_ref')
            ->orderBy('id')->select('id', 'agency_id', 'tracked_property_id', 'canonical_ref')
            ->chunk(5000, function ($rows) use (&$parent, &$advertAgencies, &$advertNodes, &$pairs, $union) {
                foreach ($rows as $r) {
                    $node = $r->agency_id . ':' . $r->tracked_property_id;
                    $parent[$node] ??= $node;
                    $advertAgencies[$r->canonical_ref][$r->agency_id] = true;
                    $pairs[$r->canonical_ref . '|' . $r->agency_id] = true;
                    if (isset($advertNodes[$r->canonical_ref])) {
                        $union($advertNodes[$r->canonical_ref], $node);
                    } else {
                        $advertNodes[$r->canonical_ref] = $node;
                    }
                }
            });

        $components = [];
        foreach (array_keys($parent) as $node) {
            $components[$find($node)][] = $node;
        }

        $inPool = DB::table('portal_advert_facts')->pluck('canonical_ref')->flip()->all();
        $adverts = count($advertAgencies);
        $alreadyInPool = count(array_intersect_key($advertAgencies, $inPool));
        $multiAgency = count(array_filter($advertAgencies, fn ($a) => count($a) > 1));

        $props = ['total' => 0, 'single_agency_single_file' => 0, 'single_agency_several_files' => 0, 'span_agencies' => 0, 'blocked_by_over_merge' => 0,
            'agency_files_that_would_link' => 0, 'samples' => ['span_agencies' => [], 'several_files' => []]];
        foreach ($components as $nodes) {
            $props['total']++;
            $ag = [];
            $blocked = false;
            foreach ($nodes as $n) {
                [$a] = explode(':', $n);
                $ag[$a] = true;
                if (isset($overMerged[$n])) {
                    $blocked = true;
                }
            }
            $props['agency_files_that_would_link'] += $blocked ? 0 : count($nodes);
            if ($blocked) {
                $props['blocked_by_over_merge']++;
                continue;
            }
            if (count($ag) > 1) {
                $props['span_agencies']++;
                if (count($props['samples']['span_agencies']) < $samples) {
                    $props['samples']['span_agencies'][] = ['agencies' => array_map('intval', array_keys($ag)), 'files' => $nodes];
                }
            } elseif (count($nodes) > 1) {
                $props['single_agency_several_files']++;
                if (count($props['samples']['several_files']) < $samples) {
                    $props['samples']['several_files'][] = ['agency' => (int) array_key_first($ag), 'files' => $nodes];
                }
            } else {
                $props['single_agency_single_file']++;
            }
        }

        // ---- cross-agency disagreement on the public facts of one advert ------------------------------------------------
        $conflicts = ['adverts_seen_by_several_agencies' => $multiAgency, 'suburb_differs' => 0, 'price_differs' => 0];
        if ($multiAgency > 0) {
            $multi = array_keys(array_filter($advertAgencies, fn ($a) => count($a) > 1));
            foreach (array_chunk($multi, 500) as $chunk) {
                $rows = DB::table('prospecting_listings')->whereNull('deleted_at')->whereIn('canonical_ref', $chunk)->get(['canonical_ref', 'agency_id', 'suburb', 'price'])->groupBy('canonical_ref');
                foreach ($rows as $list) {
                    if ($list->pluck('suburb')->map(fn ($s) => mb_strtolower(trim((string) $s)))->filter()->unique()->count() > 1) {
                        $conflicts['suburb_differs']++;
                    }
                    if ($list->pluck('price')->filter()->unique()->count() > 1) {
                        $conflicts['price_differs']++;
                    }
                }
            }
        }

        // ---- what public facts the adverts actually carry ----------------------------------------------------------------
        $mic = DB::table('prospecting_listings')->whereNull('deleted_at')->whereNotNull('canonical_ref')
            ->selectRaw("COUNT(*) n, SUM(address IS NOT NULL AND address<>'' AND address<>'Address not available') addr, SUM(suburb<>'') sub, SUM(price IS NOT NULL AND price>0) price,
                SUM(bedrooms IS NOT NULL) beds, SUM(bathrooms IS NOT NULL) baths, SUM(garages IS NOT NULL) garages, SUM(property_size_m2 IS NOT NULL) floor,
                SUM(erf_size_m2 IS NOT NULL) erf, SUM(property_type IS NOT NULL AND property_type<>'') ptype, SUM(latitude IS NOT NULL) gps,
                SUM(agent_name IS NOT NULL AND agent_name<>'') agent, SUM(agency_name IS NOT NULL AND agency_name<>'') agency, SUM(portal_status IS NOT NULL AND portal_status<>'') status")
            ->first();

        $columns = SharedPortalPool::writableColumns();

        return [
            'agencies'           => $agencies,
            'adverts'            => ['distinct' => $adverts, 'already_in_pool' => $alreadyInPool, 'would_be_created' => $adverts - $alreadyInPool,
                'seen_by_several_agencies' => $multiAgency, 'contribution_records' => count($pairs)],
            'shared_properties'  => $props,
            'conflicts'          => $conflicts,
            'public_fact_coverage' => $mic ? [
                'adverts_with_a_row' => (int) $mic->n, 'address' => (int) $mic->addr, 'suburb' => (int) $mic->sub, 'price' => (int) $mic->price, 'bedrooms' => (int) $mic->beds,
                'bathrooms' => (int) $mic->baths, 'garages' => (int) $mic->garages, 'floor_size' => (int) $mic->floor, 'erf_size' => (int) $mic->erf, 'property_type' => (int) $mic->ptype,
                'gps' => (int) $mic->gps, 'listing_agent_name' => (int) $mic->agent, 'listing_agency_name' => (int) $mic->agency, 'status' => (int) $mic->status,
            ] : [],
            'privacy'            => ['columns_that_would_be_written' => $columns, 'private_columns_among_them' => array_values(array_intersect($columns, self::PRIVATE_COLUMNS)),
                'tables' => ['portal_advert_facts', 'shared_properties'], 'internal_only' => ['portal_advert_contributions'],
                'shared_tables_have_agency_column' => $this->hasAgencyColumn(['portal_advert_facts', 'shared_properties'])],
            'pool_today'         => ['adverts' => count($inPool), 'shared_properties' => (int) DB::table('shared_properties')->count(), 'contribution_records' => (int) DB::table('portal_advert_contributions')->count()],
        ];
    }

    /** @param list<string> $tables */
    private function hasAgencyColumn(array $tables): bool
    {
        foreach ($tables as $t) {
            if (\Illuminate\Support\Facades\Schema::hasColumn($t, 'agency_id')) {
                return true;
            }
        }

        return false;
    }
}

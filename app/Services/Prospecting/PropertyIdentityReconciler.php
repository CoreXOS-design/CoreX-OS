<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\AddressMatchSetting;
use App\Models\Property;
use App\Models\Prospecting\TrackedProperty;
use App\Services\Address\AddressFacts;
use App\Services\Address\AddressMatchScorer;
use App\Services\Address\AddressParser;
use Illuminate\Support\Facades\DB;

/**
 * Property identity A4 (.ai/specs/property-identity.md §8): the dry-run merge report for the data that already exists.
 * READ-ONLY — it never writes a row. Three questions per agency:
 *
 *   1. Own stock -> property file: which of our properties would join which existing property file, with what
 *      confidence, and which would need a new file.
 *   2. Duplicates by advert number: the same Property24 / Private Property advert held twice — on two property files
 *      of one agency, or on several own-stock rows.
 *   3. Over-merged property files: one file holding adverts for several different units / addresses, with the split
 *      the addresses suggest.
 *
 * Nothing here decides anything; Johan reviews the report and a later, separate, step applies what he approves.
 */
final class PropertyIdentityReconciler
{
    public function __construct(
        private readonly PropertyIdentityResolver $door,
        private readonly AddressMatchScorer $scorer,
        private readonly AddressParser $parser,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $agencyId, int $samples = 25): array
    {
        return [
            'agency_id'       => $agencyId,
            'own_stock'       => $this->ownStock($agencyId, $samples),
            'ref_clashes'     => $this->refClashes($agencyId, $samples),
            'own_duplicates'  => $this->ownDuplicates($agencyId, $samples),
            'over_merged'     => $this->overMerged($agencyId, $samples),
        ];
    }

    /** @return list<int> agencies that hold any property or property-file data */
    public function agencies(): array
    {
        return DB::table('tracked_properties')->whereNull('deleted_at')->distinct()->pluck('agency_id')
            ->merge(DB::table('properties')->whereNull('deleted_at')->distinct()->pluck('agency_id'))
            ->unique()->sort()->values()->map(fn ($i) => (int) $i)->all();
    }

    /** @return list<int> every property file of the agency that holds several different units / houses (the A4 test) */
    public function overMergedFileIds(int $agencyId): array
    {
        return $this->overMerged($agencyId, 0)['file_ids'];
    }

    // ---- 1. own stock -> property file ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function ownStock(int $agencyId, int $samples): array
    {
        $out = ['properties' => 0, 'already_on_spine' => 0, 'would_join' => [], 'would_join_total' => 0, 'possible' => 0, 'would_need_new_file' => 0,
            'no_usable_address' => 0, 'samples' => ['join' => [], 'possible' => [], 'new' => []]];

        DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->select('id')->chunkById(500, function ($ids) use (&$out, $agencyId, $samples) {
            $promoted = DB::table('tracked_properties')->whereIn('promoted_to_property_id', $ids->pluck('id'))->pluck('id', 'promoted_to_property_id');
            foreach (Property::withoutGlobalScopes()->whereIn('id', $ids->pluck('id'))->get() as $p) {
                $out['properties']++;
                if ($p->tracked_property_id || isset($promoted[$p->id])) {
                    $out['already_on_spine']++;
                    continue;
                }
                $facts = $this->door->factsFromProperty($p);
                if (! isset($facts['street_name']) && ! isset($facts['erf_number']) && ! isset($facts['portal_refs']) && ! isset($facts['lpi_code'])) {
                    $out['no_usable_address']++;
                    continue;
                }
                $r = $this->door->find($agencyId, $facts, ['type' => 'agency_stock', 'ref' => 'property:' . $p->id]);
                $label = $this->propertyLabel($p);

                if ($r->trackedProperty !== null) {
                    $key = $r->confidence . ' / ' . $r->method;
                    $out['would_join'][$key] = ($out['would_join'][$key] ?? 0) + 1;
                    $out['would_join_total']++;
                    if (count($out['samples']['join']) < $samples) {
                        $out['samples']['join'][] = ['property' => $p->id, 'address' => $label, 'file' => $r->trackedProperty->id, 'file_address' => $r->trackedProperty->displayAddress(), 'confidence' => $r->confidence, 'method' => $r->method];
                    }
                } elseif ($r->possible) {
                    $out['possible']++;
                    if (count($out['samples']['possible']) < $samples) {
                        $out['samples']['possible'][] = ['property' => $p->id, 'address' => $label, 'candidates' => array_map(fn ($c) => ['file' => $c['id'], 'confidence' => $c['confidence'], 'method' => $c['method']], $r->possible)];
                    }
                } else {
                    $out['would_need_new_file']++;
                    if (count($out['samples']['new']) < $samples) {
                        $out['samples']['new'][] = ['property' => $p->id, 'address' => $label];
                    }
                }
            }
        });
        ksort($out['would_join']);

        return $out;
    }

    // ---- 2a. the same advert on two property files of one agency ---------------------------------------------------

    /** @return array<string, mixed> */
    private function refClashes(int $agencyId, int $samples): array
    {
        $groups = DB::table('tracked_property_external_refs as r')
            ->where('r.agency_id', $agencyId)->whereNull('r.deleted_at')->whereNotNull('r.canonical_ref')
            ->select('r.canonical_ref', DB::raw('COUNT(DISTINCT r.tracked_property_id) as files'))
            ->groupBy('r.canonical_ref')->havingRaw('COUNT(DISTINCT r.tracked_property_id) > 1')->get();

        $out = ['groups' => $groups->count(), 'same_address' => 0, 'possible' => 0, 'different_address' => 0, 'cannot_compare' => 0, 'unreadable' => 0, 'files_involved' => 0, 'samples' => ['different_address' => [], 'same_address' => []]];
        $settings = AddressMatchSetting::forAgency($agencyId);
        $involved = [];

        foreach ($groups->pluck('canonical_ref')->chunk(300) as $chunk) {
            $rows = DB::table('tracked_property_external_refs')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->whereIn('canonical_ref', $chunk)->get(['canonical_ref', 'tracked_property_id'])->groupBy('canonical_ref');
            $tps = TrackedProperty::queryWithoutAgencyScope()->whereIn('id', $rows->flatten(1)->pluck('tracked_property_id')->unique())->get()->keyBy('id');

            foreach ($rows as $canonical => $refs) {
                $ids = $refs->pluck('tracked_property_id')->unique()->values();
                $base = $tps[$ids[0]] ?? null;
                $worst = 'same_address';
                foreach ($ids->slice(1) as $other) {
                    $b = $tps[$other] ?? null;
                    if ($base === null || $b === null) {
                        $worst = 'unreadable';
                        continue;
                    }
                    $res = $this->scorer->score(AddressFacts::fromModel($base), AddressFacts::fromModel($b), $settings);
                    $tier = $res['tier'];
                    if ($tier === AddressMatchScorer::TIER_EXACT) {
                        continue;
                    }
                    $this_ = match (true) {
                        $res['veto'] !== []                                                                                 => 'different_address',
                        $tier === AddressMatchScorer::TIER_POSSIBLE || $tier === AddressMatchScorer::TIER_STREET_ONLY       => 'possible',
                        default                                                                                             => 'cannot_compare', // one or both files hold no address to compare
                    };
                    $rank = ['same_address' => 0, 'cannot_compare' => 1, 'possible' => 2, 'different_address' => 3, 'unreadable' => 4];
                    if ($rank[$this_] > $rank[$worst]) {
                        $worst = $this_;
                    }
                }
                $out[$worst]++;
                foreach ($ids as $i) {
                    $involved[$i] = true;
                }
                if (isset($out['samples'][$worst]) && count($out['samples'][$worst]) < $samples) {
                    $out['samples'][$worst][] = ['advert' => $canonical, 'files' => $ids->map(fn ($i) => ['file' => $i, 'address' => isset($tps[$i]) ? $tps[$i]->displayAddress() : '?'])->all()];
                }
            }
        }
        $out['files_involved'] = count($involved);

        return $out;
    }

    // ---- 2b. duplicate own-stock rows -------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function ownDuplicates(int $agencyId, int $samples): array
    {
        $byRef = [];
        foreach (['p24_canonical_ref', 'pp_canonical_ref'] as $col) {
            $g = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull($col)
                ->select($col . ' as ref', DB::raw('COUNT(*) as n'))->groupBy($col)->havingRaw('COUNT(*) > 1')->get();
            $byRef[$col] = ['groups' => $g->count(), 'rows' => (int) $g->sum('n'), 'sample' => $g->sortByDesc('n')->take($samples)->map(fn ($r) => ['advert' => $r->ref, 'rows' => (int) $r->n])->values()->all()];
        }

        // same address: number + street + suburb + unit, on rows with all the parts
        $addr = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('street_core')->where('street_core', '<>', '')->whereNotNull('street_number')->where('street_number', '<>', '')
            ->select('street_number', 'street_core', DB::raw('LOWER(TRIM(suburb)) as sub'), DB::raw("COALESCE(unit_number,'') as unit"), DB::raw('COUNT(*) as n'), DB::raw('GROUP_CONCAT(id ORDER BY id) as ids'))
            ->groupBy('street_number', 'street_core', 'sub', 'unit')->havingRaw('COUNT(*) > 1')->get();

        return [
            'same_advert'  => $byRef,
            'same_address' => ['groups' => $addr->count(), 'rows' => (int) $addr->sum('n'),
                'sample' => $addr->sortByDesc('n')->take($samples)->map(fn ($r) => ['address' => trim($r->street_number . ' ' . $r->street_core . ($r->unit !== '' ? ' unit ' . $r->unit : '') . ', ' . $r->sub), 'rows' => (int) $r->n, 'property_ids' => $r->ids])->values()->all()],
        ];
    }

    // ---- 3. over-merged property files ---------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function overMerged(int $agencyId, int $samples, int $minAdverts = 6): array
    {
        $candidates = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('tracked_property_id')
            ->select('tracked_property_id', DB::raw('COUNT(*) as n'))->groupBy('tracked_property_id')->havingRaw('COUNT(*) >= ?', [$minAdverts])->get();

        $out = ['files_with_many_adverts' => $candidates->count(), 'looks_like_relist_history' => 0, 'over_merged' => 0, 'adverts_on_over_merged' => 0, 'proposed_new_files' => 0, 'file_ids' => [], 'samples' => []];

        foreach ($candidates as $c) {
            $rows = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('tracked_property_id', $c->tracked_property_id)
                ->get(['id', 'portal_source', 'portal_ref', 'canonical_ref', 'address', 'suburb']);

            $groups = [];
            $units = [];
            $numbers = [];
            foreach ($rows as $r) {
                $addr = trim((string) $r->address);
                if ($addr === '' || strcasecmp($addr, 'Address not available') === 0) {
                    $groups['(no address)'][] = $r;
                    continue;
                }
                $p = $this->parser->parse(['address' => $addr, 'suburb' => $r->suburb]);
                $key = trim(($p['street_number'] ?? '') . '|' . ($p['street_core'] ?? '') . '|' . ($p['unit_number'] ?? ''), '|');
                $groups[$key !== '' ? $key : mb_strtolower($addr)][] = $r;
                if (! empty($p['unit_number'])) {
                    $units[$p['unit_number']] = true;
                }
                if (! empty($p['street_number'])) {
                    $numbers[$p['street_number']] = true;
                }
            }
            $real = array_filter($groups, fn ($k) => $k !== '(no address)', ARRAY_FILTER_USE_KEY);
            $overMerged = count($units) >= 2 || count($numbers) >= 2 || (count($real) >= 2 && $c->n >= 10);

            if (! $overMerged) {
                $out['looks_like_relist_history']++;
                continue;
            }
            $out['over_merged']++;
            $out['file_ids'][] = (int) $c->tracked_property_id;
            $out['adverts_on_over_merged'] += $c->n;
            $out['proposed_new_files'] += max(0, count($real) - 1);
            if (count($out['samples']) < $samples) {
                $tp = TrackedProperty::queryWithoutAgencyScope()->find($c->tracked_property_id);
                $out['samples'][] = [
                    'file' => (int) $c->tracked_property_id, 'file_address' => $tp?->displayAddress(), 'adverts' => (int) $c->n,
                    'distinct_units' => count($units), 'distinct_street_numbers' => count($numbers),
                    'proposed_split' => collect($groups)->map(fn ($rs, $k) => ['group' => $k, 'adverts' => count($rs), 'example' => $rs[0]->canonical_ref ?? $rs[0]->portal_ref])->values()->take(12)->all(),
                ];
            }
        }
        usort($out['samples'], fn ($a, $b) => $b['adverts'] <=> $a['adverts']);

        return $out;
    }

    private function propertyLabel(Property $p): string
    {
        return trim(implode(', ', array_filter([trim((string) ($p->address ?: ($p->street_number . ' ' . $p->street_name))), $p->suburb])));
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyAddress;
use App\Models\Property;
use App\Services\Address\AddressLineFacts;
use Illuminate\Support\Facades\DB;

/**
 * Property identity apply (.ai/specs/property-identity.md §16): the four approved apply steps that follow the A4 / A5
 * dry-run reports. Johan's rulings (30 Oct 2026):
 *
 *   own-stock-joins   the high-confidence joins of A4 §1 are applied; "possible" ones are NOT merged — they become pending
 *                     rows on the possible-matches review list.
 *   advert-clashes    the same advert on two files of one agency (A4 §2a): files whose addresses agree are merged; an
 *                     advert on a file with no address moves to the file that has one; files that only possibly agree,
 *                     or show different houses, stay separate and are flagged for review. Two files are never merged on
 *                     the advert number alone when their units differ.
 *   split-over-merged a file that holds several units / houses (A4 §3) is split by unit: one new file per address group.
 *   share             A5: the public advert facts go into the shared pool, each agency's own row points at the shared
 *                     row, and the contribution record keeps who contributed. Test agencies are excluded.
 *
 * Every step plans first (dry run: same code, nothing written) and applies only what the plan says. Every row moved is
 * journalled (table, id, column, old, new) so the step can be undone from the journal as well as from the DB dump.
 */
final class PropertyIdentityApplier
{
    public const STEPS = ['own-stock-joins', 'advert-clashes', 'split-over-merged', 'share'];

    /** Pending review rows: how many candidates one own property gets, and which confidences count. */
    private const MAX_CANDIDATES = 3;

    public function __construct(
        private readonly PropertyIdentityReconciler $reconciler,
        private readonly PropertyIdentityResolver $door,
        private readonly PropertyMatchDecisionService $decisions,
        private readonly TrackedPropertyMerger $merger,
        private readonly SharedPortalPool $pool,
    ) {
    }

    public function journalTo(?string $path): void
    {
        $this->merger->journalTo($path);
    }

    // ================================================================================================================
    // 1. own stock -> property file
    // ================================================================================================================

    /** @return array<string,mixed> */
    public function ownStockJoins(int $agencyId, bool $apply): array
    {
        $out = ['agency_id' => $agencyId, 'properties_read' => 0, 'already_on_spine' => 0, 'not_usable' => 0, 'joined' => 0, 'by_method' => [],
            'possible_properties' => 0, 'possible_pairs_recorded' => 0, 'no_match' => 0, 'samples' => ['join' => [], 'possible' => []]];

        DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->orderBy('id')->select('id')->chunkById(500, function ($ids) use (&$out, $agencyId, $apply) {
            $promoted = DB::table('tracked_properties')->whereIn('promoted_to_property_id', $ids->pluck('id'))->pluck('id', 'promoted_to_property_id');
            foreach (Property::withoutGlobalScopes()->whereIn('id', $ids->pluck('id'))->get() as $p) {
                $out['properties_read']++;
                if ($p->tracked_property_id || isset($promoted[$p->id])) {
                    $out['already_on_spine']++;
                    continue;
                }
                $facts = $this->door->factsFromProperty($p);
                if (! isset($facts['street_name']) && ! isset($facts['erf_number']) && ! isset($facts['portal_refs']) && ! isset($facts['lpi_code'])) {
                    $out['not_usable']++;
                    continue;
                }
                $r = $this->door->find($agencyId, $facts, ['type' => 'agency_stock', 'ref' => 'property:' . $p->id]);

                if ($r->trackedProperty !== null && in_array($r->confidence, ['exact', 'high'], true)) {
                    $key = $r->confidence . ' / ' . $r->method;
                    $out['by_method'][$key] = ($out['by_method'][$key] ?? 0) + 1;
                    $out['joined']++;
                    if (count($out['samples']['join']) < 15) {
                        $out['samples']['join'][] = ['property' => $p->id, 'file' => $r->trackedProperty->id, 'confidence' => $r->confidence, 'method' => $r->method];
                    }
                    if ($apply) {
                        // the link only — promoted_to_property_id is a claim, a separate act (spec §5.3)
                        Property::withoutEvents(function () use ($p, $r) {
                            DB::table('properties')->where('id', $p->id)->update([
                                'tracked_property_id' => $r->trackedProperty->id, 'identity_confidence' => $r->confidence, 'identity_method' => $r->method,
                            ]);
                        });
                        $this->merger->log('own-stock-joins', 'properties', (int) $p->id, 'tracked_property_id', null, (int) $r->trackedProperty->id);
                    }
                } elseif ($r->possible || $r->trackedProperty !== null) {
                    $cands = $r->possible;
                    if ($r->trackedProperty !== null && ! $cands) {
                        $cands = [['id' => $r->trackedProperty->id, 'confidence' => (string) $r->confidence, 'method' => (string) $r->method, 'reason' => 'below the auto-join line', 'score' => null]];
                    }
                    $out['possible_properties']++;
                    $recorded = $this->recordOwnStockPossibles($agencyId, $p, $cands, $apply);
                    $out['possible_pairs_recorded'] += $recorded;
                    if (count($out['samples']['possible']) < 15) {
                        $out['samples']['possible'][] = ['property' => $p->id, 'candidates' => array_map(fn ($c) => ['file' => $c['id'], 'confidence' => $c['confidence'], 'method' => $c['method']], $cands)];
                    }
                } else {
                    $out['no_match']++;
                }
            }
        });
        ksort($out['by_method']);

        return $out;
    }

    /**
     * Pending review rows for an own property: the probable candidates (a weak, GPS-only candidate only when it is the
     * single one), at most MAX_CANDIDATES, never one an agent already said "not the same" to.
     */
    private function recordOwnStockPossibles(int $agencyId, Property $p, array $cands, bool $apply): int
    {
        $probable = array_values(array_filter($cands, fn ($c) => $c['confidence'] !== 'weak'));
        $use = $probable ?: (count($cands) === 1 ? $cands : []);
        usort($use, fn ($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $use = array_slice($use, 0, self::MAX_CANDIDATES);
        $n = 0;
        foreach ($use as $c) {
            $n += $this->recordPending($agencyId, 'property:' . $p->id, (int) $c['id'], (string) $c['method'],
                ucfirst((string) $c['confidence']) . ' match: ' . $c['reason'], $c['score'] ?? null,
                ['kind' => 'own_stock', 'property_id' => (int) $p->id], $apply) ? 1 : 0;
        }

        return $n;
    }

    // ================================================================================================================
    // 2. the same advert on two files of one agency
    // ================================================================================================================

    /** @return array<string,mixed> */
    public function advertClashes(int $agencyId, bool $apply): array
    {
        $out = ['agency_id' => $agencyId, 'groups' => 0, 'by_verdict' => [], 'merged_files' => 0, 'merge_refused' => 0, 'adverts_attached' => 0, 'rows_moved' => [],
            'left_no_address_anywhere' => 0, 'flagged_pairs' => 0, 'shells_flagged_duplicate' => 0, 'samples' => ['attach' => [], 'flagged' => [], 'refused' => []]];
        $touched = [];

        foreach ($this->reconciler->clashGroups($agencyId) as $g) {
            $out['groups']++;
            $out['by_verdict'][$g['verdict']] = ($out['by_verdict'][$g['verdict']] ?? 0) + 1;
            $ids = $g['file_ids'];
            $tps = $g['tps'];

            if ($g['verdict'] === 'same_address') {
                [$win, $lose] = $this->merger->chooseWinner($tps[$ids[0]], $tps[$ids[1]]);
                foreach (array_slice($ids, 1) as $other) {
                    [$win, $lose] = $this->merger->chooseWinner($win, $tps[$other]);
                    $res = $this->merger->merge($win, $lose, $apply, 'advert-clashes');
                    if ($res['merged']) {
                        $out['merged_files']++;
                    } else {
                        $out['merge_refused']++;
                        if (count($out['samples']['refused']) < 15) {
                            $out['samples']['refused'][] = ['advert' => $g['canonical'], 'files' => [$win->id, $lose->id], 'reason' => $res['reason']];
                        }
                    }
                }
            } elseif (in_array($g['verdict'], ['possible', 'different_address'], true)) {
                $first = (int) $ids[0];
                foreach (array_slice($ids, 1) as $other) {
                    $label = $g['verdict'] === 'different_address' ? 'The same advert points at two different addresses' : 'The same advert on two files whose addresses only possibly agree';
                    if ($this->recordPending($agencyId, $g['canonical'], (int) $other, 'portal_ref', $label, null,
                        ['kind' => 'advert_clash', 'verdict' => $g['verdict'], 'incoming_tracked_property_id' => $first], $apply)) {
                        $out['flagged_pairs']++;
                        if (count($out['samples']['flagged']) < 15) {
                            $out['samples']['flagged'][] = ['advert' => $g['canonical'], 'files' => [$first, (int) $other], 'verdict' => $g['verdict']];
                        }
                    }
                }
            } elseif ($g['verdict'] === 'cannot_compare') {
                // "has an address": a street outranks a suburb / complex / erf, which outranks an empty capture shell. The advert
                // goes to the one file at the top rank, and only when every other file ranks strictly lower.
                $ranks = [];
                foreach ($ids as $i) {
                    $ranks[$i] = isset($tps[$i]) ? $this->addressRank($tps[$i]) : 0;
                }
                $top = max($ranks);
                $best = array_keys(array_filter($ranks, fn ($r) => $r === $top));
                if ($top < 2 || count($best) !== 1) {
                    $out['left_no_address_anywhere']++;
                    continue;
                }
                $target = (int) $best[0];
                $movedAny = false;
                foreach ($ids as $from) {
                    if ((int) $from === $target) {
                        continue;
                    }
                    $moved = $this->merger->moveAdvert($agencyId, $g['canonical'], (int) $from, $target, $apply, 'advert-clashes');
                    foreach ($moved as $t => $n) {
                        $out['rows_moved'][$t] = ($out['rows_moved'][$t] ?? 0) + $n;
                    }
                    $movedAny = $movedAny || $moved !== [];
                    $touched[(int) $from] = $target;
                }
                if ($movedAny) {
                    $out['adverts_attached']++;
                    if (count($out['samples']['attach']) < 15) {
                        $out['samples']['attach'][] = ['advert' => $g['canonical'], 'to_file' => $target, 'from_files' => array_values(array_diff($ids, [$target]))];
                    }
                }
            }
        }

        // a file emptied by the moves is flagged duplicate of the file that received its adverts
        if ($apply) {
            foreach ($touched as $from => $target) {
                if ($this->merger->isEmptyShell($from)) {
                    $this->merger->flagDuplicate($from, $target, 'advert-clashes');
                    $out['shells_flagged_duplicate']++;
                }
            }
        }

        return $out;
    }

    /** 3 = a street (on the file or on any address row), 2 = a suburb / complex / erf / scheme, 1 = nothing but an advert. */
    private function addressRank(TrackedProperty $tp): int
    {
        if (trim((string) $tp->street_name) !== ''
            || DB::table('tracked_property_addresses')->where('tracked_property_id', $tp->id)->whereNull('deleted_at')->whereRaw("COALESCE(street_name,'') <> ''")->exists()) {
            return 3;
        }
        foreach (['suburb', 'complex_name', 'erf_number', 'scheme_name'] as $col) {
            if (trim((string) $tp->{$col}) !== '') {
                return 2;
            }
        }

        return 1;
    }

    // ================================================================================================================
    // 3. over-merged files
    // ================================================================================================================

    /** @return array<string,mixed> */
    public function splitOverMerged(int $agencyId, bool $apply): array
    {
        $out = ['agency_id' => $agencyId, 'files_split' => 0, 'new_files' => 0, 'adverts_moved' => 0, 'rows_moved' => [], 'files_skipped' => 0, 'samples' => []];

        foreach ($this->reconciler->overMergedFileIds($agencyId) as $fileId) {
            $tp = TrackedProperty::queryWithoutAgencyScope()->find($fileId);
            if ($tp === null) {
                $out['files_skipped']++;
                continue;
            }
            ['groups' => $groups] = $this->reconciler->groupFileAdverts($agencyId, $fileId);
            $real = array_filter($groups, fn ($k) => $k !== '(no address)', ARRAY_FILTER_USE_KEY);
            if (count($real) < 2) {
                $out['files_skipped']++;
                continue;
            }

            // the group that stays: the one at the file's own address, else the biggest
            $fileKey = trim(trim((string) $tp->street_number) . '|' . trim((string) $tp->street_core) . '|' . trim((string) $tp->unit_number), '|');
            $stay = array_key_exists($fileKey, $real) ? $fileKey : (string) array_key_first(collect($real)->sortByDesc(fn ($rs) => count($rs))->all());

            $made = [];
            foreach ($real as $key => $rows) {
                if ((string) $key === $stay) {
                    continue;
                }
                $newId = $this->createSplitFile($tp, $rows, $apply);
                $out['new_files']++;
                $made[] = ['group' => $key, 'adverts' => count($rows), 'new_file' => $newId];
                foreach ($rows as $row) {
                    $canonical = $row->canonical_ref ?: null;
                    if ($canonical === null) {
                        continue;
                    }
                    $moved = $this->merger->moveAdvert($agencyId, $canonical, $fileId, $newId ?? 0, $apply && $newId !== null, 'split-over-merged');
                    $out['adverts_moved']++;
                    foreach ($moved as $t => $n) {
                        $out['rows_moved'][$t] = ($out['rows_moved'][$t] ?? 0) + $n;
                    }
                }
            }
            $out['files_split']++;
            if (count($out['samples']) < 15) {
                $out['samples'][] = ['file' => $fileId, 'stays' => $stay, 'new_files' => $made];
            }
        }

        return $out;
    }

    /** A new property file for one address group of an over-merged file. Public advert facts only — never the old file's deeds / owner. */
    private function createSplitFile(TrackedProperty $orig, array $rows, bool $apply): ?int
    {
        if (! $apply) {
            return null;
        }
        $ids = array_map(fn ($r) => $r->id, $rows);
        $full = DB::table('prospecting_listings')->whereIn('id', $ids)->orderBy('id')->get();
        $first = $full->first();
        $line = AddressLineFacts::split((string) $first->address, (string) $first->suburb) ?? [];
        $sameSuburb = mb_strtolower(trim((string) $first->suburb)) === mb_strtolower(trim((string) $orig->suburb));
        $price = $full->pluck('price')->filter()->last();
        $pick = fn (string $col) => $full->pluck($col)->filter(fn ($v) => $v !== null && $v !== '')->first();

        $tp = new TrackedProperty();
        $tp->forceFill(array_filter([
            'agency_id'              => $orig->agency_id,
            'street_number'          => $line['street_number'] ?? null,
            'street_name'            => $line['street_name'] ?? null,
            'unit_number'            => $line['unit_number'] ?? null,
            'complex_name'           => $line['complex_name'] ?? null,
            'suburb'                 => $first->suburb ?: null,
            'town'                   => $sameSuburb ? $orig->town : null,
            'province'               => $orig->province,
            'p24_suburb_id'          => $sameSuburb ? $orig->p24_suburb_id : null,
            'p24_city_id'            => $sameSuburb ? $orig->p24_city_id : null,
            'property_type'          => $pick('property_type'),
            'bedrooms'               => $pick('bedrooms'),
            'bathrooms'              => $pick('bathrooms'),
            'garages'                => $pick('garages'),
            'erf_size_m2'            => $pick('erf_size_m2'),
            'last_known_asking_price' => $price,
            'status'                 => TrackedProperty::STATUS_ACTIVE,
            'source_chain'           => [['ref' => $first->canonical_ref ?: $first->portal_ref, 'date' => now()->toIso8601String(), 'type' => 'identity_split',
                'split_from_tracked_property_id' => (int) $orig->id, 'fields_contributed' => ['address', 'suburb']]],
            'first_seen_at'          => $full->pluck('first_seen_at')->filter()->min() ?? now(),
            'last_enriched_at'       => now(),
            'last_enrichment_source' => 'identity_split',
        ], fn ($v) => $v !== null && $v !== ''))->save();

        $this->merger->log('split-over-merged', 'tracked_properties', (int) $tp->id, 'created_from', null, (int) $orig->id);

        return (int) $tp->id;
    }

    // ================================================================================================================
    // 4. share (A5)
    // ================================================================================================================

    /**
     * Fill the shared pool from what the agencies already hold, then point each agency row at it.
     *
     * @param  list<int>  $excludeAgencies  test / demo agencies — their data never reaches the pool
     * @return array<string,mixed>
     */
    public function share(array $excludeAgencies, bool $apply): array
    {
        $out = ['excluded_agencies' => $excludeAgencies, 'adverts_in_scope' => 0, 'components' => 0, 'skipped_not_clean' => ['several_files_one_agency' => 0, 'over_merged_file' => 0],
            'pool_adverts_created' => 0, 'pool_adverts_existing' => 0, 'contributions' => 0, 'shared_properties_created' => 0, 'files_pointed' => 0, 'listings_pointed' => 0,
            'adverts_seen_by_several_agencies' => 0, 'privacy' => []];

        $overMerged = [];
        foreach ($this->reconciler->agencies() as $a) {
            if (in_array($a, $excludeAgencies, true)) {
                continue;
            }
            foreach ($this->reconciler->overMergedFileIds($a) as $f) {
                $overMerged[$a . ':' . $f] = true;
            }
        }

        // union-find: (agency:file) nodes joined by a shared canonical advert
        $parent = [];
        $find = function (string $x) use (&$parent): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $firstNode = [];
        $advertFiles = [];   // canonical => [node => true]
        $advertAgencies = [];
        DB::table('tracked_property_external_refs as r')->join('tracked_properties as t', 't.id', '=', 'r.tracked_property_id')
            ->whereNull('r.deleted_at')->whereNull('t.deleted_at')->whereNotNull('r.canonical_ref')
            ->whereNotIn('r.agency_id', $excludeAgencies ?: [0])->where('t.status', '<>', TrackedProperty::STATUS_DUPLICATE)
            ->orderBy('r.id')->select('r.id', 'r.agency_id', 'r.tracked_property_id', 'r.canonical_ref')
            ->chunk(5000, function ($rows) use (&$parent, &$firstNode, &$advertFiles, &$advertAgencies, $find) {
                foreach ($rows as $r) {
                    $node = $r->agency_id . ':' . $r->tracked_property_id;
                    $parent[$node] ??= $node;
                    $advertFiles[$r->canonical_ref][$node] = true;
                    $advertAgencies[$r->canonical_ref][(int) $r->agency_id] = true;
                    if (isset($firstNode[$r->canonical_ref])) {
                        $ra = $find($firstNode[$r->canonical_ref]);
                        $rb = $find($node);
                        if ($ra !== $rb) {
                            $parent[$ra] = $rb;
                        }
                    } else {
                        $firstNode[$r->canonical_ref] = $node;
                    }
                }
            });
        $out['adverts_in_scope'] = count($advertFiles);
        $out['adverts_seen_by_several_agencies'] = count(array_filter($advertAgencies, fn ($a) => count($a) > 1));

        $components = [];
        foreach (array_keys($parent) as $node) {
            $components[$find($node)][] = $node;
        }
        $out['components'] = count($components);

        $adverts = [];   // component root => list<canonical>
        foreach ($advertFiles as $canonical => $nodes) {
            $adverts[$find(array_key_first($nodes))][] = $canonical;
        }

        foreach ($components as $root => $nodes) {
            $agencies = [];
            $blocked = false;
            foreach ($nodes as $n) {
                [$a] = explode(':', $n);
                $agencies[$a] = ($agencies[$a] ?? 0) + 1;
                if (isset($overMerged[$n])) {
                    $blocked = true;
                }
            }
            if ($blocked) {
                $out['skipped_not_clean']['over_merged_file']++;
                continue;
            }
            if (max($agencies) > 1) {
                $out['skipped_not_clean']['several_files_one_agency']++;
                continue;
            }
            if ($apply) {
                $this->shareComponent($nodes, $adverts[$root] ?? [], $out);
            }
        }

        $out['privacy'] = [
            'shared_tables_have_agency_column' => \Illuminate\Support\Facades\Schema::hasColumn('portal_advert_facts', 'agency_id') || \Illuminate\Support\Facades\Schema::hasColumn('shared_properties', 'agency_id'),
            'private_columns_on_shared_side'   => array_values(array_intersect(SharedPortalPool::writableColumns(), ['agency_id', 'tracked_property_id', 'owner_contact_id', 'erf_number', 'erf_portion', 'township', 'lpi_code', 'title_deed_number', 'last_known_sold_price', 'source_chain', 'prospecting_status'])),
        ];

        return $out;
    }

    /** @param list<string> $nodes "agency:file"  @param list<string> $canonicals */
    private function shareComponent(array $nodes, array $canonicals, array &$out): void
    {
        DB::transaction(function () use ($nodes, $canonicals, &$out) {
            $fileIds = array_map(fn ($n) => (int) explode(':', $n)[1], $nodes);
            $agencyOf = [];
            foreach ($nodes as $n) {
                [$a, $f] = explode(':', $n);
                $agencyOf[(int) $f] = (int) $a;
            }

            // 1. every advert of the component is in the pool, with its public facts, and each agency's contribution is recorded
            $factIds = [];
            foreach ($canonicals as $canonical) {
                $existed = DB::table('portal_advert_facts')->where('canonical_ref', $canonical)->exists();
                foreach ($this->agenciesOfAdvert($canonical, $fileIds) as $agencyId) {
                    $this->pool->contribute($canonical, $this->publicFactsOfAdvert($agencyId, $canonical), $agencyId);
                    $out['contributions']++;
                }
                $row = DB::table('portal_advert_facts')->where('canonical_ref', $canonical)->first(['id']);
                if ($row) {
                    $factIds[$canonical] = (int) $row->id;
                    $existed ? $out['pool_adverts_existing']++ : $out['pool_adverts_created']++;
                }
            }

            // 2. one shared property for the component: the public facts of its best advert, else the file's non-deeds address
            $existingShared = DB::table('tracked_properties')->whereIn('id', $fileIds)->whereNotNull('shared_property_id')->value('shared_property_id')
                ?: ($factIds ? DB::table('portal_advert_facts')->whereIn('id', array_values($factIds))->whereNotNull('shared_property_id')->value('shared_property_id') : null);
            if ($existingShared) {
                $sharedId = (int) $existingShared;
            } else {
                $facts = $this->publicFactsOfProperty($fileIds, array_values($factIds));
                $sharedId = (int) DB::table('shared_properties')->insertGetId(array_merge($facts, ['created_at' => now(), 'updated_at' => now()]));
                $out['shared_properties_created']++;
            }

            // 3. the pointers
            if ($factIds) {
                DB::table('portal_advert_facts')->whereIn('id', array_values($factIds))->update(['shared_property_id' => $sharedId]);
            }
            $out['files_pointed'] += DB::table('tracked_properties')->whereIn('id', $fileIds)->whereNull('shared_property_id')->update(['shared_property_id' => $sharedId]);
            foreach ($factIds as $canonical => $factId) {
                $out['listings_pointed'] += DB::table('prospecting_listings')->whereNull('portal_advert_fact_id')->where('canonical_ref', $canonical)
                    ->whereIn('agency_id', array_values(array_unique($agencyOf)))->update(['portal_advert_fact_id' => $factId]);
            }
        });
    }

    /** @return list<int> the agencies (of this component) whose files hold the advert */
    private function agenciesOfAdvert(string $canonical, array $fileIds): array
    {
        return DB::table('tracked_property_external_refs')->whereNull('deleted_at')->where('canonical_ref', $canonical)
            ->whereIn('tracked_property_id', $fileIds)->distinct()->pluck('agency_id')->map(fn ($a) => (int) $a)->all();
    }

    /**
     * The public facts an agency's scrape of this advert carries — from the advert's own row, never from the file (a file
     * may hold deeds-sourced data). Status is mapped onto the pool's four states; a pin is shared only with its precision.
     *
     * @return array<string,mixed>
     */
    private function publicFactsOfAdvert(int $agencyId, string $canonical): array
    {
        $r = DB::table('prospecting_listings')->where('agency_id', $agencyId)->where('canonical_ref', $canonical)->whereNull('deleted_at')->orderByDesc('id')->first();
        if ($r === null) {
            return [];
        }
        $status = match (strtolower((string) $r->portal_status)) {
            'active', 'under_offer' => 'active',
            'sold'                  => 'sold',
            'withdrawn'             => 'withdrawn',
            default                 => ((int) $r->is_active === 1 ? 'active' : 'unknown'),
        };

        return array_filter([
            'address'                 => $r->address && strcasecmp((string) $r->address, 'Address not available') !== 0 ? $r->address : null,
            'suburb'                  => $r->suburb,
            'last_known_asking_price' => $r->price,
            'bedrooms'                => $r->bedrooms,
            'bathrooms'               => $r->bathrooms,
            'garages'                 => $r->garages,
            'floor_size_m2'           => $r->property_size_m2,
            'erf_size_m2'             => $r->erf_size_m2,
            'property_type'           => $r->property_type,
            'listing_agent_name'      => $r->agent_name,
            'listing_agency_name'     => $r->agency_name,
            'portal_status'           => $status,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Public facts of the shared property: the address parts of the component's best advert (the pool row with the most
     * facts), the type and rooms; a pin only from a non-deeds file with a precise pin.
     *
     * @return array<string,mixed>
     */
    private function publicFactsOfProperty(array $fileIds, array $factIds): array
    {
        $fact = $factIds ? DB::table('portal_advert_facts')->whereIn('id', $factIds)->orderByRaw('(address_text IS NOT NULL) DESC, (price IS NOT NULL) DESC, id')->first() : null;
        $file = DB::table('tracked_properties')->whereIn('id', $fileIds)->whereNull('capture_kind')->orderByRaw("(street_name IS NOT NULL AND street_name<>'') DESC, id")->first();

        $line = $fact && $fact->address_text ? (AddressLineFacts::split((string) $fact->address_text, (string) $fact->suburb) ?? []) : [];
        $pin = $file && $file->latitude !== null && in_array($file->geo_confidence, ['exact', 'high', 'street'], true) ? $file : null;
        $suburb = $fact->suburb ?? ($file->suburb ?? null);

        return array_filter([
            'address_text'      => $fact->address_text ?? null,
            'street_number'     => $line['street_number'] ?? ($file->street_number ?? null),
            'street_name'       => $line['street_name'] ?? ($file->street_name ?? null),
            'unit_number'       => $line['unit_number'] ?? ($file->unit_number ?? null),
            'complex_name'      => $line['complex_name'] ?? ($file->complex_name ?? null),
            'suburb'            => $suburb,
            'suburb_normalised' => $suburb ? TrackedPropertyAddress::normaliseSuburb($suburb) : null,
            'town'              => $file->town ?? null,
            'province'          => $file->province ?? null,
            'latitude'          => $pin?->latitude,
            'longitude'         => $pin?->longitude,
            'geo_confidence'    => $pin?->geo_confidence,
            'property_type'     => $fact->property_type ?? ($file->property_type ?? null),
            'bedrooms'          => $fact->bedrooms ?? ($file->bedrooms ?? null),
            'bathrooms'         => $fact->bathrooms ?? ($file->bathrooms ?? null),
            'garages'           => $fact->garages ?? ($file->garages ?? null),
            'floor_size_m2'     => $fact->floor_size_m2 ?? ($file->floor_size_m2 ?? null),
            'erf_size_m2'       => $fact->erf_size_m2 ?? ($file->erf_size_m2 ?? null),
        ], fn ($v) => $v !== null && $v !== '');
    }

    // ================================================================================================================
    // shared helper: a pending row for the possible-matches review list
    // ================================================================================================================

    /** @return bool true when a new pending row was (or would be) written */
    private function recordPending(int $agencyId, string $subjectKey, int $matchedFileId, string $strategy, string $reason, ?int $score, array $facts, bool $apply): bool
    {
        $type = PropertyIdentityResolver::DECISION_SUBJECT;
        if ($this->decisions->isRejected($agencyId, $type, $subjectKey, 'tracked_property', $matchedFileId)) {
            return false;
        }
        $exists = \App\Models\PropertyMatchDecision::queryWithoutAgencyScope()->where('agency_id', $agencyId)->where('subject_type', $type)
            ->where('subject_key', $subjectKey)->where('matched_type', 'tracked_property')->where('matched_id', $matchedFileId)->exists();
        if ($exists) {
            return false;
        }
        if ($apply) {
            $d = $this->decisions->record($agencyId, $type, $subjectKey, 'tracked_property', $matchedFileId, $strategy, mb_substr($reason, 0, 480), null, $facts, $score);
            $this->decisions->recordOutcome($d, 'pending');
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Context;

use App\Models\MicAgencySetting;
use App\Services\MarketIntelligence\Comps\CanonicalComps;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MIC step 5.9 (.ai/specs/mic-work-tab.md 11.1b, AT-519): the market context of every file with a live advert - suburb average sold price and
 * R/m2 for the file's kind of property, the file's own R/m2 against it, up to 3 nearby sales, the last deeds sale, the best matched buyers and
 * our own history on the file. Built once per run (nightly, inside mic:refresh-stats) and stored; the page only reads.
 *
 * The sale pool = cc2's canonical CMA comps (duplicates excluded) + deeds-captured sales + our sold stock, last 24 months, a sale present in two
 * sources counted once. Averages never mix freehold with sectional, and R/m2 uses the erf size for freehold / land and the floor size for
 * sectional. A figure with fewer than `contextMinSales` (3) sales behind it is NULL - never guessed. Ids only; no personal names.
 */
final class FileContextBuilder
{
    private const CHUNK = 1000;
    private const MIN_PRICE = 50_000;
    private const MAX_PRICE = 20_000_000;

    public function __construct(private readonly CanonicalComps $comps, private readonly PropertyTypeResolver $types)
    {
    }

    /** @return int number of context rows written */
    public function refreshAgency(int $agencyId, ?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $started = $now->copy();
        $settings = MicAgencySetting::for($agencyId);
        $minN = $settings->contextMinSales();
        $typeMap = $this->types->map($agencyId);

        $pool = $this->salePool($agencyId, $now, $settings, $typeMap);
        $stats = $this->suburbStats($pool, $now, $minN);

        $ids = DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('has_active_advert', 1)->orderBy('tracked_property_id')->pluck('tracked_property_id')->all();
        $written = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $written += $this->chunk($agencyId, $chunk, $pool, $stats, $minN, $typeMap, $settings, $now);
        }
        DB::table('mic_file_context')->where('agency_id', $agencyId)->where('computed_at', '<', $started)->delete();

        return $written;
    }

    // ── the sale pool ────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string,string>  $typeMap
     * @return array{all: list<array>, byClass: array<string, list<int>>, byStreet: array<string, list<int>>, byComplex: array<string, list<int>>}
     *         sales carry: suburb, class, price, date, size, address, street, complex, tp, source — index lists point into `all` (newest first)
     */
    private function salePool(int $agencyId, Carbon $now, MicAgencySetting $settings, array $typeMap): array
    {
        $since = $now->copy()->subMonths(24)->startOfDay();
        $sales = [];
        $seen = [];
        $add = function (array $s) use (&$sales, &$seen) {
            if ($s['suburb'] === null || $s['price'] < self::MIN_PRICE || $s['price'] > self::MAX_PRICE) {
                return;
            }
            $key = $s['suburb'] . '|' . $s['price'] . '|' . $s['date'];
            if (isset($seen[$key])) {
                return;      // the same sale in two sources counts once (first source wins: ours, deeds, CMA)
            }
            $seen[$key] = true;
            $sales[] = $s;
        };

        // 1. our own sold stock (only sales with a real price and a trustworthy date)
        foreach (DB::table('sold_stock as s')->leftJoin('properties as p', 'p.id', '=', 's.property_id')
            ->where('s.agency_id', $agencyId)->whereNull('s.deleted_at')->where('s.sale_price', '>', 0)->whereNotNull('p.sold_at')   // the sale date is the property's own sold_at (5.4), never sold_stock.sale_date
            ->where('p.sold_at', '>=', $since->toDateString())
            ->get(['s.sale_price', 'p.sold_at as sale_date', 's.size_m2', 's.address', 's.property_type', 'p.p24_suburb_id', 'p.property_type as ptype2']) as $r) {
            $class = $this->classFor($this->types->keyFor($r->property_type ?: $r->ptype2, $agencyId), false);
            $add($this->sale($r->p24_suburb_id, $class, (int) $r->sale_price, (string) $r->sale_date, $r->size_m2, $r->address, null, null, 'our_sold_stock'));
        }

        // 2. deeds-captured sales (a deeds capture is the only source with a real property type)
        foreach (DB::table('tracked_properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('deeds_captured_at')
            ->where('last_known_sold_price', '>', 0)->where('last_known_sold_date', '>=', $since->toDateString())
            ->get(['id', 'p24_suburb_id', 'property_type', 'street_number', 'street_name', 'unit_number', 'complex_name', 'scheme_name', 'erf_size_m2', 'floor_size_m2', 'last_known_sold_price', 'last_known_sold_date']) as $r) {
            $inComplex = $r->complex_name || $r->scheme_name || $r->unit_number;
            $class = $this->classFor($typeMap[PropertyTypeResolver::normalise($r->property_type)] ?? null, (bool) $inComplex);
            $size = $class === 'sectional' ? $r->floor_size_m2 : $r->erf_size_m2;
            $addr = trim(($r->unit_number ? $r->unit_number . ' ' : '') . ($r->street_number ? $r->street_number . ' ' : '') . $r->street_name);
            $add($this->sale($r->p24_suburb_id, $class, (int) $r->last_known_sold_price, (string) $r->last_known_sold_date, $size, $addr ?: null,
                $r->street_name, $r->complex_name ?: $r->scheme_name, 'deeds', (int) $r->id));
        }

        // 3. CMA sales: cc2's canonical scope (duplicates excluded). CMA type is a title label, so the class comes from the sectional-vs-freehold basis.
        foreach ($this->comps->query($agencyId, $since, self::MIN_PRICE, self::MAX_PRICE)->addSelect('address')->whereNotNull('p24_suburb_id')->get() as $r) {
            $isLand = ($typeMap[PropertyTypeResolver::normalise($r->property_type)] ?? null) === 'land';
            $class = $isLand ? 'land' : ($r->basis === CanonicalComps::BASIS_FLOOR ? 'sectional' : 'freehold');
            $add($this->sale($r->p24_suburb_id, $class, (int) $r->sale_price, (string) $r->sale_date, $r->extent_m2, $r->address, null, $r->scheme_name, 'cma'));
        }

        usort($sales, fn ($a, $b) => [$b['date'], $b['price']] <=> [$a['date'], $a['price']]);   // newest first
        $byClass = $byStreet = $byComplex = [];
        foreach ($sales as $i => $s) {
            if ($s['class'] !== null) {
                $byClass[$s['suburb'] . '|' . $s['class']][] = $i;
            }
            if ($s['street'] !== null) {
                $byStreet[$s['suburb'] . '|' . $s['street']][] = $i;
            }
            if ($s['complex'] !== null) {
                $byComplex[$s['suburb'] . '|' . $s['complex']][] = $i;
            }
        }

        return ['all' => $sales, 'byClass' => $byClass, 'byStreet' => $byStreet, 'byComplex' => $byComplex];
    }

    private function sale(mixed $suburb, ?string $class, int $price, string $date, mixed $size, ?string $address, ?string $streetName, ?string $complex, string $source, ?int $tp = null): array
    {
        $size = $size !== null && (float) $size > 0 ? (float) $size : null;

        return [
            'suburb' => $suburb !== null ? (int) $suburb : null, 'class' => $class, 'price' => $price, 'date' => substr($date, 0, 10), 'size' => $size,
            'address' => $address !== null && trim($address) !== '' ? trim($address) : null,
            'street' => self::streetKey($streetName ?? $address), 'complex' => self::complexKey($complex), 'tp' => $tp, 'source' => $source,
            'addr' => self::addressKey($address),
        ];
    }

    /** sectional | freehold | land | null for a canonical type key (townhouse follows whether it sits in a complex). */
    private function classFor(?string $typeKey, bool $inComplex): ?string
    {
        return match ($typeKey) {
            'apartment' => 'sectional', 'house', 'farm' => 'freehold', 'land' => 'land',
            'townhouse' => $inComplex ? 'sectional' : 'freehold',
            default => null,
        };
    }

    /** "13 Newport Road, Port Edward" / "Newport Road" -> "newport road"; null when too short to be a street. */
    public static function streetKey(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = mb_strtolower(trim(explode(',', $text)[0]));
        $t = preg_replace('/^((unit|flat|ss|section|erf)\s*)?[0-9]+[a-z]?(\s*[\/\-]\s*[0-9]+[a-z]?)?\s+/u', '', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}0-9 ]/u', ' ', (string) $t)));

        return mb_strlen($t) >= 4 && preg_match('/[a-z]{3}/u', $t) ? $t : null;
    }

    /** "66 EFFINGHAM PARADE, TRAFALGAR" -> "66 effingham parade" (number + street, suburb dropped); null without a house number. Used to keep a file's own sale out of its nearby list. */
    public static function addressKey(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = mb_strtolower(trim(explode(',', $text)[0]));
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}0-9 ]/u', ' ', $t)));

        return preg_match('/^[0-9]+[a-z]? [a-z]/u', $t) ? $t : null;
    }

    public static function complexKey(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $t = mb_strtolower(trim($text));
        $t = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[^\p{L}0-9 ]/u', ' ', (string) preg_replace('/^ss\s+/u', '', $t))));

        return mb_strlen($t) >= 4 ? $t : null;
    }

    // ── per suburb + class figures ───────────────────────────────────────────────────────────────────────

    /** @return array<string, array{avg: ?int, n: int, perM2: ?int, nM2: int, basis: string}> keyed "suburb|class" */
    private function suburbStats(array $pool, Carbon $now, int $minN): array
    {
        $cut = $now->copy()->subMonths(12)->toDateString();
        $by = [];
        foreach ($pool['all'] as $s) {
            if ($s['class'] === null || $s['date'] < $cut) {
                continue;
            }
            $by[$s['suburb'] . '|' . $s['class']][] = $s;
        }
        $out = [];
        foreach ($by as $key => $list) {
            $n = count($list);
            $m2 = array_values(array_filter(array_map(fn ($s) => $s['size'] ? $s['price'] / $s['size'] : null, $list)));
            $out[$key] = [
                'avg' => $n >= $minN ? (int) round(array_sum(array_column($list, 'price')) / $n) : null,
                'n' => $n,
                'perM2' => count($m2) >= $minN ? (int) round(array_sum($m2) / count($m2)) : null,
                'nM2' => count($m2),
                'basis' => explode('|', $key)[1] === 'sectional' ? 'floor' : 'erf',
            ];
        }

        return $out;
    }

    /**
     * @return array{0: ?float, 1: ?string}  [size in m2, source: deeds | advert | file]
     */
    private function ownSize(?string $basis, object $tp, ?object $lead): array
    {
        if ($basis === null) {
            return [null, null];
        }
        $fields = $basis === 'floor' ? ['section_extent_m2', 'floor_size_m2'] : ['erf_size_m2'];
        $chain = is_string($tp->source_chain) ? (json_decode($tp->source_chain, true) ?: []) : [];
        $registerBacked = $tp->deeds_captured_at !== null;
        foreach ($chain as $entry) {
            if (in_array($entry['type'] ?? '', ['deeds_capture', 'cmainfo'], true) && array_intersect($fields, $entry['fields_contributed'] ?? []) !== []) {
                $registerBacked = true;
            }
        }
        $fileSize = null;
        foreach ($fields as $f) {
            if ((float) ($tp->{$f} ?? 0) > 0) {
                $fileSize = (float) $tp->{$f};
                break;
            }
        }
        if ($registerBacked && $fileSize !== null) {
            return [$fileSize, 'deeds'];
        }
        $adSize = null;
        if ($lead) {
            $adSize = $basis === 'floor'
                ? ((float) $lead->property_size_m2 > 0 ? (float) $lead->property_size_m2 : ((float) $lead->erf_size_m2 > 0 ? (float) $lead->erf_size_m2 : null))   // a flat's single portal size is its floor size
                : ((float) $lead->erf_size_m2 > 0 ? (float) $lead->erf_size_m2 : null);
        }
        if ($adSize !== null) {
            return [$adSize, 'advert'];
        }

        return $fileSize !== null ? [$fileSize, 'file'] : [null, null];
    }

    // ── per file ─────────────────────────────────────────────────────────────────────────────────────────

    /** @param list<int> $ids */
    private function chunk(int $agencyId, array $ids, array $pool, array $stats, int $minN, array $typeMap, MicAgencySetting $settings, Carbon $now): int
    {
        $fs = DB::table('mic_file_stats')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->get()->keyBy('tracked_property_id');
        $tps = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $ids)->get([
            'id', 'p24_suburb_id', 'property_type', 'street_number', 'street_name', 'unit_number', 'complex_name', 'scheme_name', 'erf_size_m2', 'floor_size_m2', 'section_extent_m2', 'source_chain',
            'deeds_captured_at', 'last_known_sold_price', 'last_known_sold_date', 'deeds_registered_date', 'sale_type',
        ])->keyBy('id');
        $leads = DB::table('prospecting_listings')->whereIn('id', $fs->pluck('lead_listing_id')->filter()->all())->get(['id', 'price', 'property_size_m2', 'erf_size_m2'])->keyBy('id');

        $owners = DB::table('tracked_property_owners')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->get(['tracked_property_id', 'id_type', 'role', 'ownership_status'])->groupBy('tracked_property_id');

        $buyerRows = DB::table('prospecting_buyer_matches as m')->join('prospecting_listings as pl', 'pl.id', '=', 'm.prospecting_listing_id')->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->where('m.agency_id', $agencyId)->where('pl.agency_id', $agencyId)->whereIn('pl.tracked_property_id', $ids)->where('pl.is_active', 1)->whereNull('pl.deleted_at')
            ->whereNull('m.dismissed_at')->whereNull('c.deleted_at')
            ->when($settings->buyerStatesExcluded() !== [], fn ($q) => $q->whereRaw("COALESCE(c.buyer_state, '') NOT IN (" . implode(',', array_fill(0, count($settings->buyerStatesExcluded()), '?')) . ')', $settings->buyerStatesExcluded()))
            ->groupBy('pl.tracked_property_id', 'm.contact_id')->selectRaw('pl.tracked_property_id as tp, m.contact_id, MAX(m.score) as s')->get()->groupBy('tp');

        $claims = DB::table('prospecting_claims as c')->join('prospecting_listings as pl', 'pl.id', '=', 'c.prospecting_listing_id')
            ->where('c.agency_id', $agencyId)->whereNull('c.deleted_at')->whereIn('pl.tracked_property_id', $ids)
            ->orderByDesc('c.claimed_at')->orderByDesc('c.id')->get(['pl.tracked_property_id as tp', 'c.user_id', 'c.status', 'c.claimed_at', 'c.release_reason'])->groupBy('tp');
        $work = DB::table('tracked_property_agency_work')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $ids)->get(['tracked_property_id', 'prospecting_status', 'prospecting_reason'])->keyBy('tracked_property_id');
        $mandates = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $ids)
            ->orderByDesc('listed_date')->orderByDesc('id')->get(['id', 'tracked_property_id', 'listed_date', 'expiry_date', 'price', 'mandate_type', 'status'])->groupBy('tracked_property_id');

        $rows = [];
        foreach ($ids as $id) {
            $f = $fs->get($id);
            $tp = $tps->get($id);
            if (! $f || ! $tp) {
                continue;
            }
            $inComplex = $tp->complex_name || $tp->scheme_name || $tp->unit_number;
            $class = $this->classFor($f->type_key, (bool) $inComplex);
            $suburb = $f->p24_suburb_id !== null ? (int) $f->p24_suburb_id : null;
            $st = ($suburb !== null && $class !== null) ? ($stats[$suburb . '|' . $class] ?? null) : null;

            // the file's own size and R/m2 on the same basis (lead advert first)
            $lead = $f->lead_listing_id ? $leads->get($f->lead_listing_id) : null;
            $basis = $class === 'sectional' ? 'floor' : ($class === null ? null : 'erf');
            // Which size R/m2 uses: the REGISTER-backed size of the file when there is one (a deeds capture, or a deeds / CMA entry in the file's source chain that
            // contributed the size), otherwise the lead advert's size, otherwise whatever the file record holds. `own_size_source` says which.
            [$size, $sizeSource] = $this->ownSize($basis, $tp, $lead);
            $price = $f->price !== null ? (int) $f->price : null;
            $ownPerM2 = $price && $size ? (int) round($price / $size) : null;
            $suburbPerM2 = $st['perM2'] ?? null;
            $avg = $st['avg'] ?? null;

            // nearby sales: same street, then same complex, then same suburb + class; newest first within a tier, never the file's own sale
            $picked = [];
            $ownAddr = self::addressKey(trim(($tp->street_number ? $tp->street_number . ' ' : '') . $tp->street_name));
            $take = function (array $indexes, string $match) use (&$picked, $pool, $id, $ownAddr) {
                foreach ($indexes as $i) {
                    if (count($picked) >= 3) {
                        return;
                    }
                    $s = $pool['all'][$i];
                    if (($s['tp'] ?? null) === $id || isset($picked[$i]) || ($ownAddr !== null && $s['addr'] === $ownAddr && $match !== 'complex')) {
                        continue;      // never the file's own sale (by file id, or by its own street address)
                    }
                    $picked[$i] = $s + ['match' => $match];
                }
            };
            $streetKey = self::streetKey($tp->street_name);
            if ($suburb !== null) {
                if ($streetKey !== null) {
                    $take($pool['byStreet'][$suburb . '|' . $streetKey] ?? [], 'street');
                }
                $complexKey = self::complexKey($tp->complex_name ?: $tp->scheme_name);
                if ($complexKey !== null) {
                    $take($pool['byComplex'][$suburb . '|' . $complexKey] ?? [], 'complex');
                }
                if ($class !== null) {
                    $take($pool['byClass'][$suburb . '|' . $class] ?? [], 'suburb');
                }
            }
            $nearby = collect($picked)->sortByDesc(fn ($s) => $s['date'] . sprintf('%012d', $s['price']))->values()->map(fn ($s) => [
                'address' => $s['address'], 'sold_on' => $s['date'], 'price' => $s['price'],
                'r_per_m2' => $s['size'] ? (int) round($s['price'] / $s['size']) : null, 'source' => $s['source'], 'match' => $s['match'],
            ])->all();

            // last deeds sale (no names; buyer type only when the owner record says so)
            $deeds = null;
            if ($tp->deeds_captured_at !== null) {
                $own = $owners->get($id, collect());
                $buyerType = null;
                if ($own->contains('role', 'director')) {
                    $buyerType = 'company';
                } elseif ($own->contains('id_type', 'trust_reg')) {
                    $buyerType = 'trust';
                } elseif ($own->contains('id_type', 'sa_id')) {
                    $buyerType = 'individual';
                }
                $deeds = [
                    'sold_on' => $tp->last_known_sold_date ? substr((string) $tp->last_known_sold_date, 0, 10) : null,
                    'registered_on' => $tp->deeds_registered_date ? substr((string) $tp->deeds_registered_date, 0, 10) : null,
                    'price' => (int) $tp->last_known_sold_price > 0 ? (int) $tp->last_known_sold_price : null,
                    'sale_type' => $tp->sale_type ?: null, 'buyer_type' => $buyerType,
                ];
            }

            $top = $buyerRows->get($id, collect())->sortBy([['s', 'desc'], ['contact_id', 'asc']])->take(3)->map(fn ($b) => ['contact_id' => (int) $b->contact_id, 'score' => (int) $b->s])->values()->all();

            $myClaims = $claims->get($id, collect());
            $last = $myClaims->first();
            $wk = $work->get($id);
            $history = [
                'claims' => $myClaims->count(),
                'last_claim' => $last ? ['agent_id' => (int) $last->user_id, 'claimed_at' => (string) $last->claimed_at, 'status' => (string) $last->status, 'reason' => $last->release_reason ?: null] : null,
                'work_status' => $wk->prospecting_status ?? null, 'work_reason' => $wk->prospecting_reason ?? null,
                'mandates' => $mandates->get($id, collect())->map(fn ($m) => [
                    'property_id' => (int) $m->id, 'listed_on' => $m->listed_date ? substr((string) $m->listed_date, 0, 10) : null,
                    'expires_on' => $m->expiry_date ? substr((string) $m->expiry_date, 0, 10) : null, 'price' => $m->price !== null ? (int) $m->price : null,
                    'mandate_type' => $m->mandate_type ?: null, 'status' => (string) $m->status,
                ])->values()->all(),
            ];

            $rows[] = [
                'agency_id' => $agencyId, 'tracked_property_id' => $id, 'sale_class' => $class, 'suburb_id' => $suburb,
                'suburb_avg_sold_12m' => $avg, 'suburb_avg_sold_n' => $st['n'] ?? 0,
                'suburb_r_per_m2' => $suburbPerM2, 'suburb_r_per_m2_n' => $st['nM2'] ?? 0, 'suburb_r_per_m2_basis' => $st['basis'] ?? $basis,
                'own_size_m2' => $size !== null ? (int) round($size) : null, 'own_size_basis' => $size !== null ? $basis : null, 'own_size_source' => $size !== null ? $sizeSource : null, 'own_r_per_m2' => $ownPerM2,
                'own_vs_suburb_pct' => $ownPerM2 !== null && $suburbPerM2 ? round(($ownPerM2 / $suburbPerM2 - 1) * 100, 1) : null,
                'asking_vs_avg_sold_pct' => $price && $avg ? round(($price / $avg - 1) * 100, 1) : null,
                'nearby_sales' => json_encode($nearby), 'last_deeds_sale' => $deeds ? json_encode($deeds) : null,
                'matched_buyers' => json_encode(['strong' => (int) $f->buyers_strong, 'mid' => (int) $f->buyers_mid, 'top' => $top]),
                'our_history' => json_encode($history), 'computed_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('mic_file_context')->upsert($rows, ['agency_id', 'tracked_property_id'], array_values(array_diff(array_keys($rows[0]), ['agency_id', 'tracked_property_id'])));
        }

        return count($rows);
    }
}

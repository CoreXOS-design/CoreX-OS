<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

use App\Models\MicAgencySetting;
use App\Services\MarketIntelligence\Dom\DomEstimator;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MIC step 5.5 (.ai/specs/mic-work-tab.md 11.1, AT-519): builds the STORED numbers the Work page and the hot-pocket tiles read.
 *
 *  - mic_file_stats  : one row per tracked-property file that has portal adverts: pocket keys, active/own flags, buyer strong/mid counts
 *                      (distinct buyers, cold/lost excluded by setting), portal-lead counts (own stock), price cuts on live adverts,
 *                      re-listed flag, days on market (DomEstimator).
 *  - mic_pocket_stats: per agency x day x (suburb, type, beds, price band): market stock, aged 90/180, cuts, re-listed, own stock/sales,
 *                      trusted days-to-sale, buyers waiting, leads.
 * Every query names the agency. Only caches are written (derived rows are replaced, never business data), so a run is always safe to repeat.
 */
final class MicStatsBuilder
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly DomEstimator $dom,
        private readonly PropertyTypeResolver $types,
        private readonly \App\Services\MarketIntelligence\Score\OpportunityScoreService $score,
        private readonly \App\Services\MarketIntelligence\Context\FileContextBuilder $context,
    ) {
    }

    /** @return array<string, int|float> counters for the report */
    public function refreshAgency(int $agencyId, ?Carbon $now = null): array
    {
        $now ??= Carbon::now();
        $started = $now->copy();
        $ctx = $this->context($agencyId, $now);

        $ids = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('tracked_property_id')->distinct()->orderBy('tracked_property_id')->pluck('tracked_property_id')->all();

        $files = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $files += $this->buildChunk($agencyId, $chunk, $ctx);
        }

        // Files that no longer have an advert: their stored row is a stale cache.
        $removed = DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('computed_at', '<', $started)->delete();

        $pockets = $this->refreshPockets($agencyId, $now, $ctx);
        $scored = $this->score->scoreAgency($agencyId, $now);   // needs today's pocket rows
        $context = $this->context->refreshAgency($agencyId, $now);   // 5.9 market context per file (needs the lead advert + price stored above)

        return ['files' => $files, 'stale_removed' => $removed, 'pockets' => $pockets, 'scored' => $scored, 'context' => $context];
    }

    /** Re-count one file (used on change). */
    public function refreshFile(int $agencyId, int $trackedPropertyId): void
    {
        $this->buildChunk($agencyId, [$trackedPropertyId], $this->context($agencyId, Carbon::now()));
        $this->score->score($agencyId, $trackedPropertyId);
    }

    /** @return array<string, mixed> */
    private function context(int $agencyId, Carbon $now): array
    {
        $settings = MicAgencySetting::for($agencyId);
        $tier = DB::table('buyer_match_tiers')->where('agency_id', $agencyId)->whereNull('deleted_at')->first();

        return [
            'now'      => $now,
            'settings' => $settings,
            'edges'    => $settings->priceBandEdges(),
            'strong'   => (int) ($tier->strong_min_score ?? 80),
            'mid'      => (int) ($tier->mid_min_score ?? 50),
            'excluded' => $settings->buyerStatesExcluded(),
            'typeMap'  => $this->types->map($agencyId),
        ];
    }

    private function typeKey(array $ctx, ?string ...$raws): string
    {
        foreach ($raws as $raw) {
            $k = $ctx['typeMap'][PropertyTypeResolver::normalise($raw)] ?? null;
            if ($k !== null) {
                return $k;
            }
        }

        return 'other';
    }

    /** @param list<int> $ids */
    private function buildChunk(int $agencyId, array $ids, array $ctx): int
    {
        $now = $ctx['now'];
        $adverts = DB::table('prospecting_listings')->where('agency_id', $agencyId)->whereIn('tracked_property_id', $ids)->whereNull('deleted_at')
            ->get(['id', 'tracked_property_id', 'portal_source', 'portal_ref', 'is_active', 'price', 'first_seen_at', 'listed_date', 'p24_suburb_id', 'property_type', 'bedrooms', 'advertiser_type'])
            ->groupBy('tracked_property_id');

        $tps = DB::table('tracked_properties')->where('agency_id', $agencyId)->whereIn('id', $ids)
            ->get(['id', 'p24_suburb_id', 'property_type', 'bedrooms', 'last_known_asking_price'])->keyBy('id');

        $own = DB::table('tracked_property_agency_work')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('tracked_property_id', $ids)
            ->pluck('is_own_stock', 'tracked_property_id');

        // "Some live advert on this file was cut" - valid cuts only (a history row with a NULL / 0 price is a capture glitch, not a cut).
        $cutsAny = DB::table('prospecting_price_history as h')->join('prospecting_listings as pl', 'pl.id', '=', 'h.prospecting_listing_id')
            ->where('pl.agency_id', $agencyId)->whereIn('pl.tracked_property_id', $ids)->where('pl.is_active', 1)->whereNull('pl.deleted_at')
            ->where('h.new_price', '>', 0)->whereColumn('h.new_price', '<', 'h.old_price')
            ->groupBy('pl.tracked_property_id')->selectRaw('pl.tracked_property_id as tp, COUNT(DISTINCT pl.id) as n')->pluck('n', 'tp');

        // The LEAD advert of each file = the live advert with the lowest asking price (ties: seen first, then lowest id). The price move and the
        // cut figures shown for the file come from that ONE advert's own history - never mixed across adverts.
        $lead = [];
        foreach ($adverts as $tpId => $set) {
            $live = $set->where('is_active', 1);
            $lead[$tpId] = $live->sort(function ($x, $y) {
                $key = fn ($a) => [(int) $a->price > 0 ? 0 : 1, (int) $a->price, (string) $a->first_seen_at, (int) $a->id];

                return $key($x) <=> $key($y);
            })->first();
        }
        $leadIds = collect($lead)->filter()->map(fn ($a) => (int) $a->id)->values()->all();
        $history = $leadIds === [] ? collect() : DB::table('prospecting_price_history')->whereIn('prospecting_listing_id', $leadIds)
            ->orderBy('changed_at')->orderBy('id')->get(['prospecting_listing_id', 'old_price', 'new_price', 'changed_at'])->groupBy('prospecting_listing_id');
        $claimed = DB::table('prospecting_claims as c')->join('prospecting_listings as pl', 'pl.id', '=', 'c.prospecting_listing_id')
            ->where('c.agency_id', $agencyId)->where('c.is_active', 1)->whereNull('c.released_at')->whereNull('c.deleted_at')
            ->where('pl.is_active', 1)->whereIn('pl.tracked_property_id', $ids)->distinct()->pluck('pl.tracked_property_id')->flip();

        $buyerRows = DB::table('prospecting_buyer_matches as m')
            ->join('prospecting_listings as pl', 'pl.id', '=', 'm.prospecting_listing_id')
            ->join('contacts as c', 'c.id', '=', 'm.contact_id')
            ->where('m.agency_id', $agencyId)->where('pl.agency_id', $agencyId)->whereIn('pl.tracked_property_id', $ids)
            ->where('pl.is_active', 1)->whereNull('pl.deleted_at')->whereNull('m.dismissed_at')->whereNull('c.deleted_at')
            ->when($ctx['excluded'] !== [], fn ($q) => $q->whereRaw("COALESCE(c.buyer_state, '') NOT IN (" . implode(',', array_fill(0, count($ctx['excluded']), '?')) . ')', $ctx['excluded']))
            ->groupBy('pl.tracked_property_id', 'm.contact_id')
            ->selectRaw('pl.tracked_property_id as tp, m.contact_id, MAX(m.score) as s')->get()->groupBy('tp');

        $leads = DB::table('portal_leads as l')->join('properties as p', 'p.id', '=', 'l.listing_id')
            ->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.lead_type', '<>', 'Test')->whereIn('p.tracked_property_id', $ids)
            ->groupBy('p.tracked_property_id')
            ->selectRaw('p.tracked_property_id as tp, COUNT(*) as n, SUM(l.received_at >= ?) as d30', [$now->copy()->subDays(30)])
            ->get()->keyBy('tp');

        $rows = [];
        foreach ($ids as $id) {
            $ads = $adverts->get($id);
            if (! $ads || $ads->isEmpty()) {
                continue;
            }
            $tp = $tps->get($id);
            $active = $ads->where('is_active', 1);

            $relisted = false;
            foreach ($ads->groupBy('portal_source') as $byPortal) {
                if ($byPortal->pluck('portal_ref')->unique()->count() >= 2 && $byPortal->where('is_active', 1)->isNotEmpty() && $byPortal->where('is_active', 0)->isNotEmpty()) {
                    $relisted = true;
                }
            }

            $price = $active->pluck('price')->filter(fn ($p) => (int) $p > 0)->min();   // = the lead advert's price
            $price = $price !== null ? (int) $price : ($tp && (int) $tp->last_known_asking_price > 0 ? (int) $tp->last_known_asking_price : null);

            $suburb = $tp->p24_suburb_id ?? $ads->pluck('p24_suburb_id')->filter()->first();
            $beds = $tp->bedrooms ?? $ads->pluck('bedrooms')->filter(fn ($b) => $b !== null)->first();

            $strong = $mid = 0;
            foreach ($buyerRows->get($id, collect()) as $b) {
                if ((int) $b->s >= $ctx['strong']) {
                    $strong++;
                } elseif ((int) $b->s >= $ctx['mid']) {
                    $mid++;
                }
            }

            $dom = $active->isNotEmpty()
                ? $this->dom->forAdverts($agencyId, $active, $ctx['settings']->domApproxWindowDays(), $now)
                : \App\Services\MarketIntelligence\Dom\DomResult::unknown();

            $leadAd = $lead[$id] ?? null;
            $cutN = 0; $cutAmt = 0; $c30 = 0; $c90 = 0; $lastCut = null; $priceFirst = null; $priceMove = null;
            if ($leadAd) {
                $leadPrice = (int) $leadAd->price > 0 ? (int) $leadAd->price : null;
                foreach ($history->get($leadAd->id, collect()) as $h) {
                    $o = (int) $h->old_price; $n2 = (int) $h->new_price;
                    if ($priceFirst === null && $o > 0) { $priceFirst = $o; }
                    if ($o > 0 && $n2 > 0 && $n2 < $o) {
                        $cutN++; $cutAmt += $o - $n2; $lastCut = $h->changed_at;
                        if (Carbon::parse($h->changed_at)->gte($now->copy()->subDays(30))) { $c30++; }
                        if (Carbon::parse($h->changed_at)->gte($now->copy()->subDays(90))) { $c90++; }
                    }
                }
                if ($leadPrice !== null && $priceFirst !== null) { $priceMove = $leadPrice - $priceFirst; }
            }
            $lead2 = $leads->get($id);

            $rows[] = [
                'agency_id' => $agencyId, 'tracked_property_id' => $id,
                'p24_suburb_id' => $suburb !== null ? (int) $suburb : null,
                'type_key' => $this->typeKey($ctx, $tp->property_type ?? null, ...$ads->pluck('property_type')->filter()->unique()->values()->all()),
                'beds' => (int) min(5, max(0, (int) $beds)),
                'band' => PriceBand::key($price, $ctx['edges']),
                'price' => $price,
                'has_active_advert' => $active->isNotEmpty() ? 1 : 0,
                'own_stock' => ! empty($own[$id]) ? 1 : 0,
                'adverts_active' => min(65535, $active->count()), 'adverts_total' => min(65535, $ads->count()),
                'buyers_strong' => min(65535, $strong), 'buyers_mid' => min(65535, $mid),
                'leads_total' => min(65535, (int) ($lead2->n ?? 0)), 'leads_30d' => min(65535, (int) ($lead2->d30 ?? 0)),
                'lead_listing_id' => $leadAd ? (int) $leadAd->id : null, 'price_first' => $priceFirst, 'price_move' => $priceMove,
                'cuts_count' => min(65535, $cutN), 'cut_amount' => $cutAmt, 'cuts_30d' => min(65535, $c30), 'cuts_90d' => min(65535, $c90),
                'cuts_any' => min(65535, (int) ($cutsAny[$id] ?? 0)), 'last_cut_at' => $lastCut,
                'owner_advert' => $active->contains(fn ($a) => $a->advertiser_type === 'owner') ? 1 : 0,
                'claimed' => isset($claimed[$id]) ? 1 : 0,
                'relisted' => $relisted ? 1 : 0,
                'dom_days' => $dom->days !== null ? min(65535, $dom->days) : null, 'dom_mode' => $dom->mode,
                'computed_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('mic_file_stats')->upsert($rows, ['agency_id', 'tracked_property_id'], array_values(array_diff(array_keys($rows[0]), ['agency_id', 'tracked_property_id'])));
        }

        return count($rows);
    }

    /** @return int number of pocket rows written for today */
    private function refreshPockets(int $agencyId, Carbon $now, array $ctx): int
    {
        $date = $now->toDateString();
        /** @var MicAgencySetting $settings */
        $settings = $ctx['settings'];
        $edges = $ctx['edges'];
        $pockets = [];
        $key = fn ($s, $t, $b, $band) => $s . '|' . $t . '|' . $b . '|' . $band;
        $blank = fn () => ['market_stock' => 0, 'aged_90' => 0, 'aged_180' => 0, 'cut_30' => 0, 'cut_90' => 0, 'relisted' => 0, 'own_on_market' => 0,
            'own_sold' => 0, 'own_sales_trusted' => 0, 'own_fast_sales' => 0, 'own_days_sum' => 0, 'buyers' => 0, 'leads_30d' => 0];

        // 1. market stock from the stored file rows (not our own stock)
        $market = DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('has_active_advert', 1)->where('own_stock', 0)
            ->whereNotNull('p24_suburb_id')
            ->groupBy('p24_suburb_id', 'type_key', 'beds', 'band')
            ->selectRaw('p24_suburb_id s, type_key t, beds b, band, COUNT(*) n, SUM(dom_days >= 90) a90, SUM(dom_days >= 180) a180, '
                . 'SUM(cuts_30d > 0) c30, SUM(cuts_90d > 0) c90, SUM(relisted) rl')->get();
        foreach ($market as $m) {
            $p = &$pockets[$key($m->s, $m->t, $m->b, $m->band)];
            $p ??= $blank() + ['s' => (int) $m->s, 't' => $m->t, 'b' => (int) $m->b, 'band' => $m->band];
            $p['market_stock'] = (int) $m->n; $p['aged_90'] = (int) $m->a90; $p['aged_180'] = (int) $m->a180;
            $p['cut_30'] = (int) $m->c30; $p['cut_90'] = (int) $m->c90; $p['relisted'] = (int) $m->rl;
            unset($p);
        }

        // 2. our own stock (properties) + trusted days-to-sale (sold_stock with real dates only)
        $props = DB::table('properties')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('listing_type', 'sale')->whereNotNull('p24_suburb_id')
            ->get(['id', 'p24_suburb_id', 'property_type', 'beds', 'price', 'status']);
        $propPocket = [];
        foreach ($props as $pr) {
            $t = $this->typeKey($ctx, $pr->property_type);
            $pk = [(int) $pr->p24_suburb_id, $t, (int) min(5, max(0, (int) $pr->beds)), PriceBand::key($pr->price !== null ? (int) $pr->price : null, $edges)];
            $propPocket[$pr->id] = $pk;
            $st = strtolower(trim((string) $pr->status));
            $onMarket = in_array($st, ['active', 'for_sale', 'under_offer', 'on_show'], true);
            $sold = in_array($st, ['sold', 'transferred'], true);
            if (! $onMarket && ! $sold) {
                continue;
            }
            $p = &$pockets[$key(...$pk)];
            $p ??= $blank() + ['s' => $pk[0], 't' => $pk[1], 'b' => $pk[2], 'band' => $pk[3]];
            $onMarket ? $p['own_on_market']++ : $p['own_sold']++;
            unset($p);
        }
        $sales = DB::table('sold_stock')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereNotNull('listed_date')->whereNotNull('sale_date')
            ->where('sale_date', '>', $settings->trustedSalesAfter())->whereColumn('sale_date', '>=', 'listed_date')
            ->get(['property_id', 'listed_date', 'sale_date']);
        foreach ($sales as $sale) {
            $pk = $propPocket[$sale->property_id] ?? null;
            if ($pk === null) {
                continue;
            }
            $days = (int) Carbon::parse($sale->listed_date)->diffInDays(Carbon::parse($sale->sale_date));
            $p = &$pockets[$key(...$pk)];
            $p ??= $blank() + ['s' => $pk[0], 't' => $pk[1], 'b' => $pk[2], 'band' => $pk[3]];
            $p['own_sales_trusted']++;
            $p['own_days_sum'] += $days;
            if ($days <= $settings->fastSaleDays()) {
                $p['own_fast_sales']++;
            }
            unset($p);
        }

        // 3. portal leads on our stock, last 30 days, by the property's pocket
        $leadRows = DB::table('portal_leads as l')->where('l.agency_id', $agencyId)->whereNull('l.deleted_at')->where('l.lead_type', '<>', 'Test')
            ->where('l.received_at', '>=', $now->copy()->subDays(30))->whereNotNull('l.listing_id')->selectRaw('l.listing_id as pid, COUNT(*) as n')->groupBy('l.listing_id')->get();
        foreach ($leadRows as $lr) {
            $pk = $propPocket[$lr->pid] ?? null;
            if ($pk === null) {
                continue;
            }
            $p = &$pockets[$key(...$pk)];
            $p ??= $blank() + ['s' => $pk[0], 't' => $pk[1], 'b' => $pk[2], 'band' => $pk[3]];
            $p['leads_30d'] += (int) $lr->n;
            unset($p);
        }

        // 4. buyers waiting: active sale wishlists of buyers whose state counts, expanded against every stock pocket
        $wishes = DB::table('contact_matches as cm')->join('contacts as c', 'c.id', '=', 'cm.contact_id')
            ->where('cm.agency_id', $agencyId)->whereNull('cm.deleted_at')->where('cm.status', 'active')->where('cm.listing_type', 'sale')
            ->where('c.is_buyer', 1)->whereNull('c.deleted_at')
            ->when($ctx['excluded'] !== [], fn ($q) => $q->whereRaw("COALESCE(c.buyer_state, '') NOT IN (" . implode(',', array_fill(0, count($ctx['excluded']), '?')) . ')', $ctx['excluded']))
            ->get(['cm.contact_id', 'cm.p24_suburb_ids', 'cm.property_types', 'cm.property_type', 'cm.price_min', 'cm.price_max', 'cm.beds_min'])
            ->map(function ($w) use ($ctx) {
                $ids = json_decode((string) $w->p24_suburb_ids, true);
                $raw = json_decode((string) $w->property_types, true);
                $raw = is_array($raw) && $raw !== [] ? $raw : ($w->property_type ? [$w->property_type] : []);
                $w->suburbs = is_array($ids) ? array_map('intval', $ids) : [];
                $w->types = array_values(array_unique(array_filter(array_map(fn ($r) => $ctx['typeMap'][PropertyTypeResolver::normalise((string) $r)] ?? null, $raw))));

                return $w;
            })->filter(fn ($w) => $w->suburbs !== [])->values();

        foreach ($pockets as $k => &$p) {
            $range = PriceBand::range($p['band'], $edges);
            $buyers = [];
            foreach ($wishes as $w) {
                if (! in_array($p['s'], $w->suburbs, true)) { continue; }
                if ($w->types !== [] && ! in_array($p['t'], $w->types, true)) { continue; }
                if ($w->beds_min !== null && (int) $w->beds_min > $p['b']) { continue; }
                if ($range !== null) {
                    if ($w->price_max !== null && (int) $w->price_max > 0 && (int) $w->price_max < $range[0]) { continue; }
                    if ($w->price_min !== null && $range[1] !== null && (int) $w->price_min >= $range[1]) { continue; }
                }
                $buyers[$w->contact_id] = true;
            }
            $p['buyers'] = count($buyers);
        }
        unset($p);

        // 5. write today's rows (replace), prune old days
        DB::table('mic_pocket_stats')->where('agency_id', $agencyId)->where('stat_date', $date)->delete();
        $out = [];
        foreach ($pockets as $p) {
            $out[] = [
                'agency_id' => $agencyId, 'stat_date' => $date, 'p24_suburb_id' => $p['s'], 'type_key' => $p['t'], 'beds' => $p['b'], 'band' => $p['band'],
                'market_stock' => $p['market_stock'], 'aged_90' => $p['aged_90'], 'aged_180' => $p['aged_180'], 'cut_30' => $p['cut_30'], 'cut_90' => $p['cut_90'],
                'relisted' => $p['relisted'], 'own_on_market' => $p['own_on_market'], 'own_sold' => $p['own_sold'], 'own_sales_trusted' => $p['own_sales_trusted'],
                'own_fast_sales' => $p['own_fast_sales'],
                'own_avg_days_to_sale' => $p['own_sales_trusted'] > 0 ? round($p['own_days_sum'] / $p['own_sales_trusted'], 1) : null,
                'buyers' => $p['buyers'], 'leads_30d' => $p['leads_30d'], 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($out, 500) as $c) {
            DB::table('mic_pocket_stats')->insert($c);
        }
        DB::table('mic_pocket_stats')->where('agency_id', $agencyId)->where('stat_date', '<', $now->copy()->subDays(14)->toDateString())->delete();

        return count($out);
    }
}

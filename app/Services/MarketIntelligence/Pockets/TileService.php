<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

use App\Models\MicAgencySetting;
use App\Models\ProspectingListing;
use App\Models\Property;
use App\Models\User;
use App\Services\MarketIntelligence\PropertyTypes\PropertyTypeResolver;
use App\Services\PermissionService;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The hot-pocket tiles 1, 4, 5, 6, 7, 8 and 9 (.ai/specs/mic-work-tab.md section 1; contract 11.1, consumed by cc6's MicWorkTiles).
 * Every number comes from the STORED tables built nightly (mic_file_stats, mic_pocket_stats) or from a single indexed query on our own
 * records - never a recount of the portal stock on page load. Every query is bound to the viewer's agency; file-level counts and filters
 * also honour the viewer's market_intelligence scope (own / branch / all; 'none' = no tiles). Pocket tiles (5, 7, 9) are agency-level
 * market numbers. A tile with too little data says so instead of showing a number it cannot stand behind.
 */
final class TileService
{
    public const KEYS = [1 => 'stuck', 4 => 'our_stock', 5 => 'fast_sellers', 6 => 'lead_sources', 7 => 'buyers_waiting', 8 => 'p24_alerts', 9 => 'market_prices'];

    private const ON_MARKET = ['active', 'for_sale', 'under_offer', 'on_show'];

    public function __construct(private readonly PropertyTypeResolver $types)
    {
    }

    /** @return array<int, TileResult> keyed by tile number; only the tiles the agency shows */
    public function forViewer(User $viewer): array
    {
        $agencyId = $viewer->effectiveAgencyId();
        if ($agencyId === null || PermissionService::marketIntelligenceScope($viewer) === 'none') {
            return [];
        }
        $shown = MicAgencySetting::for($agencyId)->tilesShown();
        $out = [];
        foreach (self::KEYS as $n => $key) {
            if (in_array($n, $shown, true)) {
                $out[$n] = $this->tile($n, $viewer);
            }
        }

        return $out;
    }

    public function tile(int $n, User $viewer): TileResult
    {
        $agencyId = (int) $viewer->effectiveAgencyId();
        $settings = MicAgencySetting::for($agencyId);

        return match ($n) {
            1 => $this->stuck($agencyId, $viewer, $settings),
            4 => $this->ourStock($agencyId, $viewer),
            5 => $this->fastSellers($agencyId, $settings),
            6 => $this->leadSources($agencyId),
            7 => $this->buyersWaiting($agencyId),
            8 => $this->p24Alerts($agencyId),
            9 => $this->marketPrices($agencyId, $settings),
            default => throw new \InvalidArgumentException("cc5 does not own tile {$n}"),
        };
    }

    /**
     * Files the tile points at, as a subquery of tracked_properties.id (agency- and scope-bound), or null for a strip tile (9).
     *
     * @return Builder|null
     */
    public function fileIds(string $key, User $viewer): ?Builder
    {
        $agencyId = $viewer->effectiveAgencyId();
        $scope = PermissionService::marketIntelligenceScope($viewer);
        if ($agencyId === null || $scope === 'none') {
            return DB::table('mic_file_stats')->whereRaw('1 = 0')->select('tracked_property_id');
        }
        $settings = MicAgencySetting::for((int) $agencyId);

        $q = match ($key) {
            'stuck' => $this->files((int) $agencyId, $viewer, $scope)->where('f.has_active_advert', 1)->where('f.own_stock', 0)
                ->where(fn ($w) => $w->where('f.cuts_any', '>', 0)->orWhere('f.relisted', 1)->orWhere('f.dom_days', '>=', $settings->stuckDomDays())),
            'our_stock' => DB::table('properties as f')->where('f.agency_id', $agencyId)->whereNull('f.deleted_at')->where('f.listing_type', 'sale')
                ->whereIn(DB::raw('LOWER(f.status)'), self::ON_MARKET)->whereNotNull('f.tracked_property_id')
                ->when($scope !== 'all', fn ($w) => $w->whereIn('f.id', Property::query()->visibleTo($viewer)->select('id')->toBase())),
            'fast_sellers' => $this->pocketJoin((int) $viewer->effectiveAgencyId(), $this->files((int) $agencyId, $viewer, $scope), 'p.own_fast_sales > 0'),
            'lead_sources' => $this->files((int) $agencyId, $viewer, $scope)->where('f.leads_30d', '>', 0),
            'buyers_waiting' => $this->pocketJoin((int) $agencyId, $this->files((int) $agencyId, $viewer, $scope), 'p.buyers >= 3'),
            'p24_alerts' => DB::table('p24_listings as f')->where('f.agency_id', $agencyId)->whereNull('f.deleted_at')->whereNotNull('f.tracked_property_id')
                ->where('f.first_seen_date', '>=', Carbon::now()->subDays(7)->toDateString()),
            default => null,
        };

        return $q?->select('f.tracked_property_id');
    }

    /** mic_file_stats (alias f) for the viewer's agency, restricted to the files the viewer may see. */
    public function visibleFiles(User $viewer): Builder
    {
        return $this->files((int) $viewer->effectiveAgencyId(), $viewer);
    }

    /** mic_file_stats (alias f) for the agency, restricted to the files the viewer may see. */
    private function files(int $agencyId, User $viewer, ?string $scope = null): Builder
    {
        $scope ??= PermissionService::marketIntelligenceScope($viewer);
        $q = DB::table('mic_file_stats as f')->where('f.agency_id', $agencyId);
        if ($scope !== 'all') {
            $q->whereIn('f.tracked_property_id', ProspectingListing::query()->visibleTo($viewer, $scope)->whereNotNull('tracked_property_id')->select('tracked_property_id')->toBase());
        }

        return $q;
    }

    /** Join today's pocket row (alias p) to the market files: active, not ours, same suburb + type + beds + band, plus a pocket condition. */
    private function pocketJoin(int $agencyId, Builder $files, string $pocketCondition): Builder
    {
        $date = $this->latestPocketDate($agencyId);

        return $files->where('f.has_active_advert', 1)->where('f.own_stock', 0)
            ->join('mic_pocket_stats as p', function ($j) use ($agencyId, $date) {
                $j->on('p.p24_suburb_id', '=', 'f.p24_suburb_id')->on('p.type_key', '=', 'f.type_key')->on('p.beds', '=', 'f.beds')->on('p.band', '=', 'f.band')
                    ->where('p.agency_id', '=', $agencyId)->where('p.stat_date', '=', $date ?? '1970-01-01');
            })
            ->whereRaw($pocketCondition);
    }

    private function latestPocketDate(int $agencyId): ?string
    {
        $d = DB::table('mic_pocket_stats')->where('agency_id', $agencyId)->max('stat_date');

        return $d !== null ? (string) $d : null;
    }

    private function pockets(int $agencyId): \Illuminate\Support\Collection
    {
        $d = $this->latestPocketDate($agencyId);

        return $d === null ? collect() : DB::table('mic_pocket_stats as p')->join('p24_suburbs as s', 's.id', '=', 'p.p24_suburb_id')
            ->where('p.agency_id', $agencyId)->where('p.stat_date', $d)->get(['p.*', 's.name as suburb_name']);
    }

    private function filter(string $key): array
    {
        return ['tile' => $key];
    }

    private function n(mixed $v): string
    {
        return number_format((float) $v, 0, '.', ',');
    }

    // ── Tile 1 ────────────────────────────────────────────────────────────────────────────────────────────

    private function stuck(int $agencyId, User $viewer, MicAgencySetting $settings): TileResult
    {
        $row = $this->files($agencyId, $viewer)->where('f.has_active_advert', 1)->where('f.own_stock', 0)->selectRaw(
            'COUNT(*) as market, SUM(f.cuts_any > 0) as with_cut, SUM(f.cuts_30d > 0) as cut_30d, SUM(f.cuts_count >= 2) as cut_2plus, '
            . 'SUM(f.relisted) as relisted, SUM(f.dom_days >= ?) as long_dom, '
            . 'SUM(f.cuts_any > 0 OR f.relisted = 1 OR f.dom_days >= ?) as stuck', [$settings->stuckDomDays(), $settings->stuckDomDays()]
        )->first();

        $meta = ['with_cut' => (int) $row->with_cut, 'cut_30d' => (int) $row->cut_30d, 'cut_2plus' => (int) $row->cut_2plus,
            'relisted' => (int) $row->relisted, 'long_dom' => (int) $row->long_dom, 'long_dom_days' => $settings->stuckDomDays(), 'market_files' => (int) $row->market];
        if ((int) $row->market === 0) {
            return new TileResult(1, 'stuck', null, 'No live adverts yet', $this->filter('stuck'), true, 'No portal stock read yet', $meta);
        }

        return new TileResult(1, 'stuck', (int) $row->stuck,
            sprintf('%s price cut%s · %s re-listed · %s on the market %d+ days', $this->n($row->with_cut), (int) $row->with_cut === 1 ? '' : 's', $this->n($row->relisted), $this->n($row->long_dom), $settings->stuckDomDays()),
            $this->filter('stuck'), false, null, $meta);
    }

    // ── Tile 4 ────────────────────────────────────────────────────────────────────────────────────────────

    private function ourStock(int $agencyId, User $viewer): TileResult
    {
        $base = Property::query()->visibleTo($viewer)->where('listing_type', 'sale');
        $st = (clone $base)->selectRaw('LOWER(status) as s, COUNT(*) as n')->groupBy('s')->pluck('n', 's');
        $on = (int) collect(self::ON_MARKET)->sum(fn ($s) => $st[$s] ?? 0);
        $sold = (int) (($st['sold'] ?? 0) + ($st['transferred'] ?? 0));
        $withdrawn = (int) ($st['withdrawn'] ?? 0);
        $expired = (int) ($st['expired'] ?? 0);

        $by = (clone $base)->whereNotNull('p24_suburb_id')->join('p24_suburbs as s', 's.id', '=', 'properties.p24_suburb_id')
            ->selectRaw("s.id as suburb_id, s.name, SUM(LOWER(properties.status) IN ('active','for_sale','under_offer','on_show')) as on_market, SUM(LOWER(properties.status) IN ('sold','transferred')) as sold")
            ->groupBy('s.id', 's.name')->orderByRaw('on_market DESC, sold DESC')->limit(10)->get()
            ->map(fn ($r) => ['suburb_id' => (int) $r->suburb_id, 'name' => $r->name, 'on_market' => (int) $r->on_market, 'sold' => (int) $r->sold])->all();

        return new TileResult(4, 'our_stock', $on,
            sprintf('%s on the market · %s sold · %s withdrawn · %s expired', $this->n($on), $this->n($sold), $this->n($withdrawn), $this->n($expired)),
            $this->filter('our_stock'), false, null,
            ['on_market' => $on, 'sold' => $sold, 'withdrawn' => $withdrawn, 'expired' => $expired, 'by_suburb' => $by]);
    }

    // ── Tile 5 ────────────────────────────────────────────────────────────────────────────────────────────

    private function fastSellers(int $agencyId, MicAgencySetting $settings): TileResult
    {
        $pockets = $this->pockets($agencyId);
        $sales = (int) $pockets->sum('own_sales_trusted');
        $days = (int) $pockets->sum(fn ($p) => (float) $p->own_avg_days_to_sale * (int) $p->own_sales_trusted);
        $avg = $sales > 0 ? round($days / $sales, 1) : null;
        $fast = $pockets->filter(fn ($p) => (int) $p->own_fast_sales > 0)->sortByDesc('own_fast_sales')->values();
        $lookalikes = (int) $fast->sum('market_stock');

        $meta = ['sales' => $sales, 'avg_days' => $avg, 'fast_sale_days' => $settings->fastSaleDays(), 'lookalike_adverts' => $lookalikes,
            'pockets' => $fast->take(10)->map(fn ($p) => ['suburb_id' => (int) $p->p24_suburb_id, 'suburb' => $p->suburb_name, 'type_key' => $p->type_key, 'beds' => (int) $p->beds, 'band' => $p->band,
                'sales' => (int) $p->own_sales_trusted, 'fast_sales' => (int) $p->own_fast_sales, 'avg_days' => $p->own_avg_days_to_sale !== null ? (float) $p->own_avg_days_to_sale : null,
                'lookalikes' => (int) $p->market_stock])->all()];

        if ($sales < $settings->minSalesForPocket()) {
            return new TileResult(5, 'fast_sellers', null, sprintf('Based on %d sale%s', $sales, $sales === 1 ? '' : 's'), $this->filter('fast_sellers'), true,
                sprintf('%d sale%s — too few to rank', $sales, $sales === 1 ? '' : 's'), $meta);
        }

        return new TileResult(5, 'fast_sellers', $lookalikes,
            sprintf('%s lookalike adverts in %d fast-selling pocket%s · based on %d sales, avg %s days to sell', $this->n($lookalikes), $fast->count(), $fast->count() === 1 ? '' : 's', $sales, $avg === null ? '–' : (string) round($avg)),
            $this->filter('fast_sellers'), false, null, $meta);
    }

    // ── Tile 6 ────────────────────────────────────────────────────────────────────────────────────────────

    private function leadSources(int $agencyId): TileResult
    {
        $from = Carbon::now()->subDays(30);
        $r = DB::table('portal_leads')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('lead_type', '<>', 'Test')->whereNotNull('listing_id')
            ->where('received_at', '>=', $from)->selectRaw('COUNT(*) as leads, COUNT(DISTINCT listing_id) as listings')->first();
        $all = (int) DB::table('portal_leads')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('lead_type', '<>', 'Test')->count();

        $asOf = DB::table('property_portal_metrics')->where('agency_id', $agencyId)->whereNull('deleted_at')->max('metric_date');
        $perWeek = null;
        if ($asOf !== null) {
            $m = DB::table('property_portal_metrics')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->where('metric_date', '>', Carbon::parse($asOf)->subDays(28)->toDateString())->where('metric_date', '<=', $asOf)
                ->selectRaw('COALESCE(SUM(total_leads),0) as leads, COUNT(DISTINCT property_id) as props')->first();
            $perWeek = (int) $m->props > 0 ? round(((int) $m->leads / 4) / (int) $m->props, 2) : null;
        }

        $meta = ['leads' => (int) $r->leads, 'listings' => (int) $r->listings, 'per_listing' => (int) $r->listings > 0 ? round((int) $r->leads / (int) $r->listings, 1) : null,
            'per_listing_week' => $perWeek, 'metrics_as_of' => $asOf, 'leads_all_time' => $all];
        if ($all < 10) {
            return new TileResult(6, 'lead_sources', null, 'Portal leads on our stock', $this->filter('lead_sources'), true, sprintf('%d portal leads so far — too few to rank', $all), $meta);
        }

        return new TileResult(6, 'lead_sources', (int) $r->leads,
            sprintf('%s portal lead%s in 30 days on %s listing%s%s', $this->n($r->leads), (int) $r->leads === 1 ? '' : 's', $this->n($r->listings), (int) $r->listings === 1 ? '' : 's',
                $perWeek !== null ? sprintf(' · %s per listing per week (to %s)', $perWeek, Carbon::parse($asOf)->format('j M')) : ''),
            $this->filter('lead_sources'), false, null, $meta);
    }

    // ── Tile 7 ────────────────────────────────────────────────────────────────────────────────────────────

    private function buyersWaiting(int $agencyId): TileResult
    {
        $pockets = $this->pockets($agencyId)->filter(fn ($p) => (int) $p->buyers >= 3)
            ->map(function ($p) {
                $p->supply = (int) $p->own_on_market + (int) $p->market_stock;
                $p->gap = (int) $p->buyers - $p->supply;

                return $p;
            })->sortByDesc(fn ($p) => [$p->gap, (int) $p->buyers])->values();
        $short = $pockets->filter(fn ($p) => $p->gap > 0)->count();

        $meta = ['pockets_3plus' => $pockets->count(), 'stock_short' => $short,
            'pockets' => $pockets->take(15)->map(fn ($p) => ['suburb_id' => (int) $p->p24_suburb_id, 'suburb' => $p->suburb_name, 'type_key' => $p->type_key, 'beds' => (int) $p->beds, 'band' => $p->band,
                'buyers' => (int) $p->buyers, 'own_stock' => (int) $p->own_on_market, 'market_stock' => (int) $p->market_stock])->all()];
        if ($this->latestPocketDate($agencyId) === null) {
            return new TileResult(7, 'buyers_waiting', null, 'Buyers waiting', $this->filter('buyers_waiting'), true, 'Pocket stats not built yet', $meta);
        }

        return new TileResult(7, 'buyers_waiting', $pockets->count(),
            sprintf('%d pocket%s with 3+ buyers waiting · %d with fewer adverts than buyers', $pockets->count(), $pockets->count() === 1 ? '' : 's', $short),
            $this->filter('buyers_waiting'), false, null, $meta);
    }

    // ── Tile 8 ────────────────────────────────────────────────────────────────────────────────────────────

    private function p24Alerts(int $agencyId): TileResult
    {
        $from = Carbon::now()->subDays(7)->toDateString();
        $base = DB::table('p24_listings')->where('agency_id', $agencyId)->whereNull('deleted_at');
        $week = (int) (clone $base)->where('first_seen_date', '>=', $from)->count();
        $total = (int) (clone $base)->count();
        $linked = (int) (clone $base)->whereNotNull('tracked_property_id')->count();
        $last = (clone $base)->max('last_seen_date');
        $drops = (int) DB::table('p24_price_changes as c')->join('p24_listings as l', 'l.id', '=', 'c.listing_id')
            ->where('l.agency_id', $agencyId)->whereNull('c.deleted_at')->where('c.change_date', '>=', $from)->whereColumn('c.new_price', '<', 'c.old_price')->count();

        $meta = ['alerts_week' => $week, 'price_drops_week' => $drops, 'matched_pct' => $total > 0 ? round(100 * $linked / $total, 1) : 0.0, 'last_alert_seen' => $last, 'alert_rows' => $total];
        if ($total === 0) {
            return new TileResult(8, 'p24_alerts', null, 'New from P24 alerts', $this->filter('p24_alerts'), true, 'No P24 alert emails read yet', $meta);
        }

        return new TileResult(8, 'p24_alerts', $week,
            $week === 0 && $drops === 0
                ? sprintf('No new alerts in 7 days · last alert %s · %s%% of alert adverts matched', $last ? Carbon::parse($last)->format('j M') : 'never', $meta['matched_pct'])
                : sprintf('%d new alert advert%s this week · %d price drop%s · %s%% matched to a property', $week, $week === 1 ? '' : 's', $drops, $drops === 1 ? '' : 's', $meta['matched_pct']),
            $this->filter('p24_alerts'), false, null, $meta);
    }

    // ── Tile 9 ────────────────────────────────────────────────────────────────────────────────────────────

    private function marketPrices(int $agencyId, MicAgencySetting $settings): TileResult
    {
        $from = Carbon::now()->subMonths(12)->toDateString();
        // CMA comps, one row per sale: the same sale appears in overlapping reports, so identical (suburb, address, date, price, size) count once.
        // (When cc2's duplicate flag lands this switches to the canonical row.)
        $sales = DB::table('market_report_comp_rows')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('row_type', 'comp')
            ->whereNotNull('p24_suburb_id')->where('sale_date', '>=', $from)->whereBetween('sale_price', [50_000, 20_000_000])
            ->selectRaw("p24_suburb_id, COALESCE(address, '') as addr, sale_date, sale_price, MAX(extent_m2) as extent, MAX(property_type) as ptype")
            ->groupBy('p24_suburb_id', 'addr', 'sale_date', 'sale_price')->get();

        $asking = DB::table('mic_file_stats')->where('agency_id', $agencyId)->where('has_active_advert', 1)->where('own_stock', 0)->where('price', '>', 0)->whereNotNull('p24_suburb_id')
            ->groupBy('p24_suburb_id')->selectRaw('p24_suburb_id, AVG(price) as avg_asking, COUNT(*) as stock')->get()->keyBy('p24_suburb_id');
        $names = DB::table('p24_suburbs')->whereIn('id', $sales->pluck('p24_suburb_id')->unique()->all())->pluck('name', 'id');

        $suburbs = [];
        foreach ($sales->groupBy('p24_suburb_id') as $sid => $rows) {
            if ($rows->count() < $settings->minSalesForPocket()) {
                continue;
            }
            // R/m2 is only meaningful inside one title basis: "Residential" rows carry the ERF size (freehold), "Residence" rows the FLOOR (sectional).
            $perM2 = fn ($set) => ($with = $set->filter(fn ($r) => (float) $r->extent > 0))->count() >= 3 ? round((float) $with->avg(fn ($r) => (int) $r->sale_price / (float) $r->extent)) : null;
            $suburbs[] = [
                'suburb_id' => (int) $sid, 'name' => $names[$sid] ?? '', 'sales_12m' => $rows->count(), 'avg_sold' => (int) round((float) $rows->avg('sale_price')),
                'avg_asking' => isset($asking[$sid]) ? (int) round((float) $asking[$sid]->avg_asking) : null,
                'r_per_m2_erf' => $perM2($rows->filter(fn ($r) => strtolower((string) $r->ptype) === 'residential')),
                'r_per_m2_floor' => $perM2($rows->filter(fn ($r) => strtolower((string) $r->ptype) === 'residence')),
                'stock_trend' => null,   // needs two pocket-stat days; reported honestly instead of invented
                'stock' => isset($asking[$sid]) ? (int) $asking[$sid]->stock : 0,
            ];
        }
        usort($suburbs, fn ($a, $b) => $b['sales_12m'] <=> $a['sales_12m']);

        $meta = ['suburbs' => array_slice($suburbs, 0, 25), 'sales_12m_total' => $sales->count(), 'min_sales' => $settings->minSalesForPocket()];
        if ($suburbs === []) {
            return new TileResult(9, 'market_prices', null, 'Market prices here', ['tile' => 'market_prices'], true, 'No suburb has enough dated CMA sales yet', $meta);
        }
        $top = $suburbs[0];

        return new TileResult(9, 'market_prices', count($suburbs),
            sprintf('%d suburb%s with %d+ sales in 12 months · busiest %s: %d sales, avg R %s', count($suburbs), count($suburbs) === 1 ? '' : 's', $settings->minSalesForPocket(), $top['name'], $top['sales_12m'], number_format($top['avg_sold'], 0, '.', ' ')),
            ['tile' => 'market_prices'], false, null, $meta);
    }
}

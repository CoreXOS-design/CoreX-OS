<?php

declare(strict_types=1);

namespace App\Services\SuburbReports;

use App\Models\Agency;
use App\Models\ContactMatch;
use App\Models\P24Suburb;
use App\Models\SuburbMunicipality;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assembles the three-layer suburb report data set. Johan, 2026-08-24:
 * "if we just bundle the cma information from their suburb report, and
 * include what we as hfc hold you already have a cma report on steriods."
 *
 * Layer A — parsed CMA suburb stats (market_data_points, via the parser
 *   fixed 2026-08-25 — distinct median/average keys).
 * Layer B — CoreX's own stock/market data (properties, p24_price_changes,
 *   property_sold_records).
 * Layer C — live buyer demand (contact_matches / Core Match wishlists) —
 *   the figure nothing else can print.
 *
 * THIS SERVICE DOES NOT FREEZE ANYTHING. It returns the current live
 * figures every time it's called — building the actual persisted,
 * frozen-at-generation report record (rule 1: a seller opening it in six
 * months sees exactly what they were shown) is separate, later work once
 * this data layer is proven. Every array below carries its own
 * `as_at`/`generated_at` so freshness is never averaged across sources
 * (rule 2), and Layer A explicitly flags the current calendar year as
 * partial (rule 3) wherever a data point for it exists.
 *
 * Multi-agency (rule 5): every query is scoped by $agencyId, nothing is
 * hardcoded to HFC (agency 1) — this file was built and proven against
 * agency 1's real data, but takes no shortcut that assumes it.
 */
class SuburbReportDataService
{
    public function build(int $agencyId, int $p24SuburbId): array
    {
        $suburb = P24Suburb::withoutGlobalScopes()->find($p24SuburbId);
        $map    = SuburbMunicipality::where('p24_suburb_id', $p24SuburbId)->first();
        $agency = Agency::withoutGlobalScopes()->find($agencyId);

        $suburbNorm = $suburb ? mb_strtolower(trim($suburb->name)) : null;

        return [
            'agency' => [
                'id'   => $agencyId,
                'name' => $agency?->name,
            ],
            'suburb' => [
                'p24_suburb_id' => $p24SuburbId,
                'name'          => $suburb?->name,
                'municipality'  => $map?->municipality,
                // Never treat 'needs_review' as if it were a confirmed fact —
                // the report must say "municipality not confirmed", not print
                // a guess with a straight face.
                'municipality_confirmed' => $map?->confidence === SuburbMunicipality::CONFIDENCE_CONFIRMED,
            ],
            'layer_a' => $this->layerA($agencyId, $suburbNorm, $map?->municipality),
            'layer_b' => $this->layerB($agencyId, $suburbNorm),
            'layer_c' => $this->layerC($agencyId, $p24SuburbId),
        ];
    }

    /**
     * Parsed CMA suburb stats — market_data_points, for the suburb and (when
     * a municipality is confirmed and a report has captured it) the parent
     * municipality alongside it. Empty layer, not a fabricated one, when no
     * report has been parsed for this suburb yet — this is correct behaviour,
     * not a failure: the report should say "no CMA report on file yet",
     * never print zeros dressed as data.
     */
    /**
     * The exact metric keys CmaInfoMedianSalesAnalysisParser produces — the
     * suburb annual sales-stats series Johan described (year, count,
     * median-or-average, annual change, low/high/max range). market_data_points
     * carries other parsers' metrics too (comparable_sale_price,
     * vicinity_radius_sale_price, municipal_valuation from the vicinity/
     * valuation parsers) — those are individual comp data points, not this
     * suburb-wide annual series, and mixing them in would silently blend two
     * different kinds of figure under one "years" list. Scoped explicitly.
     */
    private const LAYER_A_METRIC_KEYS = [
        'suburb_median_price_year',
        'suburb_average_price_year',
        'suburb_sales_count_year',
        'suburb_annual_change_pct',
        'suburb_low_year',
        'suburb_high_year',
        'suburb_max_year',
    ];

    private function layerA(int $agencyId, ?string $suburbNorm, ?string $municipality): array
    {
        if ($suburbNorm === null) {
            return ['available' => false, 'reason' => 'suburb not resolved', 'years' => [], 'municipality_years' => []];
        }

        $suburbPoints = DB::table('market_data_points')
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->where('suburb_normalised', $suburbNorm)
            ->whereIn('metric_key', self::LAYER_A_METRIC_KEYS)
            ->get();

        $municipalityPoints = collect();
        if ($municipality !== null) {
            $municipalityPoints = DB::table('market_data_points')
                ->whereNull('deleted_at')
                ->where('agency_id', $agencyId)
                ->where('suburb_normalised', mb_strtolower(trim($municipality)))
                ->whereIn('metric_key', self::LAYER_A_METRIC_KEYS)
                ->get();
        }

        $currentYear = (int) now()->format('Y');

        return [
            'available'          => $suburbPoints->isNotEmpty(),
            'reason'             => $suburbPoints->isEmpty() ? 'no parsed CMA suburb report on file for this suburb' : null,
            'source_report_vintage' => $suburbPoints->isNotEmpty()
                ? DB::table('market_reports')->whereIn('id', $suburbPoints->pluck('report_id')->unique())->max('report_date')
                : null,
            'years'              => $this->groupByYear($suburbPoints, $currentYear),
            'municipality_years' => $this->groupByYear($municipalityPoints, $currentYear),
        ];
    }

    private function groupByYear(\Illuminate\Support\Collection $points, int $currentYear): array
    {
        $byYear = [];
        foreach ($points as $p) {
            if ($p->metric_date === null) continue;
            $year = (int) substr($p->metric_date, 0, 4);
            $byYear[$year][$p->metric_key] = $p->metric_value_numeric;
        }
        ksort($byYear);

        $out = [];
        foreach ($byYear as $year => $metrics) {
            $out[] = array_merge(['year' => $year, 'is_partial_year' => $year === $currentYear], $metrics);
        }
        return $out;
    }

    /**
     * Our own stock/market data. Every figure notes its own coverage —
     * this must never silently present a 12.8%-populated column as if it
     * were complete.
     */
    private function layerB(int $agencyId, ?string $suburbNorm): array
    {
        if ($suburbNorm === null) {
            return ['available' => false];
        }

        $stockQuery = DB::table('properties')
            ->whereNull('deleted_at')
            ->where('agency_id', $agencyId)
            ->where('suburb_normalised', $suburbNorm);

        $activeStock = (clone $stockQuery)->where('status', 'active')->get(['id', 'price', 'listed_date']);

        $domToday = now();
        $activeWithDom = $activeStock->filter(fn ($p) => $p->listed_date !== null)->map(fn ($p) => [
            'property_id'    => $p->id,
            'price'          => $p->price,
            'days_on_market' => (int) Carbon::parse($p->listed_date)->diffInDays($domToday),
        ])->values();

        $priceReductions = DB::table('p24_price_changes')
            ->join('p24_listings', 'p24_listings.id', '=', 'p24_price_changes.listing_id')
            ->where('p24_listings.agency_id', $agencyId)
            ->whereRaw('LOWER(p24_listings.suburb) = ?', [$suburbNorm])
            ->orderByDesc('p24_price_changes.change_date')
            ->get(['p24_price_changes.old_price', 'p24_price_changes.new_price', 'p24_price_changes.change_date']);

        $sold = DB::table('property_sold_records')
            ->where('agency_id', $agencyId)
            ->whereRaw('LOWER(suburb) LIKE ?', ['%' . $suburbNorm . '%'])
            ->orderByDesc('sold_date')
            ->get(['sold_price', 'listing_price_at_sale', 'sold_date', 'days_on_market']);

        return [
            'available' => true,
            'as_at'     => now()->toIso8601String(),
            'stock_on_market' => [
                'count'                    => $activeStock->count(),
                'with_days_on_market_known' => $activeWithDom->count(),
                'listings'                 => $activeWithDom->all(),
            ],
            'price_reductions' => [
                'count'   => $priceReductions->count(),
                'changes' => $priceReductions->map(fn ($r) => [
                    'old_price'   => (int) $r->old_price,
                    'new_price'   => (int) $r->new_price,
                    'change_date' => $r->change_date,
                ])->all(),
            ],
            'sold' => [
                'count' => $sold->count(),
                'records' => $sold->map(fn ($r) => [
                    'sold_price'             => (int) $r->sold_price,
                    'listing_price_at_sale'  => $r->listing_price_at_sale !== null ? (int) $r->listing_price_at_sale : null,
                    'sold_date'              => $r->sold_date,
                    'days_on_market'         => $r->days_on_market,
                ])->all(),
            ],
        ];
    }

    /**
     * Live buyer demand — Core Match wishlists. The layer nobody else can
     * print. Real-time by construction (no snapshot table involved) —
     * as_at is simply "now", not a stale ingestion date.
     */
    private function layerC(int $agencyId, int $p24SuburbId): array
    {
        $active = ContactMatch::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('status', 'active')
            ->whereNotNull('p24_suburb_ids')
            ->get(['id', 'p24_suburb_ids', 'price_min', 'price_max']);

        $inSuburb = $active->filter(function ($m) use ($p24SuburbId) {
            return in_array($p24SuburbId, (array) $m->p24_suburb_ids, true)
                || in_array((string) $p24SuburbId, (array) $m->p24_suburb_ids, true);
        })->values();

        $bands = [];
        foreach ($inSuburb as $m) {
            $p = $m->price_max ?? $m->price_min;
            $band = match (true) {
                $p === null      => 'unset',
                $p < 1_000_000    => '<R1m',
                $p < 1_500_000    => 'R1-1.5m',
                $p < 2_000_000    => 'R1.5-2m',
                $p < 3_000_000    => 'R2-3m',
                default           => 'R3m+',
            };
            $bands[$band] = ($bands[$band] ?? 0) + 1;
        }

        return [
            'available'          => true,
            'as_at'              => now()->toIso8601String(),
            'active_buyer_count' => $inSuburb->count(),
            'price_bands'        => $bands,
        ];
    }
}

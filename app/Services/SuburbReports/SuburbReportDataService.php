<?php

declare(strict_types=1);

namespace App\Services\SuburbReports;

use App\Models\Agency;
use App\Models\ContactMatch;
use App\Models\P24Suburb;
use App\Models\SuburbMunicipality;
use App\Models\SuburbReport;
use App\Support\Sales\SaleStageLabel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Assembles, and — via generate() — freezes, the three-layer suburb report.
 * Johan, 2026-08-24: "if we just bundle the cma information from their
 * suburb report, and include what we as hfc hold you already have a cma
 * report on steriods."
 *
 * Layer A — parsed CMA suburb stats (market_data_points, scoped to the
 *   exact metric keys CmaInfoMedianSalesAnalysisParser produces).
 * Layer B — CoreX's own stock/market data — stock on market, price
 *   reductions, and sale activity sourced from Dr1/Dr2 (deals.registration_date
 *   / deals_v2.actual_registration / deals_v2.offer_date — NOT
 *   property_sold_records, which is the property's last ADVERTISED price,
 *   not an achieved sale price; confirmed wrong 2026-08-24 when every row
 *   showed sold_price === listing_price_at_sale, i.e. a value mirroring
 *   itself, not two independent figures). Sale activity is split sold vs
 *   under offer, never blended — see salesActivityForSuburb().
 * Layer C — live buyer demand (Core Match wishlists) — the figure nothing
 *   else can print. Reports BOTH legitimate definitions side by side,
 *   exactly labelled (Johan, 2026-08-24: "a number a seller can challenge
 *   and win is worse than no number") — never a single unqualified count.
 *
 * build() returns CURRENT LIVE figures — read-only, nothing persisted.
 * generate() calls build() and freezes the result into an immutable
 * SuburbReport row (rule 1 — same discipline as the e-sign burn: a seller
 * opening it in six months sees exactly what they were shown, never a
 * live-recomputed number). Regenerating creates a NEW row, never updates
 * an existing one.
 *
 * Multi-agency (rule 5): every query is scoped by $agencyId, nothing
 * hardcoded to HFC (agency 1) — built and proven against agency 1's real
 * data, but takes no shortcut that assumes it.
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
     * Freeze the current live figures into an immutable SuburbReport row.
     * Never call build() again for an existing row — a "refresh" is a new
     * generate() call, producing a new row, exactly like a new presentation
     * version.
     */
    public function generate(int $agencyId, int $p24SuburbId, ?int $generatedByUserId = null): SuburbReport
    {
        $data = $this->build($agencyId, $p24SuburbId);
        $now  = now();

        return SuburbReport::create([
            'agency_id'                  => $agencyId,
            'p24_suburb_id'              => $p24SuburbId,
            'suburb_name'                => $data['suburb']['name'] ?? ('#' . $p24SuburbId),
            'municipality'               => $data['suburb']['municipality'],
            'municipality_confirmed'     => $data['suburb']['municipality_confirmed'],
            'agency_name'                => $data['agency']['name'] ?? '',
            'generated_by_user_id'       => $generatedByUserId,
            'generated_at'               => $now,
            'current_year_at_generation' => (int) $now->format('Y'),
            'layer_a_json'               => $data['layer_a'],
            'layer_a_source_vintage'     => $data['layer_a']['source_report_vintage'] ?? null,
            'layer_b_json'               => $data['layer_b'],
            'layer_b_as_at'              => $data['layer_b']['as_at'] ?? $now,
            'layer_c_json'               => $data['layer_c'],
            'layer_c_as_at'              => $data['layer_c']['as_at'] ?? $now,
        ]);
    }

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

    /**
     * Parsed CMA suburb stats. Empty layer, not a fabricated one, when no
     * report has been parsed for this suburb yet — the report should say
     * "no CMA report on file yet", never print zeros dressed as data.
     */
    private function layerA(int $agencyId, ?string $suburbNorm, ?string $municipality): array
    {
        if ($suburbNorm === null) {
            return ['available' => false, 'reason' => 'suburb not resolved', 'years' => [], 'municipality_years' => [], 'source_report_vintage' => null];
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
     * Our own stock/market data. Every figure notes its own coverage — this
     * must never silently present a thin-coverage column as if complete.
     *
     * sold-vs-asking (2026-08-24 correction): the achieved price comes from
     * Dr2 (deals_v2.purchase_price via deals_v2.legacy_deal_id ->
     * deals.property_id), never property_sold_records — that table's
     * sold_price mirrors the property's own last advertised price, not an
     * independently captured sale figure (confirmed: every row had
     * sold_price === listing_price_at_sale). deals_v2.property_id itself is
     * unpopulated on every row — the join has to go through the legacy
     * deals table, which also carries the address text for the ~90% of
     * rows a direct property_id link never resolved. Where the FK doesn't
     * resolve, this falls back to an address-text match against
     * properties.address so a real Dr2 sale isn't dropped just because the
     * link was never made — flagged per-record via 'property_id_matched'
     * so the report can show its match method honestly, never presenting
     * an address-matched pair with the same confidence as an FK-matched one.
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

        // DISTINCT on the exact tuple the report publishes — re-syncing the
        // same portal event more than once (p24_price_changes has no unique
        // constraint) was inflating the count with exact-duplicate rows.
        // 2026-08-25, Shelly Beach: 128 raw rows collapsed to 91 distinct.
        $priceReductions = DB::table('p24_price_changes')
            ->join('p24_listings', 'p24_listings.id', '=', 'p24_price_changes.listing_id')
            ->where('p24_listings.agency_id', $agencyId)
            ->whereRaw('LOWER(p24_listings.suburb) = ?', [$suburbNorm])
            ->distinct()
            ->orderByDesc('p24_price_changes.change_date')
            ->get(['p24_price_changes.old_price', 'p24_price_changes.new_price', 'p24_price_changes.change_date']);

        $salesActivity = $this->salesActivityForSuburb($agencyId, $suburbNorm);

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
            // 2026-08-25 fix — was 'achieved_sales' with a single blended
            // 'count' that counted every deal regardless of stage. Split
            // per Johan: "3 sold" and "7 under offer" as separate numbers,
            // never collapsed into one. Wording lives in SaleStageLabel.
            'sales_activity' => [
                'sold_count'        => count($salesActivity['sold']),
                'sold'              => $salesActivity['sold'],
                'under_offer_count' => count($salesActivity['under_offer']),
                'under_offer'       => $salesActivity['under_offer'],
                'source'            => "status='granted' OR registered (DR1 deals.registration_date / DR2 deals_v2.actual_registration) = sold; offer_date with neither = under offer; declined = excluded",
            ],
        ];
    }

    /**
     * Real sale activity for a suburb, split honestly by stage — never a
     * single blended count. 2026-08-25 fix: the prior method
     * (achievedSalesFromDr2) called EVERY deals_v2 row "achieved" regardless
     * of status — an offer with no registration was presented as a sale —
     * and never looked at DR1 at all, where genuinely registered sales
     * actually live (30 on QA1, all with deal_v2_id NULL, i.e. never
     * touched DR2).
     *
     * Johan's ruling, 2026-08-25, verbatim: "pending = under offer, granted
     * and registered = sold." "Granted" means every suspensive condition
     * on the deal (bond approval, sale of an existing property, etc.) has
     * been fulfilled — the offer is unconditional
     * (DealPipelineService::allSuspensiveComplete(), AND-gated across all
     * suspensive steps). RISK FLAGGED TO JOHAN, not resolved here: a
     * granted deal can still collapse — DealPipelineService's own
     * applyNegativeStageEffect() allows a 'cancelled' outcome on any
     * non-suspensive step AFTER granted, right up to registration (deeds
     * office / attorney / closing-process failure). Calling a granted deal
     * "sold" is his call to make with that risk in view, not a data error.
     *
     * SOLD  = DR2 deals_v2.status === 'granted', OR DR1 deals.accepted_status
     *         === 'G', OR DR1 deals.registration_date populated, OR DR2
     *         deals_v2.actual_registration populated.
     * UNDER OFFER = has an offer_date (DR2) and is none of the above.
     * EXCLUDED = declined (DR2 status === 'declined' / DR1 accepted_status
     *         === 'D') — not a sale, not an offer in progress, nothing to
     *         report.
     *
     * Comparability: a sold record with no beds/baths/property_type
     * resolvable via a real properties.id link cannot be characterised and
     * must never be presented as a comparable to a seller — flagged via
     * 'comparable' rather than guessed at (no fuzzy address matching is
     * attempted for this purpose; the address-LIKE fallback below is only
     * ever used for suburb attribution, the same bar InternalDealsAdapter
     * itself uses).
     *
     * Customer-facing wording lives in one place: App\Support\Sales\SaleStageLabel.
     */
    private function salesActivityForSuburb(int $agencyId, string $suburbNorm): array
    {
        $dr2Rows = DB::table('deals_v2')
            ->join('deals', 'deals.id', '=', 'deals_v2.legacy_deal_id')
            ->leftJoin('properties', 'properties.id', '=', 'deals.property_id')
            ->where('deals_v2.agency_id', $agencyId)
            ->whereNull('deals_v2.deleted_at')
            ->where('deals_v2.status', '!=', 'declined')
            ->where(function ($q) use ($suburbNorm) {
                $q->where('properties.suburb_normalised', $suburbNorm)
                  ->orWhere('deals.property_address', 'like', '%' . $suburbNorm . '%');
            })
            ->select(
                'deals_v2.id as deal_id',
                'deals_v2.purchase_price',
                'deals_v2.status',
                'deals_v2.offer_date',
                'deals_v2.actual_registration',
                'deals.registration_date as dr1_registration_date',
                'deals.property_id',
                'deals.property_address',
                'properties.address as property_address_resolved',
                'properties.beds', 'properties.baths', 'properties.property_type'
            )
            ->get();

        // DR1-only rows: deal_v2_id is NULL so they can never already be
        // present in $dr2Rows above (that query is anchored on deals_v2 and
        // reaches deals only via legacy_deal_id). Only the granted/registered
        // (sold) side is sourced from DR1 — its own pending/blank stages are
        // out of scope here, same as before this change.
        $dr1OnlyRows = DB::table('deals')
            ->leftJoin('properties', 'properties.id', '=', 'deals.property_id')
            ->where('deals.agency_id', $agencyId)
            ->whereNull('deals.deleted_at')
            ->whereNull('deals.deal_v2_id')
            ->where(function ($q) {
                $q->where('deals.accepted_status', 'G')->orWhereNotNull('deals.registration_date');
            })
            ->where(function ($q) {
                $q->whereNull('deals.accepted_status')->orWhere('deals.accepted_status', '!=', 'D');
            })
            ->where(function ($q) use ($suburbNorm) {
                $q->where('properties.suburb_normalised', $suburbNorm)
                  ->orWhere('deals.property_address', 'like', '%' . $suburbNorm . '%');
            })
            ->select(
                'deals.id as deal_id',
                DB::raw('COALESCE(deals.sale_price, deals.property_value) as purchase_price'),
                DB::raw("'granted' as status"),
                DB::raw('NULL as offer_date'),
                DB::raw('NULL as actual_registration'),
                'deals.registration_date as dr1_registration_date',
                'deals.property_id',
                'deals.property_address',
                'properties.address as property_address_resolved',
                'properties.beds', 'properties.baths', 'properties.property_type'
            )
            ->get();

        $sold = [];
        $underOffer = [];

        foreach ($dr2Rows->concat($dr1OnlyRows) as $r) {
            $isSold = $r->status === 'granted'
                || $r->actual_registration !== null
                || $r->dr1_registration_date !== null;
            $isUnderOffer = !$isSold && $r->offer_date !== null;
            if (!$isSold && !$isUnderOffer) {
                continue; // no offer, no acceptance — not a sale event
            }

            $record = [
                'deal_id'       => $r->deal_id,
                'price'         => (int) $r->purchase_price,
                'address'       => $r->property_address_resolved ?? $r->property_address,
                'beds'          => $r->beds,
                'baths'         => $r->baths,
                'property_type' => $r->property_type,
                'comparable'    => $r->beds !== null && $r->baths !== null && $r->property_type !== null,
                'stage'         => $isSold ? SaleStageLabel::SOLD : SaleStageLabel::UNDER_OFFER,
            ];

            if ($isSold) {
                $sold[] = $record;
            } else {
                $underOffer[] = $record;
            }
        }

        return ['sold' => $sold, 'under_offer' => $underOffer];
    }

    /**
     * Live buyer demand — Core Match wishlists. The layer nobody else can
     * print. Real-time by construction. Reports BOTH legitimate
     * definitions (2026-08-24 correction): deduplicated by contact, active
     * wishlist rows only (excludes auto-archived-by-date), excludes
     * contacts whose buyer_state is cold or lost — a wishlist row being
     * technically 'active' doesn't mean the person behind it still is.
     */
    private function layerC(int $agencyId, int $p24SuburbId): array
    {
        $today = now()->toDateString();

        $active = ContactMatch::withoutGlobalScopes()
            ->join('contacts', 'contacts.id', '=', 'contact_matches.contact_id')
            ->where('contact_matches.agency_id', $agencyId)
            ->where('contact_matches.status', ContactMatch::STATUS_ACTIVE)
            ->where(function ($q) use ($today) {
                $q->whereNull('contact_matches.auto_archive_at')->orWhere('contact_matches.auto_archive_at', '>', $today);
            })
            ->whereNotNull('contact_matches.p24_suburb_ids')
            ->whereNull('contacts.deleted_at')
            ->whereNotIn('contacts.buyer_state', ['lost', 'cold'])
            ->select('contact_matches.contact_id', 'contact_matches.p24_suburb_ids', 'contact_matches.price_min', 'contact_matches.price_max')
            ->get();

        $inSuburb = $active->filter(function ($m) use ($p24SuburbId) {
            // ContactMatch casts p24_suburb_ids to 'array' — already a PHP
            // array here, not JSON text. json_decode()-ing it again (a real
            // bug caught 2026-08-25 via an "Array to string conversion"
            // warning) silently produced an empty array every time and
            // would have zeroed out this entire layer without erroring.
            $ids = is_array($m->p24_suburb_ids) ? $m->p24_suburb_ids : [];
            return in_array($p24SuburbId, $ids, true) || in_array((string) $p24SuburbId, $ids, true);
        })->values();

        $specificallyThisSuburb = $inSuburb->filter(function ($m) {
            // ContactMatch casts p24_suburb_ids to 'array' — already a PHP
            // array here, not JSON text. json_decode()-ing it again (a real
            // bug caught 2026-08-25 via an "Array to string conversion"
            // warning) silently produced an empty array every time and
            // would have zeroed out this entire layer without erroring.
            $ids = is_array($m->p24_suburb_ids) ? $m->p24_suburb_ids : [];
            return count($ids) === 1;
        });

        $bands = [];
        foreach ($inSuburb->unique('contact_id') as $m) {
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
            'available' => true,
            'as_at'     => now()->toIso8601String(),
            // Exact labels, per Johan 2026-08-24 — the report must state
            // which definition each number uses, never an unqualified count.
            'buyers_specifically_this_suburb' => $specificallyThisSuburb->pluck('contact_id')->unique()->count(),
            'buyers_including_this_suburb'    => $inSuburb->pluck('contact_id')->unique()->count(),
            'price_bands' => $bands,
        ];
    }
}

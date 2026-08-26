<?php

namespace App\Services\Website;

use App\Models\ListingWebsiteStat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the website listing statistics — everything the UI asks of the
 * daily series that WebsiteListingStatsIngestService writes.
 *
 * Three surfaces consume it:
 *   • the "Website Performance" panel on a listing's Intelligence tab,
 *   • the sortable "Views (30d)" column on the listings index,
 *   • the agent's "Website Performance" card on the Today dashboard.
 *
 * Every method is agency-scoped by explicit argument rather than by the global
 * AgencyScope: these run off raw aggregate queries (the point is to sum
 * thousands of rows in the database, not to hydrate them), so the tenant filter
 * is passed in and applied by hand at every call site.
 *
 * Spec: .ai/specs/website-listing-stats.md §5
 */
class WebsiteListingStatsReportService
{
    /** Default reporting window — matches the "Views (30d)" column and the panel tiles. */
    public const WINDOW_DAYS = 30;

    /**
     * Has this agency EVER received website stats?
     *
     * Gates whether the panel and the index column render at all. An agency with
     * no site on the API must not be shown a permanently empty panel and a column
     * of dashes — that is dead UI that teaches people to ignore the screen.
     */
    public function agencyHasStats(?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }

        return DB::table('listing_website_stats')->where('agency_id', $agencyId)->exists();
    }

    /**
     * Everything the "Website Performance" panel renders for one listing.
     *
     * @return array{
     *     has_data:bool, days:int, from:string, to:string,
     *     window:array<string,int>, lifetime:array<string,int>,
     *     views:int, unique_views:int, impressions:int, enquiries:int, contact_clicks:int,
     *     conversion:?float, series:array<int,array{date:string,views:int}>,
     *     peak:int, sites:array<int,array{site:string,views:int}>,
     *     last_received_at:?\Illuminate\Support\Carbon
     * }
     */
    public function performanceFor(int $propertyId, ?int $agencyId, int $days = self::WINDOW_DAYS): array
    {
        $days = max(1, min($days, 365));
        $to   = Carbon::now()->startOfDay();
        $from = $to->copy()->subDays($days - 1);

        $window   = $this->windowTotalsByMetric($propertyId, $agencyId, $from, $to);
        $lifetime = $this->lifetimeTotalsByMetric($propertyId, $agencyId);
        $series   = $this->dailySeries($propertyId, $agencyId, ListingWebsiteStat::METRIC_DETAIL_VIEW, $from, $to);

        $views    = (int) ($window[ListingWebsiteStat::METRIC_DETAIL_VIEW] ?? 0);
        $enquiries = (int) ($window[ListingWebsiteStat::METRIC_ENQUIRY] ?? 0);

        $contactClicks = 0;
        foreach (ListingWebsiteStat::CONTACT_METRICS as $metric) {
            $contactClicks += (int) ($window[$metric] ?? 0);
        }

        return [
            'has_data'       => ! empty($window) || ! empty($lifetime),
            'days'           => $days,
            'from'           => $from->format('Y-m-d'),
            'to'             => $to->format('Y-m-d'),
            'window'         => $window,
            'lifetime'       => $lifetime,
            'views'          => $views,
            'unique_views'   => (int) ($window[ListingWebsiteStat::METRIC_UNIQUE_DETAIL_VIEW] ?? 0),
            'impressions'    => (int) ($window[ListingWebsiteStat::METRIC_IMPRESSION] ?? 0),
            'enquiries'      => $enquiries,
            'contact_clicks' => $contactClicks,
            // Conversion is meaningless without views — null renders as "—",
            // never as a triumphant 0.0% on a listing nobody has opened.
            'conversion'     => $views > 0 ? round(($enquiries / $views) * 100, 1) : null,
            'series'         => $series,
            'peak'           => (int) max([0, ...array_column($series, 'views')]),
            'sites'          => $this->sitesFor($propertyId, $agencyId, $from, $to),
            'last_received_at' => $this->lastReceivedAt($agencyId),
        ];
    }

    /**
     * Views per listing over the window, for a page of the listings index.
     *
     * @param  array<int,int>  $propertyIds
     * @return array<int,int>  property_id => views
     */
    public function viewsForProperties(array $propertyIds, ?int $agencyId, int $days = self::WINDOW_DAYS): array
    {
        $propertyIds = array_values(array_unique(array_map('intval', $propertyIds)));
        if (! $propertyIds || ! $agencyId) {
            return [];
        }

        $from = Carbon::now()->startOfDay()->subDays(max(1, $days) - 1);

        return DB::table('listing_website_stats')
            ->where('agency_id', $agencyId)
            ->whereIn('property_id', $propertyIds)
            ->where('metric', ListingWebsiteStat::METRIC_DETAIL_VIEW)
            ->where('stat_date', '>=', $from->format('Y-m-d'))
            ->groupBy('property_id')
            ->pluck(DB::raw('SUM(metric_count) as views'), 'property_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * The agent's Today-dashboard totals: engagement across every listing they
     * own (primary or co-listing), plus the listings pulling the most traffic.
     *
     * @return array{views:int, enquiries:int, impressions:int, contact_clicks:int, top:array<int,array{id:int,title:string,views:int}>}
     */
    public function agentTotals(int $userId, ?int $agencyId, int $days = self::WINDOW_DAYS): array
    {
        $empty = ['views' => 0, 'enquiries' => 0, 'impressions' => 0, 'contact_clicks' => 0, 'top' => []];
        if (! $agencyId) {
            return $empty;
        }

        $from = Carbon::now()->startOfDay()->subDays(max(1, $days) - 1)->format('Y-m-d');

        $ownListings = DB::table('properties')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('agent_id', $userId)->orWhere('pp_second_agent_id', $userId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (! $ownListings) {
            return $empty;
        }

        $byMetric = DB::table('listing_website_stats')
            ->where('agency_id', $agencyId)
            ->whereIn('property_id', $ownListings)
            ->where('stat_date', '>=', $from)
            ->groupBy('metric')
            ->pluck(DB::raw('SUM(metric_count) as total'), 'metric')
            ->map(fn ($v) => (int) $v)
            ->all();

        if (! $byMetric) {
            return $empty;
        }

        $contactClicks = 0;
        foreach (ListingWebsiteStat::CONTACT_METRICS as $metric) {
            $contactClicks += (int) ($byMetric[$metric] ?? 0);
        }

        $top = DB::table('listing_website_stats as s')
            ->join('properties as p', 'p.id', '=', 's.property_id')
            ->where('s.agency_id', $agencyId)
            ->whereIn('s.property_id', $ownListings)
            ->where('s.metric', ListingWebsiteStat::METRIC_DETAIL_VIEW)
            ->where('s.stat_date', '>=', $from)
            ->groupBy('s.property_id', 'p.title')
            ->orderByDesc(DB::raw('SUM(s.metric_count)'))
            ->limit(5)
            ->get([
                's.property_id',
                DB::raw("COALESCE(NULLIF(p.title, ''), CONCAT('Property #', p.id)) as title"),
                DB::raw('SUM(s.metric_count) as views'),
            ]);

        return [
            'views'          => (int) ($byMetric[ListingWebsiteStat::METRIC_DETAIL_VIEW] ?? 0),
            'enquiries'      => (int) ($byMetric[ListingWebsiteStat::METRIC_ENQUIRY] ?? 0),
            'impressions'    => (int) ($byMetric[ListingWebsiteStat::METRIC_IMPRESSION] ?? 0),
            'contact_clicks' => $contactClicks,
            'top'            => $top->map(fn ($r) => [
                'id'    => (int) $r->property_id,
                'title' => (string) $r->title,
                'views' => (int) $r->views,
            ])->all(),
        ];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /** @return array<string,int> metric => count, summed across every site */
    private function windowTotalsByMetric(int $propertyId, ?int $agencyId, Carbon $from, Carbon $to): array
    {
        return $this->scoped($propertyId, $agencyId)
            ->whereBetween('stat_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('metric')
            ->pluck(DB::raw('SUM(metric_count) as total'), 'metric')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * Lifetime figures come from the website's OWN reported totals where it has
     * given them, because those survive a lost batch — our daily sum would
     * silently under-report the gap. Metrics it never reported a total for fall
     * back to our sum, so nothing disappears from the panel.
     *
     * @return array<string,int>
     */
    private function lifetimeTotalsByMetric(int $propertyId, ?int $agencyId): array
    {
        $ours = $this->scoped($propertyId, $agencyId)
            ->groupBy('metric')
            ->pluck(DB::raw('SUM(metric_count) as total'), 'metric')
            ->map(fn ($v) => (int) $v)
            ->all();

        $reported = DB::table('listing_website_stat_totals')
            ->where('property_id', $propertyId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->groupBy('metric')
            ->pluck(DB::raw('SUM(reported_total) as total'), 'metric')
            ->map(fn ($v) => (int) $v)
            ->all();

        foreach ($reported as $metric => $total) {
            $ours[$metric] = max((int) $total, (int) ($ours[$metric] ?? 0));
        }

        return $ours;
    }

    /**
     * A gap-filled daily series — every day in the window is present, at 0 if
     * nothing was counted, so the sparkline reads as real time rather than
     * compressing the quiet days out of existence.
     *
     * @return array<int,array{date:string,views:int}>
     */
    private function dailySeries(int $propertyId, ?int $agencyId, string $metric, Carbon $from, Carbon $to): array
    {
        $rows = $this->scoped($propertyId, $agencyId)
            ->where('metric', $metric)
            ->whereBetween('stat_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('stat_date')
            ->pluck(DB::raw('SUM(metric_count) as total'), 'stat_date')
            ->mapWithKeys(fn ($v, $k) => [substr((string) $k, 0, 10) => (int) $v])
            ->all();

        $series = [];
        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $key = $day->format('Y-m-d');
            $series[] = ['date' => $key, 'views' => (int) ($rows[$key] ?? 0)];
        }

        return $series;
    }

    /**
     * Per-site views in the window. An agency may run more than one site off one
     * CoreX agency, and "which site is working" is a different question from
     * "how much traffic".
     *
     * @return array<int,array{site:string,views:int}>
     */
    private function sitesFor(int $propertyId, ?int $agencyId, Carbon $from, Carbon $to): array
    {
        return $this->scoped($propertyId, $agencyId)
            ->where('metric', ListingWebsiteStat::METRIC_DETAIL_VIEW)
            ->whereBetween('stat_date', [$from->format('Y-m-d'), $to->format('Y-m-d')])
            ->groupBy('site')
            ->orderByDesc(DB::raw('SUM(metric_count)'))
            ->get(['site', DB::raw('SUM(metric_count) as views')])
            ->map(fn ($r) => ['site' => (string) $r->site, 'views' => (int) $r->views])
            ->all();
    }

    /**
     * When this agency's website last pushed ANYTHING. Rendered on the panel so
     * a site that has gone quiet reads as "last heard from 3 weeks ago" rather
     * than as a listing nobody is looking at.
     */
    private function lastReceivedAt(?int $agencyId): ?Carbon
    {
        if (! $agencyId) {
            return null;
        }

        $at = DB::table('website_stat_batches')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->max('received_at');

        return $at ? Carbon::parse($at) : null;
    }

    private function scoped(int $propertyId, ?int $agencyId)
    {
        return DB::table('listing_website_stats')
            ->where('property_id', $propertyId)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId));
    }
}

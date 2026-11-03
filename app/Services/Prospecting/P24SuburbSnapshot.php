<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The suburb line for the record card, next to CMA (.ai/specs/mic-work-tab.md §11.2.2): the newest monthly read of P24's free suburb pages
 * (p24_suburb_trends / p24_suburb_agencies / p24_street_numbers) as named fields. Global public facts - no agency filter. Returns null when
 * the suburb has never been read.
 *
 * Fields (all nullable except `read_on` and `age_days`):
 *   read_on, age_days
 *   sales_year, sales_count, avg_asking_price, avg_sold_price          deeds sales registered in the latest year the page shows
 *   erven_sold_count, erven_avg_sold_price, sectional_sold_count, sectional_avg_sold_price      same year, freehold stands vs sectional units
 *   avg_asking_by_beds                                                 ['3 bed' => 1450000, ..., 'Other' => ...] right now
 *   stock_month, stock_new, stock_total                                newest month on the page: adverts new to market / total on market
 *   stock_total_3m_ago, stock_total_change_3m                          the total three months earlier and the difference (null if not on the page)
 *   seller_age_band, buyer_age_band, owner_age_band                    the age band holding the largest share of sellers / buyers / owners
 *   agencies_count, top_agencies                                       agencies working the suburb, and the three with most listings there
 *   street_house_numbers_known                                         house numbers P24 lists for the suburb's streets
 */
final class P24SuburbSnapshot
{
    /** by P24's own suburb id (the number at the end of its addresses) */
    public function forP24Suburb(int $p24SuburbId, ?Carbon $today = null): ?array
    {
        $readOn = DB::table('p24_suburb_trends')->where('p24_suburb_id', $p24SuburbId)->max('read_on');
        if ($readOn === null) {
            return null;
        }
        $rows = DB::table('p24_suburb_trends')->where('p24_suburb_id', $p24SuburbId)->where('read_on', $readOn)->get(['metric', 'period', 'segment', 'value']);
        $get = fn (string $metric, string $period, string $segment = '') => ($v = $rows->first(fn ($r) => $r->metric === $metric && $r->period === $period && $r->segment === $segment)) ? (float) $v->value : null;

        // the latest year the sales table has a figure for
        $year = $rows->where('metric', 'sales_count')->pluck('period')->filter(fn ($p) => preg_match('/^\d{4}$/', $p))->sort()->last();
        $months = $rows->where('metric', 'stock_total')->pluck('period')->sort()->values();
        $month = $months->last();
        $ago = $months->count() >= 4 ? $months[$months->count() - 4] : null;

        $top = fn (string $who) => ($r = $rows->where('metric', 'age_profile_share')->where('segment', $who)->sortByDesc('value')->first()) && (float) $r->value > 0 ? $r->period : null;
        $agencyRead = DB::table('p24_suburb_agencies')->where('p24_suburb_id', $p24SuburbId)->max('read_on');
        $agencies = $agencyRead ? DB::table('p24_suburb_agencies')->where('p24_suburb_id', $p24SuburbId)->where('read_on', $agencyRead)->orderByDesc('listings_in_suburb')->get(['agency_name', 'listings_in_suburb']) : collect();

        $totalNow = $month ? $get('stock_total', $month) : null;
        $total3 = $ago ? $get('stock_total', $ago) : null;

        return [
            'read_on' => (string) $readOn,
            'age_days' => (int) Carbon::parse($readOn)->diffInDays($today ?? now()),
            'sales_year' => $year ? (int) $year : null,
            'sales_count' => $year ? $get('sales_count', $year) : null,
            'avg_asking_price' => $year ? $get('avg_asking_price', $year) : null,
            'avg_sold_price' => $year ? $get('avg_sold_price', $year) : null,
            'erven_sold_count' => $year ? $get('sold_count', $year, 'erven') : null,
            'erven_avg_sold_price' => $year ? $get('sold_avg_price', $year, 'erven') : null,
            'sectional_sold_count' => $year ? $get('sold_count', $year, 'sectional') : null,
            'sectional_avg_sold_price' => $year ? $get('sold_avg_price', $year, 'sectional') : null,
            'avg_asking_by_beds' => $rows->where('metric', 'avg_asking_by_beds')->mapWithKeys(fn ($r) => [$r->segment => (float) $r->value])->all() ?: null,
            'stock_month' => $month,
            'stock_new' => $month ? $get('stock_new', $month) : null,
            'stock_total' => $totalNow,
            'stock_total_3m_ago' => $total3,
            'stock_total_change_3m' => $totalNow !== null && $total3 !== null ? $totalNow - $total3 : null,
            'seller_age_band' => $top('sellers'),
            'buyer_age_band' => $top('buyers'),
            'owner_age_band' => $top('owners'),
            'agencies_count' => $agencies->count() ?: null,
            'top_agencies' => $agencies->take(3)->map(fn ($a) => ['agency' => $a->agency_name, 'listings' => (int) $a->listings_in_suburb])->all() ?: null,
            'street_house_numbers_known' => (int) DB::table('p24_street_numbers')->where('p24_suburb_id', $p24SuburbId)->count() ?: null,
        ];
    }

    /** by our own suburb id (p24_suburbs.id) */
    public function forSuburb(int $suburbId, ?Carbon $today = null): ?array
    {
        $p24Id = DB::table('p24_suburbs')->where('id', $suburbId)->value('p24_id');

        return $p24Id ? $this->forP24Suburb((int) $p24Id, $today) : null;
    }
}

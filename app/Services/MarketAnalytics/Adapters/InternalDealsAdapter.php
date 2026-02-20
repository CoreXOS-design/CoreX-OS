<?php

namespace App\Services\MarketAnalytics\Adapters;

use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\Contracts\SoldTransactionsSource;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use App\Services\MarketAnalytics\Helpers\QueryHasher;
use App\Services\MarketAnalytics\Support\SourceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns completed (registered) internal deals as sold transaction records.
 *
 * Field mapping from deals table:
 *   sold_date      ← registration_date  (canonical settled date; only registered deals returned)
 *   sold_price_inc ← property_value     (stored INCL VAT — confirmed by Deal model docblock)
 *   suburb_slug    ← filter value       (deals have no structured suburb field; we LIKE-match on
 *                                         property_address and then return the filter's slug as
 *                                         the canonical suburb identifier)
 *   property_type  ← null               (not stored in deals table)
 *   bedrooms       ← null               (not stored in deals table)
 *   listed_date    ← null               (not stored in deals table)
 */
class InternalDealsAdapter implements SoldTransactionsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'internal_deals_v1';

    private ?SourceRecord $lastSourceRecord = null;

    public function getRecords(SoldTransactionsFilter $filter): Collection
    {
        // De-slug for LIKE matching: 'north-shore' → 'north shore'
        $suburbName = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        $query = DB::table('deals')
            ->whereNotNull('registration_date')
            ->where(function ($q) {
                // Exclude declined deals
                $q->whereNull('accepted_status')
                  ->orWhere('accepted_status', '!=', 'D');
            })
            ->whereBetween('registration_date', [$filter->dateFrom, $filter->dateTo])
            ->whereRaw('LOWER(property_address) LIKE ?', ['%' . $suburbName . '%'])
            ->select(['id', 'registration_date', 'property_value', 'property_address']);

        if ($filter->branchId !== null) {
            $query->where('branch_id', $filter->branchId);
        }

        // Capture stable query hash before execution
        $qHash = QueryHasher::hash($query->toSql(), $query->getBindings());

        $deals = $query->get();

        $this->lastSourceRecord = new SourceRecord(
            sourceTag: self::SOURCE_TAG,
            rowCount:  $deals->count(),
            queryHash: $qHash,
        );

        return $deals->map(fn ($deal) => [
            'source_tag'     => self::SOURCE_TAG,
            'deal_id'        => $deal->id,
            'sold_date'      => $deal->registration_date,   // Y-m-d string from DB
            'sold_price_inc' => (float)($deal->property_value ?? 0),
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => null,  // not in deals table
            'bedrooms'       => null,  // not in deals table
            'listed_date'    => null,  // not in deals table
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }
}

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
 * Returns sold comps from the presentation_sold_comps evidence table.
 *
 * Used as a fallback when the internal deals adapter returns 0 rows
 * and the MarketAnalyticsInput carries a presentation_id.
 *
 * Source tag is distinct so audit can show which source was used.
 */
class PresentationSoldCompsAdapter implements SoldTransactionsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'presentation_uploads_sales_v1';

    private ?SourceRecord $lastSourceRecord = null;

    public function __construct(private int $presentationId) {}

    public function getRecords(SoldTransactionsFilter $filter): Collection
    {
        $suburbName = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        $query = DB::table('presentation_sold_comps')
            ->where('presentation_id', $this->presentationId)
            ->whereBetween('sold_date', [$filter->dateFrom, $filter->dateTo])
            ->select([
                'id', 'sold_date', 'sold_price_inc', 'suburb',
                'property_type', 'beds', 'size_m2', 'listed_date', 'source_upload_id', 'parser_version',
            ]);

        // Suburb filter: loose match when suburb is present; include nulls
        if ($suburbName !== '') {
            $query->where(function ($q) use ($suburbName) {
                $q->whereNull('suburb')
                  ->orWhereRaw('LOWER(suburb) LIKE ?', ['%' . $suburbName . '%']);
            });
        }

        $qHash = QueryHasher::hash($query->toSql(), $query->getBindings());
        $rows  = $query->get();

        $this->lastSourceRecord = new SourceRecord(
            sourceTag: self::SOURCE_TAG,
            rowCount:  $rows->count(),
            queryHash: $qHash,
        );

        return $rows->map(fn ($row) => [
            'source_tag'     => self::SOURCE_TAG,
            'deal_id'        => 'psc_' . $row->id,
            'sold_date'      => $row->sold_date,
            'sold_price_inc' => (float)($row->sold_price_inc ?? 0),
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => $row->property_type ?? null,
            'bedrooms'       => isset($row->beds) ? (int)$row->beds : null,
            'listed_date'    => $row->listed_date ?? null,
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }
}

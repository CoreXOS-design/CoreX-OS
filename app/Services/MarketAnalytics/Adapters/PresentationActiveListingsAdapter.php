<?php

namespace App\Services\MarketAnalytics\Adapters;

use App\Services\MarketAnalytics\Contracts\ActiveListingsSource;
use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\DTOs\ActiveListingsFilter;
use App\Services\MarketAnalytics\Helpers\QueryHasher;
use App\Services\MarketAnalytics\Support\SourceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns active listings from the presentation_active_listings evidence table.
 *
 * Used as a fallback when the imported listings adapter returns 0 rows
 * and the MarketAnalyticsInput carries a presentation_id.
 */
class PresentationActiveListingsAdapter implements ActiveListingsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'presentation_uploads_stock_v1';

    private ?SourceRecord $lastSourceRecord = null;

    public function __construct(private int $presentationId) {}

    public function getRecords(ActiveListingsFilter $filter): Collection
    {
        $suburbName = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        $query = DB::table('presentation_active_listings')
            ->where('presentation_id', $this->presentationId)
            ->select([
                'id', 'listing_date', 'list_price_inc', 'suburb',
                'property_type', 'beds', 'size_m2', 'status', 'source_upload_id', 'parser_version',
            ]);

        // Phase B1: when dedupe is enabled, only use active, de-duped rows
        if (config('features.listing_dedupe_v1', false)) {
            $query->where('is_active', true);
        }

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
            'external_id'    => 'pal_' . $row->id,
            'list_price_inc' => isset($row->list_price_inc) ? (float)$row->list_price_inc : null,
            'size_m2'        => isset($row->size_m2) ? (int)$row->size_m2 : null,
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => $row->property_type ?? null,
            'bedrooms'       => isset($row->beds) ? (int)$row->beds : null,
            'as_at_date'     => $filter->asAtDate,
            'run_id'         => null,
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }
}

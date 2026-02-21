<?php

namespace App\Services\MarketAnalytics\Adapters;

use App\Services\MarketAnalytics\Contracts\ActiveListingsSource;
use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\DTOs\ActiveListingsFilter;
use App\Services\MarketAnalytics\Helpers\QueryHasher;
use App\Services\MarketAnalytics\Helpers\SizeExtractor;
use App\Services\MarketAnalytics\Support\SourceRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Returns active listings from the imported listing snapshots.
 *
 * "Active as of date" is defined as: rows belonging to the most recent
 * listing_import_run whose created_at date is <= asAtDate.
 *
 * Run selection: prefer status IN ('applied','ready') over draft runs so that
 * published snapshots are used when available; falls back to latest run of any status.
 *
 * Field mapping from listing_import_rows / listing_import_runs:
 *   list_price_inc ← price_cents / 100            (cents to dollars)
 *   size_m2        ← SizeExtractor::fromPayload()  (optional, from row_payload JSON)
 *   suburb_slug    ← filter value                  (no structured suburb; LIKE on property field)
 *   property_type  ← null                          (not in listing_import_rows)
 *   bedrooms       ← null                          (not in listing_import_rows)
 */
class ImportedListingsAdapter implements ActiveListingsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'imported_listings_v1';

    private ?SourceRecord $lastSourceRecord = null;

    /**
     * Implements ActiveListingsSource::getRecords().
     * Returns listings active as of filter->asAtDate.
     */
    public function getRecords(ActiveListingsFilter $filter): Collection
    {
        return $this->queryActiveAsOf($filter->asAtDate, $filter);
    }

    /**
     * Listings from the latest import snapshot on or before $asAtDate.
     */
    public function queryActiveAsOf(string $asAtDate, ActiveListingsFilter $filter): Collection
    {
        $suburbName = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        // Build base query without ordering (shared constraints)
        $runBase = DB::table('listing_import_runs')
            ->whereDate('created_at', '<=', $asAtDate);

        if ($filter->branchId !== null) {
            $runBase->where('branch_id', $filter->branchId);
        }

        // Prefer published (applied/ready) runs; fall back to latest regardless of status
        $latestRun = (clone $runBase)
            ->whereIn('status', ['applied', 'ready'])
            ->orderByDesc('created_at')
            ->first();

        if (!$latestRun) {
            $latestRun = (clone $runBase)
                ->orderByDesc('created_at')
                ->first();
        }

        if (!$latestRun) {
            $this->lastSourceRecord = new SourceRecord(
                sourceTag: self::SOURCE_TAG,
                rowCount:  0,
                queryHash: QueryHasher::hash('no_run_found', ['as_at_date' => $asAtDate]),
            );
            return collect();
        }

        $rowQuery = DB::table('listing_import_rows')
            ->where('run_id', $latestRun->id)
            ->whereRaw('LOWER(property) LIKE ?', ['%' . $suburbName . '%'])
            ->select(['id', 'external_id', 'property', 'price_cents', 'run_id', 'row_payload']);

        $qHash = QueryHasher::hash($rowQuery->toSql(), $rowQuery->getBindings());

        $rows = $rowQuery->get();

        $this->lastSourceRecord = new SourceRecord(
            sourceTag:         self::SOURCE_TAG,
            rowCount:          $rows->count(),
            queryHash:         $qHash,
            snapshotRunId:     $latestRun->id,
            snapshotCreatedAt: $latestRun->created_at,
        );

        return $rows->map(fn ($row) => [
            'source_tag'     => self::SOURCE_TAG,
            'external_id'    => $row->external_id,
            'list_price_inc' => isset($row->price_cents) ? (float)$row->price_cents / 100 : null,
            'size_m2'        => SizeExtractor::fromPayload($row->row_payload ?? null),
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => null,  // not in listing_import_rows
            'bedrooms'       => null,  // not in listing_import_rows
            'as_at_date'     => $asAtDate,
            'run_id'         => $latestRun->id,
        ]);
    }

    /**
     * Listings appearing in import runs within a date range.
     * For future use in phase 2.3+ (new-to-market metrics).
     */
    public function queryNewInPeriod(string $dateFrom, string $dateTo, ActiveListingsFilter $filter): Collection
    {
        $suburbName = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        $runIds = DB::table('listing_import_runs')
            ->whereDate('created_at', '>=', $dateFrom)
            ->whereDate('created_at', '<=', $dateTo)
            ->when($filter->branchId !== null, fn ($q) => $q->where('branch_id', $filter->branchId))
            ->pluck('id');

        if ($runIds->isEmpty()) {
            $this->lastSourceRecord = new SourceRecord(
                sourceTag: self::SOURCE_TAG,
                rowCount:  0,
                queryHash: QueryHasher::hash('no_runs_in_period', ['from' => $dateFrom, 'to' => $dateTo]),
            );
            return collect();
        }

        $rowQuery = DB::table('listing_import_rows')
            ->whereIn('run_id', $runIds->all())
            ->whereRaw('LOWER(property) LIKE ?', ['%' . $suburbName . '%'])
            ->select(['id', 'external_id', 'property', 'price_cents', 'run_id']);

        $qHash = QueryHasher::hash($rowQuery->toSql(), $rowQuery->getBindings());

        $rows = $rowQuery->get();

        $this->lastSourceRecord = new SourceRecord(
            sourceTag: self::SOURCE_TAG,
            rowCount:  $rows->count(),
            queryHash: $qHash,
        );

        return $rows->map(fn ($row) => [
            'source_tag'     => self::SOURCE_TAG,
            'external_id'    => $row->external_id,
            'list_price_inc' => isset($row->price_cents) ? (float)$row->price_cents / 100 : null,
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => null,  // not in listing_import_rows
            'bedrooms'       => null,  // not in listing_import_rows
            'run_id'         => $row->run_id,
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }
}

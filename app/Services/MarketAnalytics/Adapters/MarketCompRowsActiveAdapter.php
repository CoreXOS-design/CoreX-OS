<?php

declare(strict_types=1);

namespace App\Services\MarketAnalytics\Adapters;

use App\Services\MarketAnalytics\Contracts\ActiveListingsSource;
use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\DTOs\ActiveListingsFilter;
use App\Services\MarketAnalytics\Helpers\QueryHasher;
use App\Services\MarketAnalytics\Support\SourceRecord;
use App\Support\MarketAnalytics\HaversineDistance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3b — active-listing source backed by `market_report_comp_rows`.
 *
 * Sister to MarketCompRowsSoldAdapter — same scope-branching logic, but
 * reads rows with `row_type = 'listing'` (active for-sale entries from
 * Property Valuation page-12 "FOR SALE" blocks etc).
 *
 * SECURITY — this read IS agency-scoped (filter->agencyId), same as the
 * sold adapter. See MarketCompRowsSoldAdapter's docblock: market_report_comp_rows
 * is not covered by the market_data_points shared-pool exception
 * (mic-complete-spec §13.1/§13.2 scopes that exception to market_data_points only).
 */
final class MarketCompRowsActiveAdapter implements ActiveListingsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'market_report_comp_rows_active_v1';

    private ?SourceRecord $lastSourceRecord = null;

    public function getRecords(ActiveListingsFilter $filter): Collection
    {
        $query = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            // SECURITY — see MarketCompRowsSoldAdapter. A null agencyId
            // matches no rows (fail closed) rather than leaking every
            // agency's data.
            ->where('agency_id', $filter->agencyId)
            ->where('row_type', 'listing')
            ->whereNotNull('list_price')
            // Phase 3h Step 9 — demo/real isolation.
            ->where('is_demo', $filter->subjectIsDemo)
            ->select([
                'id', 'market_report_id', 'list_price', 'list_price as list_price_inc',
                'suburb_normalised', 'scheme_name', 'section_number',
                'property_type', 'extent_m2', 'latitude', 'longitude',
                'address',
            ]);

        $rows = $query->get();

        $rows = $this->applyScope($rows, $filter);
        $rows = $this->applyPropertyTypeFilter($rows, $filter->propertyType);

        $qHash = QueryHasher::hash(
            $query->toSql() . '|scope:' . $filter->compScope . '|r:' . $filter->compRadiusM,
            $query->getBindings(),
        );

        $this->lastSourceRecord = new SourceRecord(
            sourceTag: self::SOURCE_TAG,
            rowCount:  $rows->count(),
            queryHash: $qHash,
        );

        return $rows->map(fn ($row) => [
            'source_tag'     => self::SOURCE_TAG,
            'external_id'    => 'mrcr_' . $row->id,
            'list_price_inc' => isset($row->list_price) ? (float) $row->list_price : null,
            'size_m2'        => isset($row->extent_m2) ? (int) $row->extent_m2 : null,
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => $row->property_type ?? null,
            'bedrooms'       => null,
            'as_at_date'     => $filter->asAtDate,
            'run_id'         => null,
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }

    private function applyScope(Collection $rows, ActiveListingsFilter $filter): Collection
    {
        $suburbNeedle = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        if ($filter->compScope === ActiveListingsFilter::SCOPE_SUBURB_ONLY) {
            return $rows->filter(fn ($row) => $this->matchesSuburb($row->suburb_normalised, $suburbNeedle))->values();
        }

        $haveSubjectGeo = $filter->subjectLatitude !== null && $filter->subjectLongitude !== null;
        $radius = max(1, $filter->compRadiusM);

        return $rows->filter(function ($row) use ($haveSubjectGeo, $filter, $suburbNeedle, $radius) {
            if ($haveSubjectGeo && $row->latitude !== null && $row->longitude !== null) {
                $d = HaversineDistance::distanceMetres(
                    (float) $filter->subjectLatitude,
                    (float) $filter->subjectLongitude,
                    (float) $row->latitude,
                    (float) $row->longitude,
                );
                return $d <= $radius;
            }
            return $this->matchesSuburb($row->suburb_normalised, $suburbNeedle);
        })->values();
    }

    private function matchesSuburb(?string $rowSuburb, string $needle): bool
    {
        if ($needle === '') return true;
        if (!is_string($rowSuburb) || $rowSuburb === '') return false;
        return str_contains(mb_strtolower($rowSuburb), $needle);
    }

    private function applyPropertyTypeFilter(Collection $rows, string $type): Collection
    {
        $type = strtolower(trim($type));
        if ($type === '' || $type === 'other') return $rows;

        return $rows->filter(function ($row) use ($type) {
            $rowType = strtolower((string) ($row->property_type ?? ''));
            if ($rowType === '' || $rowType === 'residence') return true;
            return str_contains($rowType, $type);
        })->values();
    }
}

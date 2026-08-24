<?php

declare(strict_types=1);

namespace App\Services\MarketAnalytics\Adapters;

use App\Services\MarketAnalytics\Contracts\HasSourceRecord;
use App\Services\MarketAnalytics\Contracts\SoldTransactionsSource;
use App\Services\MarketAnalytics\DTOs\SoldTransactionsFilter;
use App\Services\MarketAnalytics\Helpers\QueryHasher;
use App\Services\MarketAnalytics\Support\SourceRecord;
use App\Support\MarketAnalytics\HaversineDistance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 3b — sold-comp source backed by `market_report_comp_rows` (MIC).
 *
 * Sits between InternalDealsAdapter (primary, HFC's own deals) and the
 * legacy presentation_sold_comps fallback. Reads agency-wide CMA Info data
 * that the MIC importer has accumulated.
 *
 * Scope branching (driven by filter):
 *   - radius_all  → Haversine match within compRadiusM of subject lat/lng
 *                   (falls back to suburb match for rows missing geo).
 *   - suburb_only → suburb_normalised match only (legacy semantic).
 *
 * SECURITY — this read IS agency-scoped (filter->agencyId). The
 * market_data_points shared-pool design (mic-complete-spec §13.1/§13.2) is
 * scoped explicitly and ONLY to market_data_points, which carries its own
 * audit-only agency_id and an explicit auditScope()/auditScopeForAgency()
 * opt-in. market_report_comp_rows is not mentioned in §13 at all and its
 * agency_id is NOT NULL — it follows the default per-agency scoping rule
 * (§13.1), the same as every other tenant-owned table. A prior version of
 * this docblock incorrectly claimed the §13 shared-pool exception covered
 * this table too; it did not, and the unfiltered read let one agency's
 * uploaded CMA data leak into another agency's pricing/valuation output.
 */
final class MarketCompRowsSoldAdapter implements SoldTransactionsSource, HasSourceRecord
{
    public const SOURCE_TAG = 'market_report_comp_rows_sold_v1';

    private ?SourceRecord $lastSourceRecord = null;

    public function getRecords(SoldTransactionsFilter $filter): Collection
    {
        $query = DB::table('market_report_comp_rows')
            ->whereNull('deleted_at')
            // SECURITY — market_report_comp_rows.agency_id is NOT NULL and
            // is not part of the market_data_points shared-pool exception
            // (mic-complete-spec §13.1/§13.2). A null agencyId matches no
            // rows (fail closed) rather than leaking every agency's data.
            ->where('agency_id', $filter->agencyId)
            ->where('row_type', 'comp')
            ->whereNotNull('sale_date')
            ->whereNotNull('sale_price')
            // Phase 3h Step 9 — demo/real isolation. Real subjects see only
            // real comp rows; demo subjects see only demo comp rows.
            ->where('is_demo', $filter->subjectIsDemo)
            ->whereBetween('sale_date', [$filter->dateFrom, $filter->dateTo])
            ->select([
                'id', 'market_report_id', 'sale_date', 'sale_price',
                'suburb_normalised', 'scheme_name', 'section_number',
                'property_type', 'extent_m2', 'latitude', 'longitude',
                'address',
            ]);

        $rows = $query->get();

        // Filter in PHP based on scope. We pull the full date-windowed set
        // first (cheap due to the suburb-date index) and let scope decide
        // which rows match.
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
            'deal_id'        => 'mrcr_' . $row->id,
            'sold_date'      => $row->sale_date,
            'sold_price_inc' => (float) ($row->sale_price ?? 0),
            'suburb_slug'    => $filter->suburbSlug,
            'property_type'  => $row->property_type ?? null,
            'bedrooms'       => null,  // not captured in market_report_comp_rows
            'listed_date'    => null,
        ]);
    }

    public function getLastSourceRecord(): ?SourceRecord
    {
        return $this->lastSourceRecord;
    }

    /**
     * Apply the comp-scope filter:
     *   - radius_all  → Haversine within compRadiusM (suburb fallback when geo missing)
     *   - suburb_only → suburb_normalised LIKE %suburb%
     */
    private function applyScope(Collection $rows, SoldTransactionsFilter $filter): Collection
    {
        $suburbNeedle = mb_strtolower(str_replace('-', ' ', $filter->suburbSlug));

        if ($filter->compScope === SoldTransactionsFilter::SCOPE_SUBURB_ONLY) {
            return $rows->filter(fn ($row) => $this->matchesSuburb($row->suburb_normalised, $suburbNeedle))->values();
        }

        // radius_all
        $haveSubjectGeo = $filter->subjectLatitude !== null && $filter->subjectLongitude !== null;
        $radius = max(1, $filter->compRadiusM);

        return $rows->filter(function ($row) use ($haveSubjectGeo, $filter, $suburbNeedle, $radius) {
            // Row has geo + subject has geo → Haversine.
            if ($haveSubjectGeo && $row->latitude !== null && $row->longitude !== null) {
                $d = HaversineDistance::distanceMetres(
                    (float) $filter->subjectLatitude,
                    (float) $filter->subjectLongitude,
                    (float) $row->latitude,
                    (float) $row->longitude,
                );
                return $d <= $radius;
            }
            // Missing geo on either side → suburb match (graceful degrade).
            return $this->matchesSuburb($row->suburb_normalised, $suburbNeedle);
        })->values();
    }

    private function matchesSuburb(?string $rowSuburb, string $needle): bool
    {
        if ($needle === '') return true;
        if (!is_string($rowSuburb) || $rowSuburb === '') return false;
        return str_contains(mb_strtolower($rowSuburb), $needle);
    }

    /**
     * CMA Info source data uses several distinct labels for the same
     * dwelling concept ("Residence" from the property-valuation parser,
     * "Residential" from the vicinity-sale parser — same underlying erf
     * usage code, different parser vocabulary). Both map to the engine's
     * "house" / "unit" buckets. Land and anything else stay excluded from
     * house/unit and only match their own bucket, so widening residential
     * recognition can never pull commercial/vacant-land/agricultural rows
     * into a house or unit search.
     *
     * 2026-08-20 — was a single hardcoded `=== 'residence'` check, so any
     * row tagged "Residential" (25% of all comp rows agency-wide) was
     * silently invisible to every house/unit search. Fixed by normalising
     * (trim + lowercase, already done above) into two small canonical
     * buckets instead of adding more literal strings one at a time.
     */
    private const RESIDENTIAL_TYPES = ['residence', 'residential'];
    private const LAND_TYPES = ['vacant land', 'land', 'stand', 'erf'];

    private function applyPropertyTypeFilter(Collection $rows, string $type): Collection
    {
        $type = strtolower(trim($type));
        if ($type === '' || $type === 'other') return $rows;

        return $rows->filter(function ($row) use ($type) {
            $rowType = strtolower(trim((string) ($row->property_type ?? '')));

            if ($rowType === '') return true;

            if (in_array($rowType, self::RESIDENTIAL_TYPES, true)) {
                return $type === 'house' || $type === 'unit';
            }

            if (in_array($rowType, self::LAND_TYPES, true)) {
                return $type === 'land';
            }

            return str_contains($rowType, $type);
        })->values();
    }
}

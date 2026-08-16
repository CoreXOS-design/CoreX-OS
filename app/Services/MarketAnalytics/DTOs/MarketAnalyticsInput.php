<?php

namespace App\Services\MarketAnalytics\DTOs;

class MarketAnalyticsInput
{
    public const SCOPE_RADIUS_ALL  = 'radius_all';
    public const SCOPE_SUBURB_ONLY = 'suburb_only';

    public function __construct(
        public readonly string  $suburb,
        public readonly string  $propertyType,   // 'house' | 'unit' | 'land' etc.
        public readonly int     $periodMonths,   // rolling window, e.g. 12
        public readonly ?int    $bedrooms        = null,
        public readonly ?string $referenceDate   = null,  // YYYY-MM-DD; null = today
        public readonly ?int    $sourceBranchId  = null,
        public readonly ?int    $subjectSizeM2   = null,  // subject property floor area (m²)
        public readonly ?float  $subjectPriceInc = null,  // subject sold/list price incl. VAT
        public readonly ?int    $presentationId  = null,  // enables evidence-table fallback
        // Phase 3b — comp scope + subject geo (Haversine radius matching).
        public readonly string  $compScope       = self::SCOPE_RADIUS_ALL,
        public readonly int     $compRadiusM     = 1000,
        public readonly ?float  $subjectLatitude  = null,
        public readonly ?float  $subjectLongitude = null,
        // Phase 3h Step 9 — propagated to the SoldTransactionsFilter /
        // ActiveListingsFilter so adapters can isolate demo data from
        // real data. Default false keeps legacy callers safe.
        public readonly bool    $subjectIsDemo    = false,
        // SECURITY — the requesting agency, propagated to SoldTransactionsFilter /
        // ActiveListingsFilter so MarketCompRowsSoldAdapter / MarketCompRowsActiveAdapter
        // can scope their market_report_comp_rows reads. Not part of
        // toCanonicalArray(): it doesn't change hash identity semantics
        // (MarketAnalyticsRun rows are themselves agency-scoped via
        // BelongsToAgency, so a hash collision across agencies can't cross-leak).
        public readonly ?int    $agencyId         = null,
    ) {}

    /**
     * Fixed-key, fixed-order array used as the canonical input for hashing.
     * Nulls are included so missing fields are explicit. The Phase 3b
     * additions (compScope, compRadiusM, subject lat/lng) are appended so
     * legacy hashes remain stable when scope=radius_all + radius=1000 (the
     * defaults) + no subject geo (the current state for HFC).
     */
    public function toCanonicalArray(): array
    {
        return [
            'suburb'             => $this->suburb,
            'property_type'      => $this->propertyType,
            'period_months'      => $this->periodMonths,
            'bedrooms'           => $this->bedrooms,
            'reference_date'     => $this->referenceDate,
            'source_branch_id'   => $this->sourceBranchId,
            'subject_size_m2'    => $this->subjectSizeM2,
            'subject_price_inc'  => $this->subjectPriceInc,
            'comp_scope'         => $this->compScope,
            'comp_radius_m'      => $this->compRadiusM,
            'subject_latitude'   => $this->subjectLatitude,
            'subject_longitude'  => $this->subjectLongitude,
        ];
    }
}

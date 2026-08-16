<?php

namespace App\Services\MarketAnalytics\DTOs;

class SoldTransactionsFilter
{
    public const SCOPE_RADIUS_ALL  = 'radius_all';
    public const SCOPE_SUBURB_ONLY = 'suburb_only';

    public function __construct(
        public readonly string  $suburbSlug,
        public readonly string  $propertyType,
        public readonly string  $dateFrom,    // YYYY-MM-DD
        public readonly string  $dateTo,      // YYYY-MM-DD
        public readonly ?int    $bedrooms    = null,
        public readonly ?int    $branchId    = null,
        // Phase 3b additions — backward-compatible defaults preserve legacy behaviour.
        public readonly string  $compScope        = self::SCOPE_SUBURB_ONLY,
        public readonly int     $compRadiusM      = 1000,
        public readonly ?float  $subjectLatitude  = null,
        public readonly ?float  $subjectLongitude = null,
        // Phase 3h Step 9 — demo / real isolation. Defaults to false so
        // legacy callers (and real subjects) read only real data; demo-
        // tagged subjects flip this to true so they read only demo data.
        public readonly bool    $subjectIsDemo    = false,
        // SECURITY — the requesting agency. Required by
        // MarketCompRowsSoldAdapter to scope its market_report_comp_rows
        // read (that table is NOT part of the market_data_points
        // shared-pool exception; mic-complete-spec §13.1/§13.2).
        public readonly ?int    $agencyId         = null,
    ) {}
}

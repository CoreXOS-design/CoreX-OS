<?php

namespace App\Services\MarketAnalytics\DTOs;

class SoldTransactionsFilter
{
    public function __construct(
        public readonly string  $suburbSlug,
        public readonly string  $propertyType,
        public readonly string  $dateFrom,    // YYYY-MM-DD
        public readonly string  $dateTo,      // YYYY-MM-DD
        public readonly ?int    $bedrooms    = null,
        public readonly ?int    $branchId    = null,
    ) {}
}

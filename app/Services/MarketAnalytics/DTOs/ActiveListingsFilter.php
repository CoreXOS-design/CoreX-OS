<?php

namespace App\Services\MarketAnalytics\DTOs;

class ActiveListingsFilter
{
    public function __construct(
        public readonly string  $suburbSlug,
        public readonly string  $propertyType,
        public readonly string  $asAtDate,    // YYYY-MM-DD snapshot date
        public readonly ?int    $bedrooms    = null,
        public readonly ?int    $branchId    = null,
    ) {}
}

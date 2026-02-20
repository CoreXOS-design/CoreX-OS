<?php

namespace App\Services\MarketAnalytics\DTOs;

class MarketAnalyticsInput
{
    public function __construct(
        public readonly string  $suburb,
        public readonly string  $propertyType,   // 'house' | 'unit' | 'land' etc.
        public readonly int     $periodMonths,   // rolling window, e.g. 12
        public readonly ?int    $bedrooms       = null,
        public readonly ?string $referenceDate  = null,  // YYYY-MM-DD; null = today
        public readonly ?int    $sourceBranchId = null,
    ) {}

    /**
     * Fixed-key, fixed-order array used as the canonical input for hashing.
     * Nulls are included so missing fields are explicit.
     */
    public function toCanonicalArray(): array
    {
        return [
            'suburb'           => $this->suburb,
            'property_type'    => $this->propertyType,
            'period_months'    => $this->periodMonths,
            'bedrooms'         => $this->bedrooms,
            'reference_date'   => $this->referenceDate,
            'source_branch_id' => $this->sourceBranchId,
        ];
    }
}

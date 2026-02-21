<?php

namespace App\Services\SaleProbability\DTOs;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsResult;

class SaleProbabilityInput
{
    public function __construct(
        public readonly ?int                  $marketAnalyticsRunId,
        public readonly string                $marketAnalyticsModelVersion,
        public readonly string                $marketAnalyticsInputsHash,
        public readonly MarketAnalyticsResult $marketAnalyticsResult,
        // Optional subject-level overrides — populated in later phases
    ) {}

    /**
     * Fixed-key, fixed-order array used as the canonical input for hashing.
     * Nulls are included so missing fields are explicit.
     */
    public function toCanonicalArray(): array
    {
        return [
            'market_analytics_run_id' => $this->marketAnalyticsRunId,
        ];
    }
}

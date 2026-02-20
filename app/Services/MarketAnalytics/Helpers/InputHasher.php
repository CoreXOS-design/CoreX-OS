<?php

namespace App\Services\MarketAnalytics\Helpers;

use App\Services\MarketAnalytics\DTOs\MarketAnalyticsInput;

class InputHasher
{
    /**
     * Return a stable SHA-256 hex digest of the canonical input array.
     * Identical inputs always produce the same hash.
     */
    public static function hash(MarketAnalyticsInput $input): string
    {
        $canonical = json_encode($input->toCanonicalArray(), JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }
}

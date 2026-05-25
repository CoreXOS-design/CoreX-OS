<?php

declare(strict_types=1);

namespace App\Services\Geocoding;

use RuntimeException;

/**
 * Phase 11a — thrown by GeocodeRateLimiter when a daily cap is exceeded.
 * Caught at the call site to surface a friendly user message:
 * "Daily geocoding limit reached. Geocoding will resume tomorrow."
 */
final class GeocodeRateLimitException extends RuntimeException
{
    public function __construct(
        public readonly string $whichCap,   // 'environment' | 'user'
        public readonly int $current,
        public readonly int $cap,
        public readonly string $environment,
    ) {
        parent::__construct(sprintf(
            'Daily geocoding limit reached (%s cap: %d/%d on %s).',
            $whichCap, $current, $cap, $environment,
        ));
    }
}

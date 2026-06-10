<?php

namespace App\Services\Push;

/**
 * Result of a PushNotificationService dispatch — the counts every guard touched.
 * Returned so callers (and tests) can assert exactly what happened, and so the
 * dispatch can be logged without re-deriving the numbers.
 */
final class PushDispatchSummary
{
    public function __construct(
        public readonly int $requested,          // device-token rows handed in
        public readonly int $deduped,            // distinct tokens after collapsing duplicates
        public readonly int $idempotencySkipped, // dropped: already sent to that device for this key
        public readonly int $rateLimited,        // dropped: per-device per-minute cap exceeded
        public readonly int $sent,               // tokens the provider accepted
        public readonly int $attempted,          // tokens that passed all guards and were sent to the transport
    ) {}

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }
}

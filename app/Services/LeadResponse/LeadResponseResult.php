<?php

namespace App\Services\LeadResponse;

use Carbon\CarbonImmutable;

/**
 * ONE portal enquiry and what became of it — the per-lead result every lead-response summary is built on
 * (Johan, 2026-10-07: "structure the calculation as one service with a per-lead result that new summaries can
 * be added on top of"). Produced only by LeadResponseService::results(); summaries, tiles, drill-down rows and
 * future reports all read these, never re-derive.
 */
final class LeadResponseResult
{
    /** Responded within the agency's target (counted minutes <= target). */
    public const IN_TARGET = 'in_target';
    /** Responded, but after the target. */
    public const LATE = 'late';
    /** No genuine contact yet (see $overdue for "already past target"). */
    public const WAITING = 'waiting';
    /** Received before response tracking existed and nothing provable: left out of every figure. */
    public const NOT_MEASURED = 'not_measured';

    public function __construct(
        public readonly int $leadId,
        public readonly string $portal,
        public readonly ?int $contactId,
        public readonly string $contactName,
        public readonly ?int $listingId,
        public readonly ?string $listingTitle,
        public readonly ?int $agentId,
        public readonly ?string $agentName,
        public readonly CarbonImmutable $receivedAt,
        public readonly ?CarbonImmutable $firstResponseAt,
        public readonly ?int $responderId,
        public readonly ?string $responderName,
        public readonly ?string $channel,
        /** Counted minutes from arrival to first response — or, while waiting, to now. null when not measured. */
        public readonly ?int $minutes,
        public readonly string $status,
        /** Waiting AND already past the target (the "never contacted — N already late" number). */
        public readonly bool $overdue,
    ) {}

    public function isMeasured(): bool
    {
        return $this->status !== self::NOT_MEASURED;
    }

    public function isResponded(): bool
    {
        return $this->status === self::IN_TARGET || $this->status === self::LATE;
    }
}

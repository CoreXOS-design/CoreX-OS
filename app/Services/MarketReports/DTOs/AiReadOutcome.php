<?php

declare(strict_types=1);

namespace App\Services\MarketReports\DTOs;

/**
 * What the AI-read step produced for one report. Spec: mic-complete-spec §8.9.
 *
 * status 'read'   → $result is the normalised parse result, ready to persist.
 * status 'unread' → nothing was stored; $reason says why, in a sentence a person can read.
 */
final class AiReadOutcome
{
    /**
     * @param array<string, mixed>      $clean       sanitised canonical read (empty when unread)
     * @param array<string, mixed>      $layoutHints the AI's `layout` block (for the learner)
     * @param array<int, string>        $dropped     values the sanitiser refused
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly ?MarketReportParseResult $result = null,
        public readonly array $clean = [],
        public readonly ?float $confidence = null,
        public readonly ?string $publisher = null,
        public readonly array $layoutHints = [],
        public readonly bool $fromCache = false,
        public readonly array $dropped = [],
    ) {}

    public static function unread(string $reason, ?float $confidence = null, ?string $publisher = null): self
    {
        return new self(status: 'unread', reason: $reason, confidence: $confidence, publisher: $publisher);
    }

    public function wasRead(): bool
    {
        return $this->status === 'read';
    }
}

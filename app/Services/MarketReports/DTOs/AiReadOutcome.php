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
        /** Machine-readable reason for an unread outcome, e.g. 'ai_monthly_cap'. */
        public readonly ?string $code = null,
        /** The layout case the report was queued under (cap / failure / same layout already queued). */
        public readonly ?int $caseId = null,
        /** true when THIS report opened the case (the CoreX team is alerted once per case). */
        public readonly bool $newCase = false,
    ) {}

    public static function unread(string $reason, ?float $confidence = null, ?string $publisher = null, ?string $code = null, ?int $caseId = null, bool $newCase = false): self
    {
        return new self(status: 'unread', reason: $reason, confidence: $confidence, publisher: $publisher, code: $code, caseId: $caseId, newCase: $newCase);
    }

    public function wasRead(): bool
    {
        return $this->status === 'read';
    }
}

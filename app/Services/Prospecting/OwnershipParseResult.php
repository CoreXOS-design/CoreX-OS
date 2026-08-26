<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * Result of OwnershipHistoryParser::parse(). See .ai/specs/deeds-capture.md §7.9
 * for the full status table.
 */
final class OwnershipParseResult
{
    /** @param OwnershipOwnerRow[] $rows */
    public function __construct(
        public readonly array $rows,
        public readonly string $status, // 'ok' | 'warning' | 'failed'
        public readonly ?string $note,
    ) {
    }

    /** Fail-closed on ownership (§7.9 cases 1-2) — no rows, property still captures without owners. */
    public static function failed(string $note): self
    {
        return new self([], 'failed', $note);
    }
}

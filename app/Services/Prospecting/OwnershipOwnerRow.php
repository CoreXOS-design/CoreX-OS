<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

/**
 * One position parsed out of a cmainfo ownership_history_raw triple (Owner /
 * Owner's ID / Title Deed). See OwnershipHistoryParser and
 * .ai/specs/deeds-capture.md §7.
 */
final class OwnershipOwnerRow
{
    public function __construct(
        public string $name,
        public ?string $idNumber,
        public ?string $idType,          // 'sa_id' | 'trust_reg' | 'company_reg' | null (unrecognised shape)
        public ?string $deedReference,   // e.g. 'ST39075/2003', or null if the position had no deed text at all
        public ?int $deedYear,           // parsed from deedReference; null if unparseable (§7.9 case 4)
        public ?float $sharePct,         // may be propagated onto joint holders — see OwnershipHistoryParser::applyShares()
        public ?string $ownershipStatus, // 'current' | 'past' | null (unclassified — no deedYear to place it by)
    ) {
    }
}

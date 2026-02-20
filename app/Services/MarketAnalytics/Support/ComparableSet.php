<?php

namespace App\Services\MarketAnalytics\Support;

/**
 * Immutable value object holding a deterministically sorted set of comparable
 * sold transactions and their aggregate hash.
 *
 * compsHash: sha256 of the ordered array of row_hashes — changes if any row
 *            changes or the sort order changes.
 * rows:      each row is a plain array from the source adapter, with row_hash
 *            added by ComparableSetBuilder.
 */
class ComparableSet
{
    public readonly int $count;

    public function __construct(
        public readonly string $compsHash,
        public readonly array  $rows,
    ) {
        $this->count = count($rows);
    }
}

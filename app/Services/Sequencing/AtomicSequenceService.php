<?php

namespace App\Services\Sequencing;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Generic atomic, race-safe consecutive-number allocator.
 *
 * Same idiom as App\Services\Proforma\ProformaNumberService (lock a counter row
 * FOR UPDATE, read, increment, in the CALLER's own transaction — never a
 * read-max-then-increment on the target table itself, which is what let two
 * concurrent deal creations both read the same MAX(deal_no) and each mint a
 * "next" number nobody else had taken yet, producing two full deals with
 * consecutive-looking numbers, #1826 and #1827, from one user's double-click).
 *
 * Generalised into a shared service (not duplicated per feature) because two
 * unrelated callers need the exact same mechanism: Deal::deal_no (DR1/DR2,
 * scoped per agency) and DealV2::reference (scoped per agency AND per year).
 */
class AtomicSequenceService
{
    /**
     * Allocate the next value for a scope. MUST be called inside the caller's
     * own DB::transaction() — the row lock is only meaningful for the
     * lifetime of that transaction; calling this outside one still works
     * (Laravel opens an implicit one for the UPDATE) but no longer serialises
     * against whatever else the caller does before committing.
     */
    public function next(string $scope, int $startAt = 1): int
    {
        $row = DB::table('sequence_counters')->where('scope', $scope)->lockForUpdate()->first();

        if (! $row) {
            // Two concurrent first-callers for a brand-new scope could both
            // reach here at once; the table's own unique(scope) index makes
            // exactly one INSERT win and the other throw — catch that one
            // specific race and fall through to the lock-and-read path below,
            // now that a row genuinely exists for it to lock.
            try {
                DB::table('sequence_counters')->insert([
                    'scope' => $scope,
                    'next_value' => $startAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (QueryException $e) {
                if (! $this->isDuplicateKey($e)) {
                    throw $e;
                }
            }

            $row = DB::table('sequence_counters')->where('scope', $scope)->lockForUpdate()->first();
        }

        $value = (int) $row->next_value;

        DB::table('sequence_counters')->where('scope', $scope)->update([
            'next_value' => $value + 1,
            'updated_at' => now(),
        ]);

        return $value;
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        // MySQL 1062 (ER_DUP_ENTRY) / SQLSTATE 23000 — the only failure mode
        // the try/catch above exists to absorb; anything else is a real error.
        return (string) $e->getCode() === '23000' || str_contains($e->getMessage(), '1062');
    }
}

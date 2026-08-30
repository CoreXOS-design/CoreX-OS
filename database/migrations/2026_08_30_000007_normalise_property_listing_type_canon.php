<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Normalise `properties.listing_type` onto the Property::LISTING_TYPES canon
 * ('sale' | 'rental', or NULL for a listing with no type yet).
 *
 * WHY
 * ---
 * The column was never normalised on write. The P24 CSV import
 * (P24ListingsCsvParser) stored a CAPITALISED 'Sale' / 'Rental' while the UI
 * stored lowercase — and the save validator is a case-SENSITIVE
 * `in:sale,rental`. So every imported listing handed its own stored value back
 * to the validator on edit and was rejected with "The selected listing type is
 * invalid". On the Demo Agency Test account that made 4,753 of its 4,755
 * listings permanently uneditable. Reads had already been patched to tolerate
 * the divergence (Property::isRental()); the write path never was, and the data
 * was never cleaned.
 *
 * The write path is now guarded by Property::setListingTypeAttribute(), so no
 * new non-canonical value can be persisted. This migration retires the ones
 * already in the table.
 *
 * TWO THINGS THIS MIGRATION MUST GET RIGHT
 * ----------------------------------------
 * 1. The column's collation is case-INSENSITIVE (utf8mb4_*_ci). A plain
 *    `where('listing_type', '!=', 'sale')` therefore matches NOTHING — MySQL
 *    considers 'Sale' and 'sale' equal — and the migration would silently do
 *    nothing while reporting success. Every comparison here is BINARY.
 * 2. It must NOT touch `updated_at`. Bumping it on 4,753 rows would present
 *    every one of them as freshly edited across the listing views, activity
 *    surfaces and portal-refresh decisions. A raw query builder update writes
 *    only the named column. (The AT-321 audit trigger compares with `<=>`,
 *    which is likewise collation-aware, so a case-only change correctly
 *    produces no audit noise either.)
 *
 * The canon is intentionally INLINED rather than calling
 * Property::normaliseListingType(). A migration is a historical record that
 * must still replay identically years from now; it cannot depend on the current
 * shape of an app class. Behaviour is deliberately identical to that method —
 * if you change one, read the other.
 */
return new class extends Migration
{
    /** Same mapping as Property::normaliseListingType(). */
    private function canon(?string $value): ?string
    {
        $v = strtolower(trim((string) ($value ?? '')));

        if ($v === '') {
            return null;
        }

        return in_array($v, ['rental', 'to_let', 'to-let', 'lease'], true) ? 'rental' : $v;
    }

    public function up(): void
    {
        // BINARY comparison — see note 1 above. NULL is a legitimate value and
        // is left alone; we never invent a type for a listing that has none.
        $offenders = DB::table('properties')
            ->whereNotNull('listing_type')
            ->whereRaw("CAST(listing_type AS BINARY) NOT IN (CAST('sale' AS BINARY), CAST('rental' AS BINARY))")
            ->select('id', 'listing_type')
            ->get();

        if ($offenders->isEmpty()) {
            return;
        }

        $changed   = 0;
        $unmapped  = [];

        // Group by the value being fixed so this is a handful of bulk updates
        // rather than one query per row.
        foreach ($offenders->groupBy('listing_type') as $stored => $rows) {
            $target = $this->canon((string) $stored);

            // Defensive: a value the canon cannot place is REPORTED, never
            // guessed at. Leaving it visible beats silently inventing a type.
            if ($target === null || ! in_array($target, ['sale', 'rental'], true)) {
                $unmapped[(string) $stored] = $rows->count();
                continue;
            }

            $changed += DB::table('properties')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update(['listing_type' => $target]);   // updated_at untouched — see note 2
        }

        Log::info('Migration: normalised properties.listing_type onto the canon', [
            'rows_updated' => $changed,
            'unmapped'     => $unmapped,
        ]);

        if ($unmapped !== []) {
            Log::warning('Migration: properties.listing_type values left as-is (no canonical mapping)', $unmapped);
        }
    }

    /**
     * Irreversible by design. The previous state was mixed-case data that could
     * not be saved through the application at all; restoring it would restore
     * the bug. Nothing depends on the old casing — reads went through
     * Property::isRental(), which is case-insensitive.
     */
    public function down(): void
    {
        // no-op
    }
};

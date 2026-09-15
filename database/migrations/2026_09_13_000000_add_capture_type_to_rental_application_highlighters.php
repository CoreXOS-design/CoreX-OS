<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * cc2's finding, 2026-09-13 — whether a highlighter captures a ledger line
 * was decided by a raw string match on its LABEL ("Income" / "Expense",
 * see document-highlighter-script.blade.php's own captureEntryTypeFor(),
 * which flagged this exact fragility in its own comment when it shipped:
 * "an agency renaming its default Income/Expense highlighters would stop
 * them acting as capture pens... ruled out of this task's scope"). An
 * agency renaming "Income" to "Salary" or "Income (net)" silently stopped
 * capturing income — no error, the pen still drew, the line just never
 * reached the affordability panel and the total was quietly wrong. The
 * worst failure shape this screen can have: plausible, silent, only
 * caught by a hand reconciliation.
 *
 * `capture_type` is a stable identity independent of the display name —
 * renaming a highlighter now changes only what it is CALLED. Nullable,
 * default null: "no capture type" means "draws, never captures," the same
 * behaviour every non-Income/Expense highlighter (Unpaid, Electricity,
 * anything an agency adds later) already had — a deliberate value, not an
 * accident of a null (RentalApplicationHighlighter::CAPTURE_TYPES below
 * enumerates the only two real values; every other highlighter is
 * legitimately null and stays that way through this migration).
 *
 * Backfilled from the CURRENT label match below so nothing in flight
 * breaks — every highlighter presently capturing (by the old, fragile
 * rule) keeps capturing under the new one, on this exact data, not a
 * fresh fixture (proven in the accompanying regression test and via a
 * direct query against the real QA1 database before/after, documented in
 * the spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_highlighters', function (Blueprint $table) {
            $table->enum('capture_type', ['income', 'expense'])->nullable()->after('color');
        });

        // Same match captureEntryTypeFor() used client-side — trim + lower,
        // exact 'income'/'expense' only. Anything else (Unpaid, Electricity,
        // any custom label) stays null, exactly its current behaviour.
        DB::table('rental_application_highlighters')
            ->whereRaw('LOWER(TRIM(label)) = ?', ['income'])
            ->update(['capture_type' => 'income']);
        DB::table('rental_application_highlighters')
            ->whereRaw('LOWER(TRIM(label)) = ?', ['expense'])
            ->update(['capture_type' => 'expense']);
    }

    public function down(): void
    {
        Schema::table('rental_application_highlighters', function (Blueprint $table) {
            $table->dropColumn('capture_type');
        });
    }
};

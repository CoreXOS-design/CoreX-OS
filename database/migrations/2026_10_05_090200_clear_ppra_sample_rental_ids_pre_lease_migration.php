<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PPRA Inspection Pack Phase H (2026-09-28, Johan's ruling) — the picker's
 * "rental" mode changed from `App\Models\Rental` (the disconnected
 * commission-tracking table) to `App\Models\Lease`. Any sample_rental_ids
 * already stored on a ppra_inspection_packs row are old Rental ids and are
 * meaningless as Lease ids — only test packs existed at the time of this
 * change (confirmed: one row, agency 1, [59]), so this clears the column
 * rather than attempting any translation. Idempotent — safe to re-run.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ppra_inspection_packs')->whereNotNull('sample_rental_ids')->update(['sample_rental_ids' => null]);
    }

    public function down(): void
    {
        // Irreversible by design — the original Rental ids carried no
        // recoverable meaning once cleared (they were never anything but
        // test data referencing a model this picker mode no longer uses).
    }
};

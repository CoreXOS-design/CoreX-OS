<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Johan, 2026-09-29 — companion to
 * 2026_10_06_090100_mark_inventory_list_buyer_pack_eligible and the
 * RentalInventoryRecordingController::stampInventoryListDocumentType()
 * fix landed the same build: BEFORE that fix, every inventory report filed
 * via fileAndMaybeEmailReport() (`documents.source_type =
 * 'rental_inventory_report'`) had `document_type_id = NULL` — invisible to
 * the Viewing Pack's own eligibility filter (which reads
 * `documentType->slug`) no matter what the catalogue says. Backfills every
 * ALREADY-FILED row of both inventory document kinds (the completed report,
 * and a wet-ink scan — `rental_inventory_signature_wet_ink`, in case any
 * slipped through before its own inline fix) to the `inventory_list` type.
 *
 * Idempotent — only touches `document_type_id IS NULL` rows; a second run
 * matches zero. Logs the backfilled count.
 */
return new class extends Migration
{
    public function up(): void
    {
        $typeId = DB::table('document_types')->where('slug', 'inventory_list')->value('id');

        if (! $typeId) {
            Log::warning('[2026_10_06_090200] skipped — no document_types row with slug=inventory_list exists.');

            return;
        }

        $count = DB::table('documents')
            ->whereIn('source_type', ['rental_inventory_report', 'rental_inventory_signature_wet_ink'])
            ->whereNull('document_type_id')
            ->update(['document_type_id' => $typeId]);

        Log::info("[2026_10_06_090200] backfilled document_type_id (inventory_list) on {$count} rental inventory Document row(s).");
    }

    public function down(): void
    {
        // Deliberately no-op — see the sibling relabel migration's own
        // down() for the same reasoning: these rows are now CORRECTLY
        // typed, not in a temporary state worth reversing.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 Part E — "ONE document store, two views." The checklist-item
 * paperclip does NOT create a second document store; it tags a row already
 * living in the shared `documents` table (source_type='rental_application',
 * source_id=<application>) with the checklist item it was attached from.
 * Same nullable-tag-column convention already established on this exact
 * table for the same reason —
 * 2026_09_20_110000_add_custom_field_key_to_documents.php's own docblock —
 * except, unlike custom_field_key, a checklist-tagged document is NOT
 * excluded from the generic Supporting Documents list: it is meant to
 * appear in both places (Part E design point 1). RentalApplication::
 * documents() is unfiltered by this column, so no read-side change is
 * needed to satisfy that — only this write-side tag.
 *
 * nullOnDelete rather than cascadeOnDelete: a checklist item is never
 * deleted today (only sections/items in the TEMPLATE are archived; the
 * per-application snapshot row is permanent once created — see
 * RentalApplicationChecklistItem's own docblock), so this is defensive
 * only, matching custom_field_key's own choice not to hard-tie document
 * survival to a row from a different table's lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('checklist_item_id')->nullable()->after('custom_field_key')
                ->constrained('rental_application_checklist_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checklist_item_id');
        });
    }
};

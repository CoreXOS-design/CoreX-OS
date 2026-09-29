<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase A — .ai/specs/ppra-inspection-pack.md §4.1
 *
 * Nullable grouping key: two agency_document_type_configs rows sharing the
 * same satisfies_group are ALTERNATIVES — ANY one of them having a valid
 * (non-expired) provision satisfies the group. Used for "BEE certificate OR
 * sworn affidavit" (spec item h). NULL means "stands alone" — the existing
 * behaviour for every current row, no backfill needed for that half.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_document_type_configs', function (Blueprint $table) {
            $table->string('satisfies_group')->nullable()->after('slug');
        });

        // Existing bee_certificate rows (one per agency that has ever configured
        // the vault) join the 'bee' group. Scoped per-agency, never a blanket
        // UPDATE without a WHERE — this table has no agency-blind rows to worry
        // about here since every row already carries a real agency_id.
        DB::table('agency_document_type_configs')
            ->where('slug', 'bee_certificate')
            ->update(['satisfies_group' => 'bee']);
    }

    public function down(): void
    {
        Schema::table('agency_document_type_configs', function (Blueprint $table) {
            $table->dropColumn('satisfies_group');
        });
    }
};

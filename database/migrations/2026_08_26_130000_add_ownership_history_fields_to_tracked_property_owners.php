<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-owner / ownership-history capture (Johan 2026-08-19,
 * .ai/specs/deeds-capture.md §7). cmainfo's Owner / Owner's ID / Title Deed
 * cells are a TRANSFER HISTORY, not a snapshot of current co-owners — the same
 * property can carry several deeds across several years, each with its own
 * share. This table previously had no way to record which deed an owner row
 * came from, what share it carried, or whether that owner is the CURRENT
 * registered owner or a PAST one (e.g. a 1993 seller). Existing rows default
 * to ownership_status='current' — correct, since every prior capture predates
 * this parsing and was always single-generation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->decimal('ownership_share_pct', 7, 4)->nullable()->after('id_type');
            $table->string('deed_reference', 100)->nullable()->after('ownership_share_pct');
            // Nullable despite the default: existing rows and any write that omits this column
            // (the pre-existing single-owner path, untouched by §7) correctly land on 'current'.
            // A row this spec's parser explicitly could NOT classify (§7.9 case 4 — an
            // unparseable deed) must be able to store a genuine NULL here — it is neither
            // current nor past, and must never silently default into either.
            $table->string('ownership_status', 20)->nullable()->default('current')->after('deed_reference'); // 'current' | 'past' | null (unclassified)
        });
    }

    public function down(): void
    {
        Schema::table('tracked_property_owners', function (Blueprint $table) {
            $table->dropColumn(['ownership_share_pct', 'deed_reference', 'ownership_status']);
        });
    }
};

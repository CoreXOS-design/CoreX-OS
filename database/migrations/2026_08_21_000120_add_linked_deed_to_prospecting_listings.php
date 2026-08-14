<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC ↔ Deeds ↔ Contact loop (Johan 2026-08-14) — remember a manual deed link.
 *
 * When auto-match can't confidently tie a prospecting listing to a deeds capture (the P24
 * marketing address diverges from the deeds-office scheme address), the agent picks the deed
 * from the "Link a deed" modal. We record that choice on the listing so the deed owner is
 * surfaced automatically on every subsequent visit — the manual link teaches the matcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('linked_deed_tracked_property_id')->nullable()->after('tracked_property_id');
            $table->unsignedBigInteger('linked_deed_by_user_id')->nullable()->after('linked_deed_tracked_property_id');
            $table->timestamp('linked_deed_at')->nullable()->after('linked_deed_by_user_id');
            $table->index('linked_deed_tracked_property_id', 'prosp_listings_linked_deed_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prosp_listings_linked_deed_idx');
            $table->dropColumn(['linked_deed_tracked_property_id', 'linked_deed_by_user_id', 'linked_deed_at']);
        });
    }
};

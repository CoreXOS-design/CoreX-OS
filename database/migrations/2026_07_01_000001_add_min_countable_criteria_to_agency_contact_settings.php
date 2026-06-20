<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-71 (Buyer Pillar Build 1) — countable-buyer gate.
 *
 * Adds the agency-configurable minimum-criteria definition that decides when a
 * buyer's wishlist (ContactMatch) is "countable" toward match counts/lists.
 *
 * JSON array of required criteria groups. DEFAULT = ['any'] = a wishlist is
 * countable if it has AT LEAST ONE non-empty criteria field (the loosest bar —
 * only a completely empty wishlist is uncountable). An agency may later raise
 * the bar to e.g. ['area','price_band'] to require both.
 *
 * Nullable: a NULL column means "use the code default" (ContactMatch reads
 * AgencyContactSettings::minCountableFor() which falls back to ['any']), so
 * rows predating this migration resolve correctly without a backfill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->json('min_countable_criteria')->nullable()->after('buyer_lost_days');
        });
    }

    public function down(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('min_countable_criteria');
        });
    }
};

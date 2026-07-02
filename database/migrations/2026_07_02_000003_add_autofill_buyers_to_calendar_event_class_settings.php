<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-154 — attendee auto-fill by appointment type.
 *
 * SELLERS auto-fill for every property appointment (the feedback loop attaches
 * to the seller). BUYERS must NOT auto-fill for listing_presentation /
 * property_evaluation / meeting / other — only for buyer-driven appointments
 * (viewing). This flag makes "which event classes auto-fill buyers"
 * agency-configurable (per calendar_event_class_settings row) rather than
 * hardcoded. Dedicated + single-purpose (not overloading actor_role or the
 * activity-points buyer_facing flag), mirroring occupies_time.
 *
 * Default false; backfilled true for the buyer-driven classes (actor_role =
 * buyer_action) so behaviour is correct on existing rows without a reseed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->boolean('autofill_buyers')->default(false)->after('actor_role');
        });

        // Sensible default: buyer-driven appointment classes (viewing) auto-fill
        // buyers. Global template rows only — agency rows inherit false and can
        // be overridden in settings. By actor_role, not a hardcoded class list.
        DB::table('calendar_event_class_settings')
            ->whereNull('agency_id')
            ->where('actor_role', 'buyer_action')
            ->update(['autofill_buyers' => true]);
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('autofill_buyers');
        });
    }
};

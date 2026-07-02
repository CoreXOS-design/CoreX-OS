<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decouple the marker-vs-appointment distinction from actor_role.
 *
 * Until now, conflict/double-booking logic inferred "this event occupies a time
 * slot" from actor_role != 'neither' — but actor_role is really a buyer/seller
 * FEEDBACK field (buyer_action / seller_action / both / neither). Overloading it
 * as the appointment flag conflated two ideas. This adds an explicit
 * occupies_time boolean so actor_role goes back to meaning only "who acts".
 *
 * true  = appointment  (occupies a slot → counts for double-booking conflicts)
 * false = marker/reminder (mandate expiry, rent due, birthdays, SARS, tasks…)
 *
 * The backfill reproduces TODAY's behaviour EXACTLY for every existing row
 * (global + agency): the old rule excluded only actor_role='neither' from
 * conflicts, so everything else (including a NULL actor_role) was treated as an
 * appointment. So occupies_time=false iff actor_role='neither'; true otherwise.
 * Behaviour is therefore identical the instant this migrates, before any reseed.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            // Default false: a newly-added class is a marker unless explicitly
            // flagged an appointment — safer than defaulting to "blocks conflicts".
            $table->boolean('occupies_time')->default(false)->after('actor_role');
        });

        // Appointment = anything that WASN'T excluded from conflicts before,
        // i.e. actor_role is not 'neither' (NULL included → was an appointment).
        DB::table('calendar_event_class_settings')
            ->where('actor_role', 'neither')
            ->update(['occupies_time' => false]);
        DB::table('calendar_event_class_settings')
            ->where(function ($q) {
                $q->where('actor_role', '<>', 'neither')->orWhereNull('actor_role');
            })
            ->update(['occupies_time' => true]);
    }

    public function down(): void
    {
        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn('occupies_time');
        });
    }
};

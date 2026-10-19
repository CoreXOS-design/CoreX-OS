<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the "Work accepted — no tenant response" notification toggle (audit 2026-10-18).
 *
 * Its only producer was the silence-acceptance step of the tenant completion check, removed by Johan's T1 ruling
 * (9 Oct 2026, commit 5f3533f43: "agent can close on word and evidence from the crew or contractor" — the tenant's check
 * never blocks closing, there is no "accepted by silence" any more). Nothing can fire the event, so a toggle offering it is
 * a switch that does nothing (NotificationCatalogueHasProducersTest, AT-235 C7). Soft-deleted, so a user's saved preference
 * survives if a producer ever returns; down() restores it.
 */
return new class extends Migration
{
    private const KEY = 'rental_work_order.completion_accepted';

    public function up(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')->where('key', self::KEY)->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('notification_event_types')) {
            return;
        }

        DB::table('notification_event_types')->where('key', self::KEY)->whereNotNull('deleted_at')
            ->update(['deleted_at' => null, 'updated_at' => now()]);
    }
};

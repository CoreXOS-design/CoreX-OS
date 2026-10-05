<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — the agency can tick its OWN steps from the public timeline link.
 * Per step, off unless CoreX turns it on (a step that is CoreX's work stays locked).
 * Seeded ON for the two default steps the agency itself does; existing timelines
 * inherit the flag from the default each step was copied from.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['agency_timeline_default_items', 'agency_timeline_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('agency_can_complete')->default(false)->after('is_public');
            });
        }

        DB::table('agency_timeline_default_items')
            ->whereIn('title', ['Take-on questionnaire completed', 'CRM data exported and sent to us'])
            ->update(['agency_can_complete' => true]);

        DB::statement('UPDATE agency_timeline_items i JOIN agency_timeline_default_items d ON d.id = i.source_default_id SET i.agency_can_complete = d.agency_can_complete');
    }

    public function down(): void
    {
        foreach (['agency_timeline_items', 'agency_timeline_default_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('agency_can_complete');
            });
        }
    }
};

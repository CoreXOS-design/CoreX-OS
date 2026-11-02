<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, belt step A4 (road-to-sold.md 23.8 / 23.17): the appointment and the presentation carry the property.
 *   calendar_events  tracked_property_id, rts_prospect_id (nullable, indexed)
 *   presentations    rts_prospect_id (nullable, indexed), calendar_event_id (nullable, indexed); tracked_property_id / seller_contact_id already exist and start being written
 * Additive; a re-run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $t) {
            if (! Schema::hasColumn('calendar_events', 'tracked_property_id')) {
                $t->unsignedBigInteger('tracked_property_id')->nullable()->index('calendar_events_tracked_property_idx');
            }
            if (! Schema::hasColumn('calendar_events', 'rts_prospect_id')) {
                $t->unsignedBigInteger('rts_prospect_id')->nullable()->index('calendar_events_rts_prospect_idx');
            }
        });
        Schema::table('presentations', function (Blueprint $t) {
            if (! Schema::hasColumn('presentations', 'rts_prospect_id')) {
                $t->unsignedBigInteger('rts_prospect_id')->nullable()->index('presentations_rts_prospect_idx');
            }
            if (! Schema::hasColumn('presentations', 'calendar_event_id')) {
                $t->unsignedBigInteger('calendar_event_id')->nullable()->index('presentations_calendar_event_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $t) {
            foreach (['tracked_property_id' => 'calendar_events_tracked_property_idx', 'rts_prospect_id' => 'calendar_events_rts_prospect_idx'] as $c => $i) {
                if (Schema::hasColumn('calendar_events', $c)) {
                    $t->dropIndex($i);
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('presentations', function (Blueprint $t) {
            foreach (['rts_prospect_id' => 'presentations_rts_prospect_idx', 'calendar_event_id' => 'presentations_calendar_event_idx'] as $c => $i) {
                if (Schema::hasColumn('presentations', $c)) {
                    $t->dropIndex($i);
                    $t->dropColumn($c);
                }
            }
        });
    }
};

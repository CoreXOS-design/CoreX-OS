<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, belt step A3 (road-to-sold.md 23.7 / 23.15): rts_contact_outcomes.channel (phone / whatsapp / email / in_person; NULL = recorded
 * before v4) and rts_settings.default_callback_days (the call-back date the Market Intelligence feedback adapter uses). Additive; a re-run changes nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rts_contact_outcomes', 'channel')) {
            Schema::table('rts_contact_outcomes', function (Blueprint $t) {
                $t->string('channel', 20)->nullable()->after('kind')->index('rts_contact_outcomes_channel_idx');
            });
        }
        if (! Schema::hasColumn('rts_settings', 'default_callback_days')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->unsignedSmallInteger('default_callback_days')->default(3);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rts_contact_outcomes', 'channel')) {
            Schema::table('rts_contact_outcomes', function (Blueprint $t) {
                $t->dropIndex('rts_contact_outcomes_channel_idx');
                $t->dropColumn('channel');
            });
        }
        if (Schema::hasColumn('rts_settings', 'default_callback_days')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->dropColumn('default_callback_days');
            });
        }
    }
};

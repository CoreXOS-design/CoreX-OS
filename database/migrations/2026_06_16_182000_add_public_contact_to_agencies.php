<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-46 — agency public contact for seller-outreach messages.
 *
 * The {agency_contact} merge field needs a configurable, agency-owned
 * "reply to / contact us on" value, distinct from the general company phone.
 * Backfills from agencies.phone where present so existing agencies render a
 * sensible default immediately; editable under Admin → Company Settings.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('public_contact')->nullable()->after('ppra_number');
        });

        // Sensible default = the office phone, where one exists. Configurable after.
        DB::table('agencies')
            ->whereNull('public_contact')
            ->whereNotNull('phone')
            ->update(['public_contact' => DB::raw('phone')]);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('public_contact');
        });
    }
};

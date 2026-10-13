<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - "portals we capture from": an agency that does not use Private Property (or Property24) must not
 * be told every suburb "needs a refresh" for it. JSON list of 'p24' / 'pp'; NULL = both (the default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->json('refresh_portals')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->dropColumn('refresh_portals');
        });
    }
};

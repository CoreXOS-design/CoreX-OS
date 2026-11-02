<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Road to Sold v4, belt step A2 (road-to-sold.md 23.6 / 23.15): rts_settings.owner_requires_deed, default on. Additive; a re-run changes nothing. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rts_settings', 'owner_requires_deed')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->boolean('owner_requires_deed')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rts_settings', 'owner_requires_deed')) {
            Schema::table('rts_settings', function (Blueprint $t) {
                $t->dropColumn('owner_requires_deed');
            });
        }
    }
};

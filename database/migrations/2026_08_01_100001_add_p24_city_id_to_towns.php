<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-246 corrected region model (Johan-final) — the prospecting "town" IS the P24
 * town, not a municipality. Three layers, each from its natural source:
 *   (1) suburb→town  = P24 (p24_suburbs.p24_city_id → p24_cities); read-only.
 *   (2) town→region  = MDB municipality via spatial lookup of the TOWN (overridable).
 *   (3) region→alias = agency alias (region_aliases).
 *
 * A town carries its P24 city id so the MIC filter can resolve
 * suburb → P24 town → municipality → alias at query time, and the setup screen can
 * list P24 towns with their suburbs read-only. Yesterday's build wrongly rebuilt
 * towns AS the containing municipality (suburb-level PIP), which put Albersville
 * (a Port Shepstone suburb) under "Umzumbe". This links the town to its P24 city.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            $table->unsignedInteger('p24_city_id')->nullable()->after('slug')->index();
        });
    }

    public function down(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            $table->dropColumn('p24_city_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-239 region model (Johan-final 2026-07-13): a region record is the MDB
 * MUNICIPALITY (canonical, immutable, nationally consistent) carrying an
 * agency-editable ALIAS. The alias displays everywhere (MIC filter, tiles,
 * reports); an empty alias falls back to the municipal name. `towns.region`
 * holds the canonical municipality; this table holds the per-agency alias +
 * the pre-filled P24-alias suggestion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('region_aliases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('municipality', 120);          // canonical MDB name (Ray Nkonyeni, KwaDukuza…)
            $table->string('alias', 120)->nullable();      // agency display override ("Hibiscus Coast")
            $table->string('alias_suggestion', 120)->nullable(); // pre-filled P24-alias suggestion, never auto-trusted
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'municipality'], 'region_aliases_agency_munic_unique');
            $table->index(['agency_id', 'display_order'], 'region_aliases_agency_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('region_aliases');
    }
};

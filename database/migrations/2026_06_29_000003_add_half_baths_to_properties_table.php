<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Half bathrooms on the property upload wizard.
 *
 * Adds a first-class `half_baths` scalar alongside `baths`. A half bathroom is a
 * guest toilet / cloakroom (toilet + basin, no bath or shower). Previously the
 * only way to record half-bath precision was the show-page rich spaces editor,
 * which stores a fractional `count` in `spaces_json` and floors the legacy
 * `baths` column. The simple upload wizard had no half-bath input at all.
 *
 * Shape mirrors the existing `baths` / `garages` counters (tinyint, default 0).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->unsignedTinyInteger('half_baths')->default(0)->after('baths');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->dropColumn('half_baths');
        });
    }
};

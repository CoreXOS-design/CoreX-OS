<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-239 — kill the blanket p24_suburbs.region default.
 *
 * The original create_p24_suburbs_table migration (2026-02) defaulted `region`
 * to the literal 'kzn-south-coast', so every one of ~26,862 imported P24 suburbs
 * carried that label whether or not it was true — a blanket that surfaced in the
 * P24 suburb-mapping UI. Region now derives from the MDB municipality model
 * (point-in-polygon on suburb coordinates), NULL where unknown. Default the
 * column to NULL so future P24 arrivals join the unmapped queue instead of
 * inheriting a false region. Existing rows are corrected by
 * prospecting:reconcile-p24-suburb-regions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('p24_suburbs', function (Blueprint $table) {
            $table->string('region')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('p24_suburbs', function (Blueprint $table) {
            $table->string('region')->default('kzn-south-coast')->change();
        });
    }
};

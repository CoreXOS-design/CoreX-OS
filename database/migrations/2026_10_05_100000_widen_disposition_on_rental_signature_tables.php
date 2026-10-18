<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conductor brief 2026-09-29 — wet-ink signing for rental Inventory, plus
 * closing the same gaps on rental Inspections. Both signature tables gain a
 * new `disposition` value, 'awaiting_wet_ink' (17 chars) — the "paper sent,
 * not yet returned" tracking state, distinct from 'wet_ink' (the scan has
 * actually arrived). `disposition` was `string(10)` on both tables (sized
 * for 'signed'/'refused'/'wet_ink'), too short for the new value. Widened
 * to `string(20)` on both — no data change, existing values are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->string('disposition', 20)->default('signed')->change();
        });
        Schema::table('rental_inventory_signatures', function (Blueprint $table) {
            $table->string('disposition', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_signatures', function (Blueprint $table) {
            $table->string('disposition', 10)->default('signed')->change();
        });
        Schema::table('rental_inventory_signatures', function (Blueprint $table) {
            $table->string('disposition', 10)->change();
        });
    }
};

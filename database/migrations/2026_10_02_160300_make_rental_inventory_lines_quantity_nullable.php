<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §4c — Johan, 2026-10-02: the qty field on a
 * new inventory line pre-filled with 1 forces a delete-first before typing
 * 4, exactly on the field the rapid-entry feature exists to speed up. Fix
 * is a blank qty field client-side; this migration is what makes "blank,
 * left blank, saved" possible server-side. "lets get that to null and it
 * will work perfect."
 *
 * Additive/relaxing only — no existing row's `quantity` value is touched.
 * `->default(1)` is left as-is (harmless: the app now always sends an
 * explicit `quantity` value, null or a number, so the column default never
 * actually fires) — only nullability changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable()->default(1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_lines', function (Blueprint $table) {
            $table->unsignedInteger('quantity')->nullable(false)->default(1)->change();
        });
    }
};

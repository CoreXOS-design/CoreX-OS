<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05 (3): whether
 * `lease_start_date` (reused as "available from" for both an ordinary rental
 * listing and the notice-readvertise path, §15 GATE 2) is pushed to
 * Property24/Private Property at all. Default ON — matches today's existing,
 * always-on behaviour for every property that already has this reused
 * column; an agency only sees a change if it explicitly turns this off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('show_available_from_on_portals')->default(true)->after('lease_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('show_available_from_on_portals');
        });
    }
};

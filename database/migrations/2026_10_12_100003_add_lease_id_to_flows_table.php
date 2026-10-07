<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §15.10 M3 (Build L1 — foundation) — the e-sign flow a lease launched points
 * back at that lease. Written by LeaseSigningLauncher when it creates the flow; replaces finding the
 * lease again by property address + tenant name when the document completes. No FK across the
 * Docuperfect module boundary (convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('flows', 'lease_id')) {
            return;
        }

        Schema::table('flows', function (Blueprint $table) {
            $table->unsignedBigInteger('lease_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('flows', function (Blueprint $table) {
            $table->dropIndex(['lease_id']);
            $table->dropColumn('lease_id');
        });
    }
};

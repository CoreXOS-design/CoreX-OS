<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ROI report user selector (#1) — persistent per-user flag so an admin can
 * exclude IT/office accounts (Johan, Andre, Ronel) from the Agency
 * Performance & ROI report once, without re-ticking a filter every visit.
 * Default true: every existing and future user appears unless explicitly
 * switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('show_in_performance_reports')->default(true)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('show_in_performance_reports');
        });
    }
};

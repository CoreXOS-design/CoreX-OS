<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase E — item (j), .ai/specs/ppra-inspection-pack.md §6.7.
 * 1-12. Default 3 = March, the common SA small-business FY start — a
 * sensible neutral default, never assumed correct for a given agency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedTinyInteger('financial_year_start_month')->default(3)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('financial_year_start_month');
        });
    }
};

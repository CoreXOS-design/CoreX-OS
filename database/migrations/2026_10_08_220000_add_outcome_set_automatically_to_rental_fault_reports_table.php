<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 8 Oct 2026: completing the work order resolves its fault by itself (outcome Repaired). The outcome stays EDITABLE afterwards, but only
 * while it is the automatic one - this flag marks it; a hand-recorded outcome clears it and closes the report for good, as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->boolean('outcome_set_automatically')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropColumn('outcome_set_automatically');
        });
    }
};

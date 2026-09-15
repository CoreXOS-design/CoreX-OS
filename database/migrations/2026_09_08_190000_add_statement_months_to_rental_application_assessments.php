<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 Round 11 — Johan: "I keep capturing expenses and income and by
 * what do I divide once ready to get a monthly avg? we have to ask the
 * nr of months the bank statement is for." A bank statement export
 * covers a fixed number of months; the totals captured from it are a
 * LUMP SUM over that period, not a monthly figure, until divided by it.
 *
 * Deliberately just the input + a displayed monthly-average calculation
 * in this round — NOT yet wired into qualifyingResult()'s gross_income.
 * Johan's own reading (to be confirmed): the monthly average should
 * replace the manually-typed income in the actual decision. Confirm
 * before wiring — see spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->unsignedTinyInteger('statement_months')->nullable()->after('rental_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->dropColumn('statement_months');
        });
    }
};

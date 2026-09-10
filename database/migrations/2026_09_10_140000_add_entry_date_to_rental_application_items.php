<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 "Dates on entries" (Johan, 2026-09-10) — every income/expense line
 * the agent (or an authoriser, via the same strike-and-replace endpoint)
 * captures off a bank statement gets its own date, so the record shows WHEN
 * that deposit/debit happened, not just how much. Nullable — existing rows
 * (and any newly-typed row where the agent hasn't picked a date yet) keep
 * working exactly as before; this is capture only, no scoring change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_income_items', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('amount');
        });

        Schema::table('rental_application_expense_items', function (Blueprint $table) {
            $table->date('entry_date')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_income_items', function (Blueprint $table) {
            $table->dropColumn('entry_date');
        });

        Schema::table('rental_application_expense_items', function (Blueprint $table) {
            $table->dropColumn('entry_date');
        });
    }
};

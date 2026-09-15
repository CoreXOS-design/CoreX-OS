<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-392 "Dates on entries" (Johan, 2026-09-10) — "Months covered" stops
 * being a typed number and becomes a from/to date range; the month count is
 * CALCULATED from the range (RentalApplicationAssessment::calculateStatementMonths()),
 * never typed directly. These two columns are the new source of the number
 * for any assessment that uses the date pickers from now on.
 *
 * NO BACKFILL. Existing assessments already carry a `statement_months`
 * value with no date range behind it — Johan, explicit: "existing records
 * keep whatever month count they hold, nothing recalculates retrospectively
 * without a date range to derive it from." Those rows' `statement_months`
 * is left exactly as it is; these two columns simply start out null for
 * them, and stay null until an agent opens that application again and picks
 * dates — at which point the derived figure takes over going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->date('statement_period_from')->nullable()->after('statement_months');
            $table->date('statement_period_to')->nullable()->after('statement_period_from');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_assessments', function (Blueprint $table) {
            $table->dropColumn(['statement_period_from', 'statement_period_to']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan's decision, 2026-09-14: a struck-out line is EXCLUDED from the
 * affordability totals (income/expense, and therefore months, monthly
 * income, net monthly) — not discounted, not merely annotated. It stays
 * VISIBLE with its date, description and amount; it is struck, not
 * hidden, and can be un-struck back into the totals.
 *
 * This is a first-class strike concept on the CURRENT capture-ledger
 * table (rental_application_document_marks), not a revival of the old,
 * pre-2026-09-11 RentalApplicationIncomeItem/ExpenseItem struck_out_at/
 * struck_out_by_user_id columns — those tables are frozen (see the
 * 2026-09-11 backfill migration's own docblock) and this is a genuinely
 * new capability on the table that replaced them. Column names/shape
 * deliberately mirror the old ones for anyone who worked with that
 * feature before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->timestamp('struck_out_at')->nullable()->after('entry_amount');
            $table->foreignId('struck_out_by_user_id')->nullable()->after('struck_out_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('struck_out_by_user_id');
            $table->dropColumn('struck_out_at');
        });
    }
};

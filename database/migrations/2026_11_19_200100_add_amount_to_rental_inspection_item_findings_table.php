<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rental application/lease audit fixes (A24, 2026-10-19) - Johan's ruling: money stays with reOS, CoreX only RECORDS the figure. A deposit
 * comparison finding may carry the amount the agent has in mind for that item (rand, optional). Nothing here turns it into a deduction,
 * an invoice or a payout.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_item_findings', function (Blueprint $table) {
            $table->decimal('amount', 12, 2)->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_item_findings', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};

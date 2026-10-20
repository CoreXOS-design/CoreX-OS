<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan's ruling, 20 Oct 2026: money is NOT tracked in CoreX - the accounting system handles it. "Record deduction amounts" is an agency
 * setting, default OFF (null reads as off). Off: a charge to the tenant needs no amount, no amount field is shown, and the lease's
 * "Move-out charges" card and page show the list of charged items only (no totals, no R figures, no deposit held). On: the audit-fix behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('record_deduction_amounts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('record_deduction_amounts');
        });
    }
};

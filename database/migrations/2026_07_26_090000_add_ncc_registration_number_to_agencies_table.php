<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-234 — NCC (National Consumer Commission) registration number on the agency.
 * Agency-scoped stationery field, editable in Company Settings, rendered anywhere
 * the company reg/VAT numbers already render (letterhead, proforma PDF, payslip PDF).
 * Nullable — hidden gracefully until an agency captures it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('ncc_registration_number')->nullable()->after('ppra_number');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('ncc_registration_number');
        });
    }
};

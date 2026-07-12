<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A real VAT-registered flag on the agency. Proforma VAT rendering follows this
 * setting (spec §6). No such flag existed before — the only signal was a non-empty
 * `vat_no` string, which we use to backfill a sensible default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('vat_registered')->default(false)->after('vat_no');
        });

        // Backfill: an agency with a non-empty VAT number is treated as registered.
        DB::table('agencies')
            ->whereNotNull('vat_no')
            ->whereRaw("TRIM(vat_no) <> ''")
            ->update(['vat_registered' => true]);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('vat_registered');
        });
    }
};

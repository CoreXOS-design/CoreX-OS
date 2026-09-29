<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase F — .ai/specs/ppra-inspection-pack.md §4.6a.
 * Three separate settings (not one shared N) — items k/l/m draw from
 * different-sized populations per agency (§4.6a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'ppra_pack_sales_sample_size')) {
                $table->unsignedTinyInteger('ppra_pack_sales_sample_size')->default(5)->after('financial_year_start_month');
            }
            if (! Schema::hasColumn('agencies', 'ppra_pack_rental_sample_size')) {
                $table->unsignedTinyInteger('ppra_pack_rental_sample_size')->default(5)->after('ppra_pack_sales_sample_size');
            }
            if (! Schema::hasColumn('agencies', 'ppra_pack_mandate_sample_size')) {
                $table->unsignedTinyInteger('ppra_pack_mandate_sample_size')->default(5)->after('ppra_pack_rental_sample_size');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            foreach (['ppra_pack_sales_sample_size', 'ppra_pack_rental_sample_size', 'ppra_pack_mandate_sample_size'] as $column) {
                if (Schema::hasColumn('agencies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};

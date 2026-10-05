<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase I — .ai/specs/ppra-inspection-pack.md §6.8e/§11.
 * Two settings: the mandate/MDF/FICA register's red-threshold percentage
 * (item m's checklist status), and the max-files-per-ZIP cap shared by the
 * register's bulk export and any other per-list ZIP this spec introduces.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('agencies', 'ppra_mandate_register_red_threshold_pct')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->unsignedTinyInteger('ppra_mandate_register_red_threshold_pct')->default(10)->after('ppra_pack_mandate_sample_size');
            });
        }

        if (! Schema::hasColumn('agencies', 'ppra_zip_max_files')) {
            Schema::table('agencies', function (Blueprint $table) {
                $table->unsignedSmallInteger('ppra_zip_max_files')->default(200)->after('ppra_mandate_register_red_threshold_pct');
            });
        }
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['ppra_mandate_register_red_threshold_pct', 'ppra_zip_max_files']);
        });
    }
};

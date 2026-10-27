<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold admin settings (Johan, 27 Oct 2026; road-to-sold.md section 21).
 *
 *   capture_back_date_working_days  how far back a captured task may be dated, in WORKING days (weekends and the agency's
 *                                   public holidays do not count). Default 5. Replaces capture_back_date_hours (48 hours).
 *   scan_page_size                  leaves the agency settings: it is a config value now (config/road-to-sold.php), not
 *                                   something an agency chooses.
 *
 * Every agency starts on the new default; the old hour figures are not converted (hours and working days do not map).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'capture_back_date_working_days')) {
                $table->unsignedSmallInteger('capture_back_date_working_days')->default(5);
            }
        });
        Schema::table('rts_settings', function (Blueprint $table) {
            foreach (['capture_back_date_hours', 'scan_page_size'] as $column) {
                if (Schema::hasColumn('rts_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'capture_back_date_hours')) {
                $table->unsignedSmallInteger('capture_back_date_hours')->default(48);
            }
            if (! Schema::hasColumn('rts_settings', 'scan_page_size')) {
                $table->unsignedSmallInteger('scan_page_size')->default(200);
            }
        });
        Schema::table('rts_settings', function (Blueprint $table) {
            if (Schema::hasColumn('rts_settings', 'capture_back_date_working_days')) {
                $table->dropColumn('capture_back_date_working_days');
            }
        });
    }
};

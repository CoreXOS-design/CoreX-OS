<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SS presentations — agency toggle for the dedicated "Complex sales" section.
 *
 * Sectional / complex sales (CMA source tag `vicinity_sales_sectional`) are
 * split into their own comp group and rendered as a separate "Recent sales in
 * {complex}" section whenever such comps exist in the pool. Default behaviour
 * is data-presence driven, but per the configurability rule (no hardcoded
 * show/hide) an agency can suppress the section entirely with this flag.
 *
 * Default TRUE — agencies that work sectional stock want it on out of the box.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'ss_show_complex_section')) {
                $table->boolean('ss_show_complex_section')
                    ->default(true)
                    ->after('presentations_default_radius_m')
                    ->comment('Show the dedicated sectional/complex sales section on presentations when such comps exist.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'ss_show_complex_section')) {
                $table->dropColumn('ss_show_complex_section');
            }
        });
    }
};

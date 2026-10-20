<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §17.38 — the agency's default markup on a NEW parts & labour catalogue item:
 * the unit (rand | percent) and the amount. NULL = the built-in neutral default (percent, 0), resolved at read time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_work_order_settings', 'catalogue_default_markup_type')) {
                $table->string('catalogue_default_markup_type', 10)->nullable();
            }
            if (! Schema::hasColumn('rental_work_order_settings', 'catalogue_default_markup_value')) {
                $table->decimal('catalogue_default_markup_value', 12, 4)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_work_order_settings', function (Blueprint $table) {
            foreach (['catalogue_default_markup_type', 'catalogue_default_markup_value'] as $col) {
                if (Schema::hasColumn('rental_work_order_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

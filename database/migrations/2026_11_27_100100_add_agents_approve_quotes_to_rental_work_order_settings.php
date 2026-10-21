<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-494 (Johan's ruling) - "Agents can approve quotes and send them to the owner". A nullable column, read-time default ON
 * (null = on), so no existing agency changes behaviour and nothing is written on read. See rentals-faults-work-orders.md section 28.5.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('rental_work_order_settings', 'agents_approve_quotes')) {
            Schema::table('rental_work_order_settings', function (Blueprint $table) {
                $table->boolean('agents_approve_quotes')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_work_order_settings', 'agents_approve_quotes')) {
            Schema::table('rental_work_order_settings', function (Blueprint $table) {
                $table->dropColumn('agents_approve_quotes');
            });
        }
    }
};

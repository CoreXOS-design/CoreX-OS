<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md section 28.3 - "Same call: the owner also approved the cost up to R___". Stored on the owner's go-ahead record
 * (Decision 1) it authorises nothing by itself; it is the figure the work order offers back as one "Use it" press once a quote at or below it is in.
 * Additive, nullable, idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_authorisations') && ! Schema::hasColumn('rental_authorisations', 'also_cost_up_to')) {
            Schema::table('rental_authorisations', function (Blueprint $table) {
                $table->decimal('also_cost_up_to', 12, 2)->nullable()->after('callout_fee');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_authorisations', 'also_cost_up_to')) {
            Schema::table('rental_authorisations', fn (Blueprint $t) => $t->dropColumn('also_cost_up_to'));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3a/§3.4a — the two real foreign-key
 * constraints deliberately deferred until this table existed:
 *
 *   rental_fault_reports.rental_work_order_id  -> rental_work_orders.id
 *     (Stage 1's own migration comment: "NO foreign-key constraint here —
 *     rental_work_orders (Stage 4) does not exist yet at this migration.")
 *   rental_approvals.rental_work_order_id      -> rental_work_orders.id
 *     (Stage 2's own migration comment, identical reasoning.)
 *
 * Both columns already exist and are already populated with nothing but
 * NULLs (Stage 4 is the first release that can ever set them), so adding
 * the constraint now is safe — there is no existing data to violate it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->foreign('rental_work_order_id', 'rfr_work_order_fk')
                ->references('id')->on('rental_work_orders')->nullOnDelete();
        });

        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->foreign('rental_work_order_id', 'rental_approvals_work_order_fk')
                ->references('id')->on('rental_work_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropForeign('rfr_work_order_fk');
        });

        Schema::table('rental_approvals', function (Blueprint $table) {
            $table->dropForeign('rental_approvals_work_order_fk');
        });
    }
};

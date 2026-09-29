<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.4b, Johan's ruling 2026-09-29 — "looking
 * at it on the lease screen now. per property, populated to the leases
 * screen." The property is the single editable source of truth for the
 * no-approval spend threshold override; the lease screen reads through to
 * this column rather than carrying its own value (see the companion
 * migration dropping leases.rental_no_approval_spend_threshold). Sits with
 * the other rental-tab money fields (deposit_amount, admin_fee).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->decimal('rental_no_approval_spend_threshold', 10, 2)->nullable()->after('admin_fee');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_no_approval_spend_threshold');
        });
    }
};

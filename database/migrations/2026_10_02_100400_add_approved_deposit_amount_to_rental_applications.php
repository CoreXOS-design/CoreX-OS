<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-22 (property 4283 / rental application 290) — the tenant-
 * linking screen's rent/deposit precedence is "the approved application
 * amount wins; the property is the fallback." approved_rental_amount
 * already exists (2026_09_08_150000); this is the same idea for deposit,
 * captured optionally alongside it on the SAME authoriser approve() action
 * — RentalApplicationAuthorisationController::approve(). Nullable: most
 * approvals will never set one, and the precedence chain falls back to the
 * property's own deposit_amount exactly as it does for rent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->decimal('approved_deposit_amount', 12, 2)->nullable()->after('approved_rental_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropColumn('approved_deposit_amount');
        });
    }
};

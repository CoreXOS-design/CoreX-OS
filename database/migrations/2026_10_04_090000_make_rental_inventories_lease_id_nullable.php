<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §0a/§15 — Johan's standing ruling (2026-09-22,
 * restated 2026-09-28): "inventory was specifically specced not only for
 * rentals. sales will also need it... it should be on properties." The
 * original migration hard-required `lease_id` (a rental-tenancy concept a
 * sale property never has), which is the actual root cause of a sale
 * property's Inventory tab refusing to start at all
 * (RentalInventory::resolveOrStartFor() returned null whenever no active
 * Lease existed). An inventory now attaches to the property alone when there
 * is no lease to also attach to — `lease_id` stays required and populated
 * for every rental inventory exactly as before; this only widens the column.
 *
 * Drop the FK first (so the column can change), relax to nullable, re-add
 * the FK with the same cascadeOnDelete this table already had — unchanged
 * for every existing (lease-attached) row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->dropForeign(['lease_id']);
        });

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('lease_id')->nullable()->change();
        });

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->foreign('lease_id')
                ->references('id')->on('leases')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        $nullCount = \DB::table('rental_inventories')->whereNull('lease_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Cannot roll back: {$nullCount} rental_inventories row(s) have NULL lease_id "
                . '(property-level inventories with no lease). Delete or backfill them before '
                . 're-attempting this rollback.'
            );
        }

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->dropForeign(['lease_id']);
        });

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('lease_id')->nullable(false)->change();
        });

        Schema::table('rental_inventories', function (Blueprint $table) {
            $table->foreign('lease_id')
                ->references('id')->on('leases')
                ->cascadeOnDelete();
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix — Rental module had zero multi-tenant isolation:
 * `rental_properties` never carried an `agency_id` column, so
 * RentalPropertyController/RentalDivisionController/ESignWizardController's
 * searchProperties() all returned and allowed mutation of every agency's
 * rental stock (including landlord PII) to any user with `view_rentals`.
 *
 * Adds the column nullable + FK + index, mirroring the presentations
 * precedent (2026_05_21_120011). Backfill happens in the next migration;
 * NOT NULL is applied in the migration after that, guarded on zero
 * remaining NULLs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('rental_properties', 'agency_id')) {
            Schema::table('rental_properties', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_properties' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('rental_properties', function (Blueprint $table) {
                $table->foreign('agency_id')
                      ->references('id')->on('agencies')
                      ->nullOnDelete();
            });
        }

        $hasIndex = !empty(DB::select(
            "SHOW INDEX FROM rental_properties WHERE Key_name = 'idx_rental_properties_agency_id'"
        ));
        if (!$hasIndex) {
            Schema::table('rental_properties', function (Blueprint $table) {
                $table->index('agency_id', 'idx_rental_properties_agency_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_properties', 'agency_id')) {
            Schema::table('rental_properties', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_rental_properties_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};

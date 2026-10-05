<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastel-style enhancement, 2026-10-05 — adds the new FK columns alongside
 * the old `type`/`unit` strings (not yet dropped — the backfill migration
 * right after this one needs both old and new columns present at once to
 * migrate existing rows). default_custom_vat_rate mirrors
 * rental_job_card_lines.custom_vat_rate: only meaningful when the item's
 * default VAT type is rate_mode=custom_per_line, so the item's own
 * excl/incl price display has a rate to compute with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->foreignId('rental_catalogue_item_type_id')->nullable()->after('type')
                ->constrained('rental_catalogue_item_types', indexName: 'rci_type_fk')->nullOnDelete();
            $table->foreignId('rental_catalogue_unit_id')->nullable()->after('unit')
                ->constrained('rental_catalogue_units', indexName: 'rci_unit_fk')->nullOnDelete();
            $table->decimal('default_custom_vat_rate', 5, 2)->nullable()->after('default_rental_vat_type_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_catalogue_item_type_id');
            $table->dropConstrainedForeignId('rental_catalogue_unit_id');
            $table->dropColumn('default_custom_vat_rate');
        });
    }
};

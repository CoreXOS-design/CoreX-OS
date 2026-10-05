<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A catalogue item's default VAT type — a job-card line picking this item inherits it, still editable per line. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            // Laravel's constrained() guesses the table from the column name —
            // "default_rental_vat_type_id" would wrongly guess
            // "default_rental_vat_types"; the real table is rental_vat_types.
            $table->foreignId('default_rental_vat_type_id')->nullable()->after('default_price')
                ->constrained('rental_vat_types', indexName: 'rci_default_vat_type_fk')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_rental_vat_type_id');
        });
    }
};

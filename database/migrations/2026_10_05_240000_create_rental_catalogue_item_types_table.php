<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue item "type" made
 * agency-configurable (add/rename/reorder/archive), seeded per agency with
 * the current Labour/Part values as defaults. See RentalCatalogueItemType.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_catalogue_item_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('kind', 10);
            // labour | part — the fixed classification every type maps onto,
            // so renaming/adding types never breaks labour-hours/parts-used
            // reporting (RentalReportService::jobCards()).
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active'], 'rcit_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_catalogue_item_types');
    }
};

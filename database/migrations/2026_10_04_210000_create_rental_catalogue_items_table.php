<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-442 — the agency's own parts & labour catalogue consumed by internal
 * job cards (rental-work-orders.md §14). Full CRUD, per-agency, soft-delete
 * only (non-negotiable #1) — same shape as AgencyServiceType
 * (app/Models/DealV2/AgencyServiceType.php), a new table because an item
 * here carries a unit and an optional default price, neither of which that
 * sibling catalogue needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_catalogue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            $table->string('type', 10);
            // labour | part — RentalCatalogueItem::TYPE_*
            $table->string('name', 191);
            $table->string('unit', 30);
            // each | hour | metre | ... — agency free text, not a fixed list.
            $table->decimal('default_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'type', 'is_active'], 'rci_agency_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_catalogue_items');
    }
};

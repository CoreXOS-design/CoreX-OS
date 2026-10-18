<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastel-style enhancement, 2026-10-05 — a catalogue item's "unit" made an
 * agency-configurable list instead of free text, seeded per agency with
 * sensible defaults (each/dozen/box/pack/metre/m²/litre/kg/hour/day/
 * call-out). See RentalCatalogueUnit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_catalogue_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            $table->string('name', 50);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active'], 'rcu_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_catalogue_units');
    }
};

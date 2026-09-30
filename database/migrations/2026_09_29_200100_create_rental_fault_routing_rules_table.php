<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.2 — per-category/per-urgency
 * overrides WITHIN a profile ("plumbing emergencies go to supplier X,
 * electrical to supplier Y," Johan's own example). Several per profile,
 * most-specific-wins (§13.3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_fault_routing_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('rental_fault_routing_profile_id')->constrained('rental_fault_routing_profiles')->cascadeOnDelete();

            $table->string('category', 100)->nullable();
            $table->string('urgency', 20)->nullable();
            $table->string('route', 20);
            $table->foreignId('agency_service_provider_id')->nullable()->constrained('agency_service_providers')->nullOnDelete();
            $table->decimal('spend_limit', 10, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['rental_fault_routing_profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_fault_routing_rules');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §2.2. No agency_id of its own — scoped
 * via run_id -> run.agency_id, exactly like p24_import_rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_take_on_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('rental_take_on_import_runs')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('payload_json')->nullable();
            $table->string('property_match_action', 10)->nullable(); // create | match
            $table->unsignedBigInteger('property_match_tracked_id')->nullable();
            $table->string('property_match_label')->nullable();
            $table->json('landlord_match_json')->nullable();
            $table->json('tenant_match_json')->nullable();
            $table->string('lease_completeness', 10)->nullable(); // complete | draft
            $table->json('errors_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->string('status', 15)->default('pending'); // pending|included|excluded|confirmed|error
            $table->foreignId('target_property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('target_lease_id')->nullable()->constrained('leases')->nullOnDelete();
            $table->json('target_landlord_contact_ids_json')->nullable();
            $table->json('target_tenant_contact_ids_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_take_on_import_rows');
    }
};

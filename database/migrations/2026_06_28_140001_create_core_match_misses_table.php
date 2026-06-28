<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-XX Viewing Pack — Step 3: core_match_miss CAPTURE table.
 *
 * Written silently when an agent adds a property to a Viewing Pack that is NOT
 * a current Core Match for that buyer (spec §3). It records a point-in-time
 * snapshot of BOTH sides — the buyer's criteria and the property's attributes
 * AS AT the moment of the add — so the later (separate) Core Match Intelligence
 * ticket can diagnose why the canonical engine missed it.
 *
 * THIS BUILD IS CAPTURE ONLY. No diagnostic logic, no suggestion engine, no
 * review surface — those are out of scope. Just the row.
 *
 * Tenant-owned (agency_id + AgencyScope), soft-deleted (no hard deletes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('core_match_misses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();   // the buyer
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewing_pack_id')->nullable()->constrained('viewing_packs')->nullOnDelete();

            // Point-in-time copies — immutable history, never re-read live data.
            $table->json('buyer_criteria_snapshot')->nullable();
            $table->json('property_attributes_snapshot')->nullable();

            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'contact_id']);
            $table->index(['viewing_pack_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_match_misses');
    }
};

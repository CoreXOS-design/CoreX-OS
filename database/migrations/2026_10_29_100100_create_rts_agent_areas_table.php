<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — Road to Sold: which suburbs each agent works (.ai/specs/road-to-sold.md §3.2).
 *
 * One row per (agency, track, agent, suburb). Several agents may share a suburb.
 * p24_suburb_id is our internal p24_suburbs.id (same convention as
 * tracked_properties.p24_suburb_id — no foreign key; validated against the
 * agency's Prospecting Setup pool in code).
 *
 * Soft delete only. The unique index ignores soft deletes, so assigning a
 * suburb that was ever unassigned RESTORES the trashed row (BUILD_STANDARD §5a).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts_agent_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->unsignedBigInteger('branch_id')->nullable()->index();
            $table->string('track', 20)->default('sales');
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedBigInteger('p24_suburb_id');
            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'track', 'user_id', 'p24_suburb_id'], 'rts_agent_areas_unique');
            $table->index(['agency_id', 'track', 'p24_suburb_id'], 'rts_agent_areas_suburb_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_agent_areas');
    }
};

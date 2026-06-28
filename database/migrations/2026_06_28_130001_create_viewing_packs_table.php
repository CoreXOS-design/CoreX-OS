<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-XX Viewing Pack — Step 2: persistence spine (table 1 of 3).
 *
 * A Viewing Pack is the buyer-facing mirror of the Presentation: an ordered set
 * of properties an agent assembles for a buyer to view on a tour (spec §1/§2).
 * This table is the pack header; the selected properties and their documents
 * live in viewing_pack_properties / viewing_pack_documents.
 *
 * Tenant-owned: agency_id + AgencyScope (a pack is never visible across
 * agencies). Soft-deletes only — "archive" is a soft delete; admin can recover
 * (no hard deletes, ever). FKs confirmed in the Step-2 investigation:
 *   contact_id → contacts.id, agent_id → users.id, agency_id → agencies.id,
 *   calendar_event_id → calendar_events.id (nullable; wired in Step 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();   // the buyer
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();         // owning agent
            $table->foreignId('calendar_event_id')->nullable()->constrained('calendar_events')->nullOnDelete();

            // draft → being built; ready → finalised for the tour. The ARCHIVE
            // mechanism is soft-delete (deleted_at), not this string.
            $table->string('status', 20)->default('draft');
            $table->string('title')->nullable(); // e.g. "Viewing Pack — {Buyer} — {date}"

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'contact_id']);
            $table->index(['agency_id', 'agent_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_packs');
    }
};

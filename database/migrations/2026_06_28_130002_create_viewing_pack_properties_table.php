<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-XX Viewing Pack — Step 2: persistence spine (table 2 of 3).
 *
 * One row per property selected into a pack. `sort_order` is the agent's manual
 * drag order (spec §4) — the page order in both the buyer pack and agent sheet.
 * `source` records whether the property came from the buyer's Core Matches or
 * an ad-hoc search (spec §3); selection mechanics arrive in Step 3.
 *
 * agency_id is denormalised here (the documents table carries no agency_id) so
 * AgencyScope applies directly to every pack child. Soft-deletes cascade from
 * the parent pack via the model layer (ViewingPack::deleting), keeping children
 * scoped out with the pack and intact on restore — no orphans, no hard loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_pack_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('viewing_pack_id')->constrained('viewing_packs')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('source', 20)->default('core_match'); // core_match | ad_hoc

            $table->timestamps();
            $table->softDeletes();

            $table->index(['viewing_pack_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_pack_properties');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, share-history piece — Johan's ruling: "we record,
 * internally, each time the agent shares the link, and what the match set
 * contained AT THAT MOMENT. Written once. Never edited, never
 * recalculated, never rewritten when stock changes." This is that record:
 * one row per property that was part of a share event's live match set,
 * resolved via ClientMatchResolver at share time (the exact same query the
 * live link itself runs — never a second, parallel query).
 *
 * NOT a cache and NOT served to the buyer (ruling 4: "the link should
 * actually operate live... otherwise the link shows old stock in a
 * month"). Purely internal, evidentiary, and the basis for "properties not
 * seen since you last sent it."
 *
 * contact_match_id is denormalised alongside the contact_match_share_id FK
 * — the dominant query ("every property_id ever shared for this match")
 * filters on it directly rather than joining through contact_match_shares
 * every time, matching this codebase's existing agency_id-everywhere
 * convention for the same reason.
 *
 * property_id is restrictOnDelete(), not cascade/nullOnDelete: properties
 * are themselves soft-deleted (never hard-deleted) under CoreX's
 * non-negotiable #1, so this should never fire in practice — but if it
 * ever did, losing evidence of what was shown silently would be worse than
 * a blocked delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_share_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->foreignId('contact_match_share_id')->constrained('contact_match_shares')->cascadeOnDelete();
            $table->foreignId('property_id')->constrained('properties')->restrictOnDelete();
            $table->timestamp('created_at');

            // Explicit short name — the auto-generated one (table + both
            // column names + "_index") exceeds MySQL's 64-char identifier
            // limit. contact_match_share_id already has its own index via
            // the FK constraint above; no separate index needed for it.
            $table->index(['contact_match_id', 'property_id'], 'cmsp_match_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_share_properties');
    }
};

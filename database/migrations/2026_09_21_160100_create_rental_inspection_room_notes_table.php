<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-21, from Retha's real paper out-inspection form: every
 * room table on it carries its OWN free-text notes box, holding evidence
 * that belongs to the whole room, not any single item — "3x nails in
 * wall", "can't test aircon no batteries", "damp under windows in corner".
 * Per-item notes (rental_inspection_observations.notes) stay as they are;
 * this is in addition, not instead.
 *
 * Scoped to (rental_inspection_id, property_room_id) — a note recorded
 * DURING one specific walkthrough, never on PropertyRoom itself (which is
 * a permanent, cross-tenancy, potentially Sales-shared record, §3.2 —
 * a note about "what I found in Bedroom 2 during THIS inspection" has no
 * home there). Immutable, never edited or deleted (UPDATED_AT = null,
 * same convention as rental_inspection_observations, §3.3) — a correction
 * is a NEW row; "the room's current note" is simply the latest one, same
 * pattern as an item's currentObservation().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_room_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_room_id')->constrained()->cascadeOnDelete();
            $table->text('note');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['agency_id', 'rental_inspection_id', 'property_room_id'], 'ri_room_notes_agency_inspection_room_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_room_notes');
    }
};

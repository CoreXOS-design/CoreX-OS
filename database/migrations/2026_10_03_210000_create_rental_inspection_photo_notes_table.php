<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-433 Part C — the photo-level note, distinct from the item comment
 * (`rental_inspection_observations.notes`). Two levels, not one: the item
 * comment says what the ITEM is like; this note says what THIS PHOTO shows
 * — one scuff photo out of four is the evidence, the note is what makes it
 * evidence. .ai/specs/rental-inspections.md §25.
 *
 * Unlike an observation (immutable, evidentiary, never edited/deleted —
 * §3.3), a photo note is Full CRUD per Johan's explicit ruling for this
 * build: edit-in-place, soft-delete/restore, never an append-only
 * supersede chain. At most one LIVE note per photo is enforced at the
 * model/controller layer (RentalInspectionPhotoNoteController::store()),
 * deliberately NOT as a DB unique constraint — a unique index has no
 * soft-delete awareness (BUILD_STANDARD §5a's exact warning: it enforces
 * uniqueness across ALL rows, trashed or not), and this table's own
 * archive-then-recreate flow would hit that trap immediately.
 *
 * `rental_inspection_id` is denormalized here the same way it is on
 * `rental_inspection_photos` itself (2026_09_22_140000's own docblock) —
 * purely so "every note on this inspection" never needs a join through
 * photos. `rental_inspection_photo_id` is the real, authoritative parent.
 *
 * RentalInspectionPhoto::archive() cascades: a photo's live note (if any)
 * is archived in the same call — a parent's own SoftDeletes global scope
 * does NOT cascade to a child read FROM the parent, so this needed an
 * explicit hook rather than "free" protection from the scope alone (found
 * by testing, see that method's own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_photo_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')
                ->constrained(indexName: 'ri_photo_notes_inspection_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_photo_id')
                ->constrained(indexName: 'ri_photo_notes_photo_fk')->cascadeOnDelete();

            $table->string('classification_key', 60);
            $table->text('note');

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_photo_notes_created_by_fk')->nullOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_photo_notes_updated_by_fk')->nullOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_photo_notes_archived_by_fk')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // explicit short name — auto-generated one exceeds MySQL's 64-char limit.
            $table->index(['agency_id', 'rental_inspection_photo_id'], 'ri_photo_notes_agency_photo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_photo_notes');
    }
};

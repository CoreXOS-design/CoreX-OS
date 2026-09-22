<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §20.15 — the comparison-view "match
 * photos" feature: Johan wants a photo on the In Inspection panel linkable
 * to a photo on the next (Out, or a future ad-hoc) inspection, persisted
 * (not a view-only state) so the pair stays together in the compare view,
 * the modal, and any report/document generated later. A photo may carry
 * more than one match (the same damage can show in several shots), so this
 * is a genuine many-to-many self-join over rental_inspection_photos, not a
 * column on the photo itself.
 *
 * `photo_id_a`/`photo_id_b` are always stored in canonical order (the
 * lower id first — enforced in RentalInspectionPhotoMatch::matchPhotos(),
 * never here) so the unique pair index catches a duplicate regardless of
 * which side the caller names first.
 *
 * Soft-deletable (unmatch), never a hard delete — same evidentiary
 * discipline as the photo itself (non-negotiable #1) and the rest of this
 * feature (§20.13.1's amendment note). `matched_by_user_id`/`matched_at`
 * and `unmatched_by_user_id` mirror the existing tagged_by/archived_by
 * pairing already established on rental_inspection_photos — this is
 * evidence in a deposit dispute and carries the same audit weight as a
 * recorded condition.
 *
 * Every index/constraint name below is explicit and short — the
 * auto-generated name for a two-long-column-name unique index on this
 * table's own full name would risk MySQL's 64-character identifier limit
 * (same reasoning already documented on rental_inspection_photos' own
 * migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_photo_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained(indexName: 'ripm_agency_fk')->cascadeOnDelete();
            // Denormalized the same way rental_inspection_id is denormalized on
            // rental_inspection_photos (§20.13.1) — a match spans two different
            // inspections, but always the same property, so this is what every
            // scoping query actually filters on without a join through either photo.
            $table->foreignId('property_id')->constrained(indexName: 'ripm_property_fk')->cascadeOnDelete();
            $table->foreignId('photo_id_a')
                ->constrained('rental_inspection_photos', indexName: 'ripm_photo_a_fk')->cascadeOnDelete();
            $table->foreignId('photo_id_b')
                ->constrained('rental_inspection_photos', indexName: 'ripm_photo_b_fk')->cascadeOnDelete();
            $table->foreignId('matched_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripm_matched_by_fk')->nullOnDelete();
            $table->timestamp('matched_at');
            $table->foreignId('unmatched_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripm_unmatched_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['photo_id_a', 'photo_id_b'], 'ripm_pair_unique');
            $table->index(['agency_id', 'property_id'], 'ripm_agency_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_photo_matches');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §20.16 — one row per photo's membership
 * in a match group. Johan's own instinct, taken as the rule: a photo
 * belongs to at MOST ONE active group at a time — "one group per photo
 * keeps it comprehensible." That invariant is enforced in
 * RentalInspectionPhotoMatchGroup::addMember() at the application layer
 * (soft-deleting any prior active membership before creating the new one),
 * NOT by a database unique constraint on the photo id alone — a plain
 * unique index can't express "unique among non-deleted rows only" in
 * MySQL, the exact gotcha RentalInspectionPhotoMatch::matchPhotos() already
 * had to work around for its own pair-uniqueness (a soft-deleted row still
 * occupies its unique slot). Keeping full history this way — rather than
 * overwriting one photo-keyed row in place — means a photo's past group
 * memberships stay visible after it moves to a different group, matching
 * the audit weight this evidence carries (Johan, §20.15.1).
 *
 * `added_by_user_id`/`added_at` and `removed_by_user_id` mirror the exact
 * pairing already established on the old table's `matched_by_user_id`/
 * `matched_at`/`unmatched_by_user_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_photo_match_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained(indexName: 'ripmgm_agency_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_photo_match_group_id')
                ->constrained('rental_inspection_photo_match_groups', indexName: 'ripmgm_group_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_photo_id')
                ->constrained('rental_inspection_photos', indexName: 'ripmgm_photo_fk')->cascadeOnDelete();
            $table->foreignId('added_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripmgm_added_by_fk')->nullOnDelete();
            $table->timestamp('added_at');
            $table->foreignId('removed_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripmgm_removed_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_inspection_photo_id'], 'ripmgm_agency_photo_idx');
            $table->index('rental_inspection_photo_match_group_id', 'ripmgm_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_photo_match_group_members');
    }
};

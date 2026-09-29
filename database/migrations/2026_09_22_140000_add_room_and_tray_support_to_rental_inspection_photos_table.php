<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Photos-on-the-inspection-surface rebuild (2026-09-22, item 2/3):
 * `rental_inspection_photos` was item-only (required
 * `rental_inspection_observation_id`). A photo now optionally belongs to a
 * ROOM (general shot, no single item) or sits UNTAGGED in the inspection's
 * own tray until an agent files it — so the FK that used to be required is
 * now nullable, and two new columns say what a photo IS tagged to.
 *
 * `rental_inspection_id` is denormalized here the same way `property_id` is
 * denormalized on `rental_inspections` itself (.ai/specs/rental-inspections.md
 * §3.2) — purely so "every photo on this inspection, tagged or not" never
 * needs a join through observations. Backfilled from the existing
 * observation link for every row that already has one.
 *
 * Amendment to the original "no deleted_at at all" design call
 * (rental-inspections.md §3.3): that reasoning holds for OBSERVATIONS
 * (never edited/removed — the evidentiary fact). Johan's explicit ruling
 * for THIS build is narrower and different: a photo an agent uploaded by
 * mistake (duplicate, wrong property, blurry) must be removable — archived,
 * never hard-deleted. Standard SoftDeletes; every list query already reads
 * through Eloquent so the global "not trashed" default applies everywhere
 * without a separate flag to remember.
 *
 * `property_room_id` carries the COLUMN here but not the FK constraint —
 * `property_rooms` is created by a LATER-dated migration
 * (2026_09_30_100400), so a fresh migrate:fresh (filename order) would
 * fail here with "Failed to open the referenced table" before that table
 * exists. The constraint itself (same index name 'ri_photos_room_fk',
 * same ON DELETE SET NULL) is added once that table exists — see
 * 2026_09_30_100600_add_deferred_foreign_keys_for_later_created_tables.php.
 * `rental_inspection_id` FKs to `rental_inspections`, created long before
 * this file (2026_09_17) — no ordering issue, unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->foreignId('rental_inspection_id')->nullable()->after('agency_id')
                ->constrained(indexName: 'ri_photos_inspection_fk')->cascadeOnDelete();
            $table->foreignId('property_room_id')->nullable()->after('rental_inspection_observation_id');
            $table->timestamp('tagged_at')->nullable();
            $table->foreignId('tagged_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_photos_tagged_by_fk')->nullOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_photos_archived_by_fk')->nullOnDelete();
            $table->softDeletes();
        });

        // doctrine/dbal is not installed on this box, so Blueprint::change()
        // is unavailable — a raw MODIFY is the only way to relax this FK's
        // NOT NULL constraint. The existing FK constraint on this column
        // survives a MODIFY that only touches nullability.
        DB::statement('ALTER TABLE rental_inspection_photos MODIFY rental_inspection_observation_id BIGINT UNSIGNED NULL');

        // No `property_room_id` backfill here — `rental_inspection_items.property_room_id`
        // is added by a LATER-dated migration (2026_09_30_100500), so on a fresh
        // migrate:fresh this SELECT would fail with "Unknown column" before that
        // column exists. Harmless to drop: a genuinely fresh environment has zero
        // pre-existing `rental_inspection_photos` rows to backfill in the first
        // place — this statement always was, and remains, a no-op on a fresh
        // install. Already-migrated environments (QA1) ran this migration's
        // ORIGINAL content long ago; this file's content never re-runs there.
        DB::statement('
            UPDATE rental_inspection_photos p
            INNER JOIN rental_inspection_observations o ON o.id = p.rental_inspection_observation_id
            SET p.rental_inspection_id = o.rental_inspection_id,
                p.tagged_at = p.created_at,
                p.tagged_by_user_id = p.uploaded_by_user_id
            WHERE p.rental_inspection_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('rental_inspection_photos', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropConstrainedForeignId('archived_by_user_id');
            $table->dropConstrainedForeignId('tagged_by_user_id');
            $table->dropColumn('tagged_at');
            // Not dropConstrainedForeignId() — this migration's own up() no longer
            // adds a constraint on property_room_id (that FK is added by the later
            // deferred-FK migration, which drops it in its own down()).
            $table->dropColumn('property_room_id');
            $table->dropConstrainedForeignId('rental_inspection_id');
        });
        DB::statement('ALTER TABLE rental_inspection_photos MODIFY rental_inspection_observation_id BIGINT UNSIGNED NOT NULL');
    }
};

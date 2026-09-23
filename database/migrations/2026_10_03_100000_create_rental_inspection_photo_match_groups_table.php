<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §20.16 — replaces the pairwise
 * `rental_inspection_photo_matches` self-join (§20.15.1) with a genuine
 * group: a SET of photos, from any number of inspections, that all depict
 * the same thing. Johan, 2026-09-23: "2 x 5 is ten separate links to
 * create and keep consistent, and any one going stale breaks the set...
 * join the group, not the other photo."
 *
 * The old table is left in place, untouched and unread by the app going
 * forward — its data is carried into these new tables by the migration
 * immediately after this one (2026_10_03_100200), never dropped (schema
 * history, not a live dependency).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_photo_match_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained(indexName: 'ripmg_agency_fk')->cascadeOnDelete();
            // Denormalized the same way the old table denormalized it (§20.15.1)
            // — a group spans photos from different inspections but always the
            // same property, and every scoping query filters on this directly.
            $table->foreignId('property_id')->constrained(indexName: 'ripmg_property_fk')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripmg_created_by_fk')->nullOnDelete();
            $table->foreignId('archived_by_user_id')->nullable()
                ->constrained('users', indexName: 'ripmg_archived_by_fk')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'property_id'], 'ripmg_agency_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_photo_match_groups');
    }
};

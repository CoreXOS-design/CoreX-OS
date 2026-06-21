<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build 2 — review flow on per-version basis + agent_overrides log.
 *
 * Each generated PresentationVersion now carries its own review lifecycle:
 *   draft → awaiting_review → published
 *                          → archived (revert)
 *
 * Spec: .ai/specs/presentation-data-lineage.md §3-B + Build 2 prompt.
 * The audit (Build 1) called for review-status on presentation_versions
 * specifically — Presentation.status (draft/finalized) is per-property,
 * version-status is per-render.
 *
 * agent_overrides log:
 *   Every change the agent makes on the review screen — comp ticks,
 *   category additions, field edits, section toggles — lands here.
 *   Pure log, no business logic reads from it this build. Foundation for
 *   future "learn what agents change about CMA Info's defaults" surface.
 */
return new class extends Migration {
    public function up(): void
    {
        // Idempotent — a previous partial run on this dev DB may have already
        // landed some columns. Check before adding.
        Schema::table('presentation_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('presentation_versions', 'review_status')) {
                $table->enum('review_status', ['draft', 'awaiting_review', 'published', 'archived'])
                      ->default('draft')
                      ->after('blueprint_version');
            }
            if (!Schema::hasColumn('presentation_versions', 'reviewer_user_id')) {
                $table->unsignedBigInteger('reviewer_user_id')->nullable()->after('compiled_by');
            }
            if (!Schema::hasColumn('presentation_versions', 'reviewer_locked_at')) {
                $table->timestamp('reviewer_locked_at')->nullable()->after('reviewer_user_id');
            }
            if (!Schema::hasColumn('presentation_versions', 'awaiting_review_at')) {
                $table->timestamp('awaiting_review_at')->nullable()->after('compiled_at');
            }
            if (!Schema::hasColumn('presentation_versions', 'published_at')) {
                $table->timestamp('published_at')->nullable()->after('awaiting_review_at');
            }
            if (!Schema::hasColumn('presentation_versions', 'archived_at')) {
                $table->timestamp('archived_at')->nullable()->after('published_at');
            }
            if (!Schema::hasColumn('presentation_versions', 'deleted_at')) {
                $table->softDeletes();
            }
            // Build 2 — comp-selection state. Null = every compiled comp
            // included (default). Array of ids = whitelist. Agent ticks
            // and unticks on the review screen rewrite this column; the
            // agent_overrides log captures each change separately for
            // future learning. Comma-separated would have been smaller
            // but JSON gives us cheap whereJsonContains() lookups for
            // analytics later.
            if (!Schema::hasColumn('presentation_versions', 'included_comp_ids_json')) {
                $table->json('included_comp_ids_json')->nullable()->after('data_snapshot_json');
            }
        });

        // Foreign key + index added in a separate pass so the schema
        // builder sees the columns it's referencing.
        Schema::table('presentation_versions', function (Blueprint $table) {
            try { $table->foreign('reviewer_user_id')->references('id')->on('users')->nullOnDelete(); }
            catch (\Throwable $e) { /* FK already exists */ }
            try { $table->index(['presentation_id', 'review_status']); }
            catch (\Throwable $e) { /* index already exists */ }
        });

        // Backfill: every existing compiled version is treated as already
        // published — they're historic, agent never reviewed them through
        // the new screen. Keeps the "Last published" surfaces working.
        \Illuminate\Support\Facades\DB::table('presentation_versions')
            ->whereNotNull('compiled_at')
            ->update([
                'review_status' => 'published',
                'published_at'  => \Illuminate\Support\Facades\DB::raw('compiled_at'),
            ]);

        Schema::create('agent_overrides', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('presentation_version_id');
            $table->unsignedBigInteger('user_id');

            // The kind of change the agent made.
            $table->enum('override_type', [
                'comp_excluded',
                'comp_included',
                'category_added',
                'category_removed',
                'condition_changed',
                'section_toggled',
                'field_edited',
                'review_takeover',          // Build 2 robustness — concurrent reviewer notice
                'comp_unavailable',         // Build 2 robustness — comp soft-deleted between compile+review
                // AT-22 curation toolkit. Originally added by the backdated
                // 2026_06_11_190000 MODIFY; folded in here so a fresh DB (whose
                // filename order runs that MODIFY before this create) still
                // gets the complete enum. See that migration's note.
                'comp_bulk_set',            // price-slider / select-all / bulk-tick batch write
                'comp_added',               // comps pulled in from the browse-beyond-the-pool panel
            ]);

            // Polymorphic-ish target — comp id, category id, field name, etc.
            // String so we don't have to commit to a single foreign table.
            $table->string('target_id', 64)->nullable();

            $table->json('before_value')->nullable();
            $table->json('after_value');

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('presentation_version_id')->references('id')->on('presentation_versions')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->index(['presentation_version_id', 'override_type'], 'idx_av_version_type');
            $table->index(['agency_id', 'created_at'], 'idx_av_agency_created');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_overrides');

        Schema::table('presentation_versions', function (Blueprint $table) {
            $table->dropForeign(['reviewer_user_id']);
            $table->dropIndex(['presentation_id', 'review_status']);
            $table->dropColumn([
                'review_status',
                'reviewer_user_id',
                'reviewer_locked_at',
                'awaiting_review_at',
                'published_at',
                'archived_at',
                'deleted_at',
            ]);
        });
    }
};

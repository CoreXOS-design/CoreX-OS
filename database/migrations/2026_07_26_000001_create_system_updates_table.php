<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Updates — the CoreX product release note a user sees as a pop-up.
 *
 * Spec: .ai/specs/system-updates.md §4.1.
 *
 * DELIBERATELY NO agency_id (spec §3, documented exception to non-negotiable #7).
 * A system update is a CoreX PRODUCT release note authored by the System Owner,
 * describing a change to the CoreX codebase that every tenant just received. It is
 * not tenant-owned data — it is data ABOUT the product, addressed to everyone using
 * it. Stamping an agency_id would either force the owner to re-author the same note
 * once per agency, or create a row whose agency_id is a lie.
 *
 * The model therefore does NOT use BelongsToAgency and AgencyScope is never
 * registered on it — so no withoutGlobalScope() call appears anywhere in request
 * code. There is nothing to bypass. Write access is owner_only (spec §10), so a
 * global table cannot become a cross-tenant leak vector.
 *
 * `type` is a plain string with an application-level allow-list
 * (config/system-updates.php), NOT a DB enum: changing the vocabulary must never
 * require an ALTER TABLE on a live database.
 *
 * There is no audience column: EVERY update goes to every CoreX user (Johan,
 * 2026-07-26). A release note an agency or a role could be excluded from would
 * recreate the "ships inert" problem the feature exists to solve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table) {
            $table->id();

            $table->string('title', 160);
            $table->text('body');

            // Vocabulary lives in config/system-updates.php (spec §5).
            $table->string('type', 20)->default('feature');

            // Optional "Take me there" button — either part may be absent (spec §9.2).
            $table->string('link_url', 255)->nullable();
            $table->string('link_label', 60)->nullable();

            // Optional screenshot on the `public` disk under system-updates/.
            $table->string('image_path', 255)->nullable();

            $table->string('status', 20)->default('draft');   // draft | published
            $table->timestamp('published_at')->nullable();

            // "Re-notify everyone" watermark (spec §7.4). Dismissals older than
            // this stop counting, so the update becomes pending again WITHOUT
            // deleting a single view row — the original audit survives intact.
            $table->timestamp('notify_reset_at')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                  ->constrained('users')->nullOnDelete();

            $table->softDeletes();   // non-negotiable #1 — "delete" archives
            $table->timestamps();

            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};

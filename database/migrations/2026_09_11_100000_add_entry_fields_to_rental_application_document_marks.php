<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture-ledger rework (2026-09-11) — Johan: "it stays split and
 * disconnected" (on the old separate ledger strip). The fix removes the
 * transcription gap instead of moving it: the highlighter mark IS the
 * ledger line. This extends the existing mark record
 * (rental_application_document_marks, AT-401) rather than building a
 * second table — the 0-1 fraction coordinate system and the resize
 * re-projection it already carries are reused unchanged.
 *
 * Two structural changes beyond the four new columns:
 *
 * 1. `document_id` becomes nullable. A capture entry drawn on a document
 *    keeps it (an "anchored" entry); a manually-typed entry (Stage 3) or a
 *    migrated pre-existing affordability line (see the companion backfill
 *    migration) has none — an "unanchored" entry, same table, same
 *    columns, just no document/page/points to jump to.
 * 2. `page` and `type` become nullable for the same reason — an unanchored
 *    entry was never drawn, so it has no page and is neither a 'highlight'
 *    nor a 'note'.
 *
 * `rental_application_id` is new and NOT derived transitively through
 * `document_id` — an unanchored entry has no document to derive it from,
 * so every capture-ledger row (anchored or not) carries this directly,
 * making "every ledger line for application X" a single indexed WHERE
 * rather than a join that breaks the moment document_id is null.
 *
 * `entry_type` defaults to 'annotation' so every EXISTING row (plain
 * highlights and notes, all of them, on every application) backfills
 * correctly with zero data migration: they were never ledger lines and
 * must never appear in the new panel — 'annotation' is exactly that
 * "not a ledger line" state, not a placeholder.
 *
 * `source`/`confidence` (already on this table, reserved for OCR) are
 * NOT touched, read, or referenced anywhere in this work — OCR is a
 * separate decision Johan has not made yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->dropForeign(['document_id']);
        });

        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->unsignedBigInteger('document_id')->nullable()->change();
            $table->unsignedInteger('page')->nullable()->change();
            $table->enum('type', ['highlight', 'note'])->nullable()->change();
        });

        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->foreign('document_id')->references('id')->on('documents')->nullOnDelete();

            $table->foreignId('rental_application_id')->nullable()->after('document_id')
                ->constrained('rental_applications')->nullOnDelete();

            $table->enum('entry_type', ['income', 'expense', 'annotation'])->default('annotation')->after('type');
            $table->date('entry_date')->nullable()->after('entry_type');
            $table->string('entry_description', 255)->nullable()->after('entry_date');
            $table->decimal('entry_amount', 12, 2)->nullable()->after('entry_description');

            $table->index(['rental_application_id', 'entry_type'], 'ra_doc_marks_app_entry_type_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rental_application_document_marks', function (Blueprint $table) {
            $table->dropIndex('ra_doc_marks_app_entry_type_idx');
            $table->dropForeign(['rental_application_id']);
            $table->dropColumn(['rental_application_id', 'entry_type', 'entry_date', 'entry_description', 'entry_amount']);
        });

        // Not reversed: document_id/page/type nullability. A rollback that
        // re-tightened these to NOT NULL would fail the moment any
        // unanchored entry exists (manual or migrated) — the same
        // "refuse rather than fail destructively" rule the sibling
        // nullable-relaxation migration in this codebase already follows.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase D — .ai/specs/ppra-inspection-pack.md §4.3 (v3).
 *
 * Item (i), transformation initiatives. Versioned = append-only: every save
 * creates a NEW row and soft-deletes the prior current one (never edits
 * content in place). "Current" = latest non-deleted row for the agency. One
 * statement per agency (not per branch/user) — see §6.5's explicit, recorded
 * exception to the own/branch/agency scoping floor.
 *
 * v3 (Johan, 2026-09-28): either the principal writes a structured statement
 * IN CoreX (entry_type=structured, one row per initiative in structured_data)
 * or uploads their own document instead (entry_type=document) — either
 * satisfies the item.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_transformation_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->enum('entry_type', ['structured', 'document']);
            // entry_type=structured: [{description, start_date, end_date, people_involved, spend_amount}, ...]
            $table->json('structured_data')->nullable();
            // entry_type=document: private-disk path + the uploader's original filename.
            $table->string('document_path')->nullable();
            $table->string('document_original_name')->nullable();
            // Generated at save time (never re-derived later) — a one-line summary
            // for the version-history list and the Inspection Report's item (i) line.
            $table->text('summary')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_transformation_notes');
    }
};

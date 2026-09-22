<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspection-form.md §13 — the OMR scan reader, part 2 of
 * the two-part job cc5's printable form (§12) is built against. One row
 * per UPLOADED scan file (PDF or image) — the original is retained and
 * openable against the inspection forever (Johan's explicit requirement:
 * "the scan on file is what backs up anything we could not read"),
 * regardless of whether the reader could decode it.
 *
 * `rental_inspection_form_id` is nullable because it isn't known until
 * AFTER the page-identifier bits are decoded — the identifier grid sits at
 * a fixed position on every page (cc5's own design, §12.5), so a reader
 * locates and decodes it BEFORE it knows which manifest to consult. If the
 * decoded form_version doesn't match the inspection's CURRENT form
 * version, this stays null and `status` records the mismatch — marks are
 * never applied against the wrong layout (an agency reprinting after
 * adding an item must never have an old scan silently write onto the new
 * one).
 *
 * `decoded_inspection_id`/`decoded_form_version` are plain diagnostic
 * columns, not foreign keys — they record whatever the bits actually said,
 * even when that doesn't match anything real (a garbled scan, or the wrong
 * inspection's form uploaded here by mistake) so a human reviewing a
 * failed scan can see why.
 *
 * Soft-deletable (archived, never hard-deleted, non-negotiable #1) — "a
 * superseded scan is archived, never removed."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained(indexName: 'ri_scans_agency_fk')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()
                ->constrained(indexName: 'ri_scans_branch_fk')->nullOnDelete();
            $table->foreignId('rental_inspection_id')
                ->constrained(indexName: 'ri_scans_inspection_fk')->cascadeOnDelete();
            $table->foreignId('rental_inspection_form_id')->nullable()
                ->constrained(indexName: 'ri_scans_form_fk')->nullOnDelete();

            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 120);

            // processing -> needs_review -> applied
            //           -> version_mismatch (decoded, but wrong form version)
            //           -> failed (fiducials/identifier not decodable at all)
            $table->string('status', 30)->default('processing');
            $table->string('failure_reason', 500)->nullable();

            $table->unsignedBigInteger('decoded_inspection_id')->nullable();
            $table->unsignedInteger('decoded_form_version')->nullable();
            $table->unsignedSmallInteger('page_count')->nullable();

            $table->foreignId('uploaded_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_scans_uploaded_by_fk')->nullOnDelete();
            $table->foreignId('applied_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_scans_applied_by_fk')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('archived_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_scans_archived_by_fk')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'rental_inspection_id'], 'ri_scans_agency_inspection_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_scans');
    }
};

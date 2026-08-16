<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix — `docuperfect_documents` (App\Models\Docuperfect\Document)
 * has never carried an `agency_id` column. `Document` only uses
 * BelongsToBranch (a no-op unless the owning agency has
 * split_branches_enabled, which is off by default), so:
 *
 *   1. PageImageController::showDocumentPage() does Document::findOrFail($id)
 *      with NO ownership/scope check at all — a zero-guard IDOR streaming
 *      rendered signed-document page images (names, ID numbers, signed
 *      content) to any authenticated user holding the base
 *      `access_docuperfect` permission.
 *   2. AuthorizesDocumentAccess::guardDocument() and SignatureController's
 *      mirrored authorizeDocument() both `if ($scope === 'all') { return; }`
 *      with no further check — since Document has no agency dimension,
 *      'all' (an ordinary, non-owner-exclusive per-agency permission grant)
 *      currently means "every document in every agency", letting a
 *      non-owner Admin/Principal with an "edit all documents" grant
 *      mutate/destroy any OTHER agency's document by id.
 *
 * `signature_templates` does NOT get its own column: every row has a
 * NOT NULL `document_id` FK (see 2026_02_26_600001_create_signature_
 * templates_table.php), so a SignatureTemplate always has exactly one
 * parent Document and can cheaply derive agency_id via that relation
 * (single lazy/eager load, not a join) rather than duplicating and
 * re-backfilling the column on a second table.
 *
 * Adds the column nullable + FK + index, mirroring the rental_properties
 * precedent (2026_08_23_000001). Backfill happens in the next migration;
 * NOT NULL is applied in the migration after that, guarded on zero
 * remaining NULLs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('docuperfect_documents', 'agency_id')) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'docuperfect_documents' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                $table->foreign('agency_id')
                      ->references('id')->on('agencies')
                      ->nullOnDelete();
            });
        }

        $hasIndex = !empty(DB::select(
            "SHOW INDEX FROM docuperfect_documents WHERE Key_name = 'idx_docuperfect_documents_agency_id'"
        ));
        if (!$hasIndex) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                $table->index('agency_id', 'idx_docuperfect_documents_agency_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('docuperfect_documents', 'agency_id')) {
            Schema::table('docuperfect_documents', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_docuperfect_documents_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};

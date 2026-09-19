<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prod-audit 2026-09-16 (M2) — RentalApplicationDocumentValidityWindow was the
 * rental-applications module's one real hard delete: every settings save wiped
 * the per-document overrides and recreated them. Non-negotiable #1 says every
 * "delete" archives. The model now uses SoftDeletes and the settings save
 * reconciles (restore-or-create, archive the rest) instead of wiping.
 *
 * Existing rows get deleted_at = NULL by column default — no data migration.
 * Guarded so a re-run after a partial failure is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rental_application_document_validity_windows')
            || Schema::hasColumn('rental_application_document_validity_windows', 'deleted_at')) {
            return;
        }

        Schema::table('rental_application_document_validity_windows', function (Blueprint $table) {
            $table->softDeletes()->after('validity_days');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('rental_application_document_validity_windows', 'deleted_at')) {
            return;
        }

        Schema::table('rental_application_document_validity_windows', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

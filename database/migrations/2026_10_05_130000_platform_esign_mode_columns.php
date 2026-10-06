<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — Platform E-Sign mode (CoreX's own contracts, no agency).
 *
 *  - docuperfect_templates.is_platform: a template's NULL agency_id means "shared with every
 *    agency", so a platform template needs an explicit flag that customer queries exclude.
 *  - docuperfect_documents.agency_id becomes nullable (the only NOT NULL agency column the
 *    contract flow writes to); the existing FK to agencies is kept — NULL is simply "no agency".
 *    Agency-scoped reads treat NULL as an orphan, so these rows are invisible to every agency.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('docuperfect_templates', 'is_platform')) {
            Schema::table('docuperfect_templates', function (Blueprint $table) {
                $table->boolean('is_platform')->default(false)->after('is_global');
                $table->index('is_platform');
            });
        }

        DB::statement('ALTER TABLE docuperfect_documents MODIFY agency_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Never destroy data on a rollback: refuse — BEFORE changing anything — while platform
        // (agency-less) contracts exist.
        if (DB::table('docuperfect_documents')->whereNull('agency_id')->exists()) {
            throw new \RuntimeException('Cannot roll back: agency-less Platform E-Sign documents exist in docuperfect_documents. Archive/export them first (php artisan platform-esign:demo --remove for the demo).');
        }

        Schema::table('docuperfect_templates', function (Blueprint $table) {
            $table->dropIndex(['is_platform']);
            $table->dropColumn('is_platform');
        });

        DB::statement('ALTER TABLE docuperfect_documents MODIFY agency_id BIGINT UNSIGNED NOT NULL');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * CoreX Standard recipient presets (Johan, 2026-08-25) — the container for
 * SA conveyancing phrasing that ships with the product, before any agency
 * has configured their own. Follows the SAME shared-row convention already
 * used by automation_rules (agency_id nullable, is_system flag) and
 * documented in .ai/specs/multi-tenancy.md §2a: agency_id = NULL is the
 * deliberate "global row" signal AgencyScope already honours (never
 * silently re-tenants it — see BelongsToAgency's creating() hook) and
 * ALREADY structurally filters out of every agency-scoped query. That is
 * the actual protection Johan asked for: an agency's own preset CRUD is
 * agency_id-scoped, so it can never match, let alone edit or delete, a row
 * whose agency_id is NULL. is_system is the explicit, self-documenting
 * label on top of that — a query result should never have to infer "this
 * is a CoreX standard, not an orphan" from a bare NULL foreign key.
 *
 * Deliberately NOT touching resolveFor()/defaultFor()'s per-agency
 * selection behaviour in this migration or its model changes — that is
 * live document-generation logic ESignWizardController.php depends on
 * right now. This migration only makes CoreX Standard rows storable and
 * queryable; wiring them into automatic resolution is a separate decision
 * for whoever builds the setup-screen consumer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('esign_recipient_presets', 'is_system')) {
            Schema::table('esign_recipient_presets', function (Blueprint $table) {
                $table->boolean('is_system')->default(false)->after('applies_to');
                $table->index('is_system', 'esign_recipient_presets_is_system_index');
            });
        }

        // agency_id must be nullable to hold a CoreX Standard (global) row.
        // No doctrine/dbal in this project, so drop + recreate the FK by hand
        // rather than ->nullable()->change(). Idempotent: only acts if the
        // column is still NOT NULL.
        $col = DB::selectOne(
            "SELECT IS_NULLABLE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'esign_recipient_presets' AND COLUMN_NAME = 'agency_id'"
        );
        if ($col && $col->IS_NULLABLE === 'NO') {
            DB::statement('ALTER TABLE esign_recipient_presets DROP FOREIGN KEY esign_recipient_presets_agency_id_foreign');
            DB::statement('ALTER TABLE esign_recipient_presets MODIFY agency_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE esign_recipient_presets ADD CONSTRAINT esign_recipient_presets_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE CASCADE');
        }
    }

    public function down(): void
    {
        Schema::table('esign_recipient_presets', function (Blueprint $table) {
            if (Schema::hasColumn('esign_recipient_presets', 'is_system')) {
                $table->dropIndex('esign_recipient_presets_is_system_index');
                $table->dropColumn('is_system');
            }
        });

        // Revert agency_id to NOT NULL only if no global row would violate it.
        $hasGlobalRows = DB::table('esign_recipient_presets')->whereNull('agency_id')->exists();
        if (! $hasGlobalRows) {
            DB::statement('ALTER TABLE esign_recipient_presets DROP FOREIGN KEY esign_recipient_presets_agency_id_foreign');
            DB::statement('ALTER TABLE esign_recipient_presets MODIFY agency_id BIGINT UNSIGNED NOT NULL');
            DB::statement('ALTER TABLE esign_recipient_presets ADD CONSTRAINT esign_recipient_presets_agency_id_foreign FOREIGN KEY (agency_id) REFERENCES agencies (id) ON DELETE CASCADE');
        }
    }
};

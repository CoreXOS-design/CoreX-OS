<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HFC tenant-isolation audit, Wave 2 (#7) — the DocuPerfect document
 * subsystem was never brought into the BelongsToAgency pattern the base
 * `documents` table already has (2026_04_14_100000_add_agency_id_to_tenant
 * _tables.php). Any role with data-scope 'all' (a normal "admin sees
 * everything in my agency" grant) saw EVERY agency's documents — the
 * scopeVisibleTo() 'all' branch returned fully unfiltered — and
 * guardDocument()/PageImageController::showDocumentPage() had no per-
 * record check at all, letting any authenticated user with access_docuperfect
 * walk sequential document IDs to view/edit/delete another agency's
 * documents (including raw signed-page image bytes).
 *
 * docuperfect_templates gets agency_id too but is handled separately in
 * code (Template::scopeVisibleTo() + TemplateController::webPreview()) —
 * it does NOT get BelongsToAgency's automatic global scope, because
 * is_global=true templates are intentionally shared across every agency
 * and the trait's global scope would hide a shared template that lacks
 * an agency_id (NULL = orphan under AgencyScope, not "shared").
 *
 * Backfill: each table's own creator/sender FK -> users.agency_id. Any
 * remaining orphan (creator user deleted, orphaned agency_id lookup) is
 * assigned to the first agency, matching the established 2026-04-14
 * precedent — leaving it NULL would make it invisible under AgencyScope's
 * strict-orphan semantics, which for real historical production rows is
 * worse than attributing them to the original (first/oldest) tenant.
 */
return new class extends Migration
{
    private const TABLES = [
        'docuperfect_documents'  => 'owner_id',
        'docuperfect_templates'  => 'owner_id',
        'signature_templates'    => 'created_by',
        'sales_document_sends'   => 'sent_by',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $ownerColumn) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unsignedBigInteger('agency_id')->nullable()->after('id');
                });
            }
        }

        $this->backfill();

        foreach (self::TABLES as $table => $ownerColumn) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'agency_id')) {
                Schema::table($table, function (Blueprint $t) use ($table) {
                    $t->index('agency_id', "{$table}_agency_id_index");
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $ownerColumn) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'agency_id')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($table) {
                try { $t->dropIndex("{$table}_agency_id_index"); } catch (\Throwable $e) {}
                $t->dropColumn('agency_id');
            });
        }
    }

    private function backfill(): void
    {
        foreach (self::TABLES as $table => $ownerColumn) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, $ownerColumn)) {
                continue;
            }
            DB::statement("
                UPDATE {$table}
                SET agency_id = (
                    SELECT u.agency_id FROM users u WHERE u.id = {$table}.{$ownerColumn}
                )
                WHERE agency_id IS NULL
            ");
        }

        // signature_templates fallback: created_by user may be gone/null,
        // but the linked document's owner is a second-chance source.
        if (Schema::hasTable('signature_templates') && Schema::hasTable('docuperfect_documents')) {
            DB::statement("
                UPDATE signature_templates st
                JOIN docuperfect_documents d ON d.id = st.document_id
                JOIN users u ON u.id = d.owner_id
                SET st.agency_id = u.agency_id
                WHERE st.agency_id IS NULL
            ");
        }

        // Final safety net: assign any remaining orphans to the first
        // agency — same rationale as 2026_04_14_100000 (a NULL row is
        // invisible to everyone under AgencyScope's strict-orphan rule,
        // which for real historical data is worse than attributing it to
        // the original tenant).
        $firstAgencyId = DB::table('agencies')->orderBy('id')->value('id');
        if ($firstAgencyId) {
            foreach (array_keys(self::TABLES) as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'agency_id')) {
                    DB::table($table)->whereNull('agency_id')->update(['agency_id' => $firstAgencyId]);
                }
            }
        }
    }
};

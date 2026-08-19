<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HFC tenant-isolation audit, Wave 3 (ported to QA2 from main) — Clause
 * Library, Packs, and Knowledge Base were never brought into the
 * BelongsToAgency pattern at all: none of docuperfect_clauses /
 * docuperfect_packs / knowledge_documents ever got an agency_id column, and
 * Clause::scopeVisibleTo() / Pack::scopeVisibleTo() both had
 * `if ($scope === 'all') return $query;` — completely unfiltered for any
 * role with full data-scope. KnowledgeController had no agency concept
 * whatsoever, so Ellie's RAG search (KnowledgeSearchService) surfaced any
 * agency's knowledge-base content to any other agency.
 *
 * Fix: add agency_id (BelongsToAgency) to all three content tables, exactly
 * like docuperfect_documents/templates/signature_templates in Wave 2. Once
 * scoped, is_global stops having any practical cross-agency effect for
 * Clause/Pack (AgencyScope's global `agency_id = X` ANDs with any OR clause
 * a caller adds — the same reason FieldGroupController's `orWhere('is_global',
 * true)` is already inert today). That is the correct outcome here: none of
 * the current is_global=1 rows are legitimately CoreX-wide content, so full
 * per-agency isolation is what's wanted, not a widened share. Genuine
 * cross-agency sharing (if ever wanted) needs the docuperfect_templates
 * pattern instead — an explicit scopeVisibleTo() OR, with NO BelongsToAgency
 * trait — not this migration.
 *
 * knowledge_categories is deliberately NOT touched — it is pure taxonomy
 * (like document_types), and KnowledgeDocument::category() eager-loads /
 * withCount('documents') automatically inherit the new per-document scope,
 * so category-level counts are correct without adding agency_id there too.
 *
 * Backfill: each table's own owner/uploader FK -> users.agency_id. Any
 * remaining orphan (creator user deleted) is assigned to the first agency,
 * matching the Wave 2 precedent.
 */
return new class extends Migration
{
    private const TABLES = [
        'docuperfect_clauses' => 'owner_id',
        'docuperfect_packs'   => 'owner_id',
        'knowledge_documents' => 'uploaded_by',
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

        // Final safety net: assign any remaining orphans to the first
        // agency — same rationale as Wave 2 (a NULL row is invisible to
        // everyone under AgencyScope's strict-orphan rule, which for real
        // historical data is worse than attributing it to the original
        // tenant).
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

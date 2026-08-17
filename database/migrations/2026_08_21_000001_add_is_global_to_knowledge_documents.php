<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-#9 — Knowledge Base selectable ownership. Johan: "as corex we can
 * upload for all, or each agency can only upload their own."
 *
 * Adds is_global (default false) to knowledge_documents, mirroring
 * docuperfect_templates.is_global exactly. Existing rows all default to
 * false (agency-private) — nothing already uploaded silently becomes
 * cross-agency visible; a doc only becomes global when someone with the
 * System Owner role explicitly flags it that way going forward.
 *
 * See KnowledgeDocument::scopeVisibleTo() for why this table does NOT rely
 * on BelongsToAgency's automatic global scope for the widened is_global
 * read path (same reasoning as docuperfect_templates, and the same
 * reasoning the Wave 3 migration's docblock gave for why genuine
 * cross-agency sharing needs the explicit-scope pattern, not the trait).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('knowledge_documents') && !Schema::hasColumn('knowledge_documents', 'is_global')) {
            Schema::table('knowledge_documents', function (Blueprint $t) {
                $t->boolean('is_global')->default(false)->after('agency_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('knowledge_documents') && Schema::hasColumn('knowledge_documents', 'is_global')) {
            Schema::table('knowledge_documents', function (Blueprint $t) {
                $t->dropColumn('is_global');
            });
        }
    }
};

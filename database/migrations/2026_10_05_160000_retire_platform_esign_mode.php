<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-447 — Platform E-Sign is now its own module (spec §3A), so the agency-less "mode" inside DocuPerfect is retired.
 *  - Archive (soft delete — never a hard delete) every agency-less DocuPerfect contract / ceremony / platform template.
 *    Restorable by clearing deleted_at on documents with agency_id NULL, their ceremonies, and templates with is_platform = 1.
 *  - agency_timelines.agreement_template_id (pointed at a DocuPerfect ceremony) becomes agreement_document_id
 *    (points at platform_esign_documents); stale values are cleared.
 * docuperfect_documents.agency_id stays nullable and docuperfect_templates.is_platform stays as inert columns: dropping
 * them would destroy the archived rows' identity for no gain.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        // Identify platform rows ONLY by what the retired mode alone could create: documents with agency_id NULL
        // (the column was NOT NULL until that mode) and templates flagged is_platform. Ceremonies follow their document.
        $platformDocs = DB::table('docuperfect_documents')->whereNull('agency_id')->pluck('id');
        DB::table('signature_templates')->whereIn('document_id', $platformDocs)->whereNull('deleted_at')->update(['deleted_at' => $now]);
        DB::table('docuperfect_documents')->whereIn('id', $platformDocs)->whereNull('deleted_at')->update(['deleted_at' => $now]);
        if (Schema::hasColumn('docuperfect_templates', 'is_platform')) {
            DB::table('docuperfect_templates')->where('is_platform', true)->whereNull('deleted_at')->update(['deleted_at' => $now]);
        }

        if (Schema::hasColumn('agency_timelines', 'agreement_template_id')) {
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->dropIndex(['agreement_template_id']));
            DB::table('agency_timelines')->update(['agreement_template_id' => null]);
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->renameColumn('agreement_template_id', 'agreement_document_id'));
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->index('agreement_document_id'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_timelines', 'agreement_document_id')) {
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->dropIndex(['agreement_document_id']));
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->renameColumn('agreement_document_id', 'agreement_template_id'));
            Schema::table('agency_timelines', fn (Blueprint $t) => $t->index('agreement_template_id'));
        }
    }
};

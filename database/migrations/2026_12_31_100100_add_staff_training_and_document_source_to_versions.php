<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Build staff training from this version" (compliance.md):
 *  - staff_training_* on both version tables record the last time the training was sent and to how many people
 *    (the dashboards print "Last sent ... - N of M signed");
 *  - policy_versions.source_kind / source_document_* describe a policy made from an UPLOADED document (stored as a
 *    PDF; one policy_sections row per page) instead of typed sections;
 *  - compliance_document_audit_log is the append-only trail for signed-copy uploads and training sends, for both
 *    document kinds.
 */
return new class extends Migration
{
    private const TABLES = ['rmcp_versions', 'policy_versions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'staff_training_sent_at')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->timestamp('staff_training_sent_at')->nullable();
                $t->unsignedBigInteger('staff_training_sent_by')->nullable();
                $t->unsignedInteger('staff_training_sent_count')->nullable();
            });
        }

        if (Schema::hasTable('policy_versions') && ! Schema::hasColumn('policy_versions', 'source_kind')) {
            Schema::table('policy_versions', function (Blueprint $t) {
                $t->string('source_kind', 16)->default('sections');
                $t->string('source_document_path', 500)->nullable();
                $t->string('source_document_name', 255)->nullable();
                $t->string('source_document_sha256', 64)->nullable();
                $t->unsignedSmallInteger('source_page_count')->nullable();
            });
        }

        if (! Schema::hasTable('compliance_document_audit_log')) {
            Schema::create('compliance_document_audit_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('document_kind', 16);          // rmcp | policy
                $t->unsignedBigInteger('version_id');
                $t->string('event_type', 40);             // signed_copy_uploaded | signed_copy_replaced | training_sent
                $t->unsignedBigInteger('actor_user_id')->nullable();
                $t->string('actor_label', 190)->nullable();
                $t->string('ip_address', 45)->nullable();
                $t->json('meta')->nullable();
                $t->timestamp('created_at')->useCurrent();

                $t->index(['document_kind', 'version_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_document_audit_log');

        if (Schema::hasTable('policy_versions') && Schema::hasColumn('policy_versions', 'source_kind')) {
            Schema::table('policy_versions', function (Blueprint $t) {
                $t->dropColumn(['source_kind', 'source_document_path', 'source_document_name', 'source_document_sha256', 'source_page_count']);
            });
        }
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'staff_training_sent_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn(['staff_training_sent_at', 'staff_training_sent_by', 'staff_training_sent_count']);
                });
            }
        }
    }
};

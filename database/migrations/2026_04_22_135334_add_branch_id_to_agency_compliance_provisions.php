<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_compliance_provisions', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('document_type_config_id')
                ->constrained('branches')
                ->nullOnDelete();
        });

        // Replace old index with branch-aware one
        Schema::table('agency_compliance_provisions', function (Blueprint $table) {
            $table->dropIndex('acp_agency_doctype_deleted_idx');
            $table->index(
                ['agency_id', 'document_type_config_id', 'branch_id', 'deleted_at'],
                'acp_agency_doctype_branch_deleted_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('agency_compliance_provisions', function (Blueprint $table) {
            $table->dropIndex('acp_agency_doctype_branch_deleted_idx');
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
            $table->index(
                ['agency_id', 'document_type_config_id', 'deleted_at'],
                'acp_agency_doctype_deleted_idx'
            );
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Safety guard: abort if existing data needs manual migration
        if (DB::table('agency_compliance_provisions')->count() > 0) {
            throw new \RuntimeException(
                'agency_compliance_provisions has existing data — data migration required before adding FK. Aborting.'
            );
        }

        Schema::table('agency_compliance_provisions', function (Blueprint $table) {
            $table->foreignId('document_type_config_id')
                ->nullable()
                ->after('provision_type')
                ->constrained('agency_document_type_configs')
                ->restrictOnDelete();

            $table->index(['agency_id', 'document_type_config_id', 'deleted_at'], 'acp_agency_doctype_deleted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('agency_compliance_provisions', function (Blueprint $table) {
            $table->dropForeign(['document_type_config_id']);
            $table->dropIndex('acp_agency_doctype_deleted_idx');
            $table->dropColumn('document_type_config_id');
        });
    }
};

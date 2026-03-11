<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'docuperfect_pack_attachments',
        'docuperfect_pack_instance_values',
        'docuperfect_pack_slots',
        'docuperfect_pack_templates',
        'docuperfect_template_branches',
        'docuperfect_template_signature_zones',
        'docuperfect_clause_branches',
        'flows',
        'lease_records',
        'rental_document_types',
        'rental_properties',
        'rental_reminder_settings',
        'signature_audit_log',
        'signature_markers',
        'signature_requests',
        'signature_templates',
        'signatures',
        'web_pack_items',
        'wet_ink_inspections',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && !Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};

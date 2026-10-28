<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** signed-document-distribution.md "The rules screen" - the two screens (matrix, stage rules) share one audit table, told apart by `area`. Every earlier row is a matrix save. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_distribution_audit_entries') || Schema::hasColumn('document_distribution_audit_entries', 'area')) {
            return;
        }
        Schema::table('document_distribution_audit_entries', function (Blueprint $table) {
            $table->string('area', 20)->default('matrix')->after('user_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_distribution_audit_entries', 'area')) {
            Schema::table('document_distribution_audit_entries', function (Blueprint $table) {
                $table->dropColumn('area');
            });
        }
    }
};

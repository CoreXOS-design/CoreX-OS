<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A signed copy of an RMCP / policy version, and who signed it (compliance.md - "Signed copy on file").
 *
 * The signed file lives beside the version, not inside the approval: it can be uploaded or replaced on an ACTIVE
 * version without approving it again (so no staff re-sign is fired). The same columns sit on both tables so one
 * service and one partial serve both documents.
 */
return new class extends Migration
{
    private const TABLES = ['rmcp_versions', 'policy_versions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'signed_copy_path')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->string('signed_copy_path', 500)->nullable();
                $t->string('signed_copy_name', 255)->nullable();
                $t->string('signed_copy_sha256', 64)->nullable();
                $t->unsignedBigInteger('signed_copy_uploaded_by')->nullable();
                $t->timestamp('signed_copy_uploaded_at')->nullable();
                $t->string('signed_by_name', 190)->nullable();
                $t->date('signed_on')->nullable();
                $t->date('submitted_to_fic_on')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'signed_copy_path')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn([
                    'signed_copy_path', 'signed_copy_name', 'signed_copy_sha256', 'signed_copy_uploaded_by',
                    'signed_copy_uploaded_at', 'signed_by_name', 'signed_on', 'submitted_to_fic_on',
                ]);
            });
        }
    }
};

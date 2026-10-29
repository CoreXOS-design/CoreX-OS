<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * esign-import-blanks.md - a CDS draft keeps the uploaded Word file, so the builder's "Re-read with current rules" can read it
 * again after the importer improves. The file sits on the private disk; these two columns say where and what it was called.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cds_drafts') || Schema::hasColumn('cds_drafts', 'source_path')) {
            return;
        }
        Schema::table('cds_drafts', function (Blueprint $table) {
            $table->string('source_path', 500)->nullable()->after('settings');
            $table->string('source_name', 255)->nullable()->after('source_path');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('cds_drafts', 'source_path')) {
            Schema::table('cds_drafts', function (Blueprint $table) {
                $table->dropColumn(['source_path', 'source_name']);
            });
        }
    }
};

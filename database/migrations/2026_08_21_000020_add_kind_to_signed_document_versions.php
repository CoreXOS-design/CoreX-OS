<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-SIGN recipient supporting-document uploads (cc2).
 *
 * `kind` distinguishes an ordinary signed-document version (NULL / 'signed') from an
 * optional supporting document a recipient uploaded during/after signing ('supporting').
 * Additive + backward-compatible: existing rows stay NULL and read as signed versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_document_versions', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('signature_request_id');
            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('signed_document_versions', function (Blueprint $table) {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};

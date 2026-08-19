<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-SIGN recipient supporting-docs — FILED state (Johan Part A).
 *
 * The "Recipient additional docs to file" list is the UNFILED working list. Once a recipient's
 * upload batch is filed (manually, or later by the multi-doc splitter signalling it filed them),
 * these stamps move the batch off the working list into the "Filed additional docs" archive.
 * Applies to kind='supporting' rows; NULL = not yet filed. Additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signed_document_versions', function (Blueprint $table) {
            $table->timestamp('filed_at')->nullable()->after('kind');
            $table->unsignedBigInteger('filed_by_user_id')->nullable()->after('filed_at');
            $table->index('filed_at');
        });
    }

    public function down(): void
    {
        Schema::table('signed_document_versions', function (Blueprint $table) {
            $table->dropIndex(['filed_at']);
            $table->dropColumn(['filed_at', 'filed_by_user_id']);
        });
    }
};

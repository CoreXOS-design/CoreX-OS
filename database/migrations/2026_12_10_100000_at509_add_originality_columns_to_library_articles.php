<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-509 — audit trail for the Article Library's original-wording guard. Nullable: rows written before
 * the guard existed have no score until `article-library:rescore` has been run over them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('library_articles', 'originality_overlap')) {
            Schema::table('library_articles', function (Blueprint $table) {
                $table->decimal('originality_overlap', 5, 4)->nullable()->after('cost_zar');
                $table->unsignedSmallInteger('originality_longest_run')->nullable()->after('originality_overlap');
            });
        }
    }

    public function down(): void
    {
        Schema::table('library_articles', function (Blueprint $table) {
            $table->dropColumn(['originality_overlap', 'originality_longest_run']);
        });
    }
};

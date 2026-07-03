<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-158 DR2 · WS3 — Document spine (decision D4).
 *
 * A unified `documents` row may now anchor to a DR2 deal. This is the ONLY
 * new column the document spine needs: one nullable FK to deals_v2.id, so a
 * single upload / split / e-sign auto-file becomes reachable from the deal
 * register alongside its existing property + contact pivots. A deal belongs
 * to at most one document; a document to at most one deal (belongsTo), so this
 * is a column, not a pivot.
 *
 * Nullable + onDelete('set null'): a document must never 500 or vanish because
 * its deal was archived — the file survives, the anchor simply clears
 * (BUILD_STANDARD §4 deleted-related-record rule).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (! Schema::hasColumn('documents', 'deal_id')) {
                $table->unsignedBigInteger('deal_id')->nullable()->after('source_id')->index();
                $table->foreign('deal_id')->references('id')->on('deals_v2')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            if (Schema::hasColumn('documents', 'deal_id')) {
                $table->dropForeign(['deal_id']);
                $table->dropIndex(['deal_id']);
                $table->dropColumn('deal_id');
            }
        });
    }
};

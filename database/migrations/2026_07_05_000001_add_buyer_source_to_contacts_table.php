<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Buyer-lifecycle loop — records HOW a buyer entered the pipeline so MIC demand
     * can stay source-tagged and never blended (Johan's two-streams rule):
     *   portal_p24 | portal_pp | manual  (null = legacy / agent-confirmed spreadsheet).
     * First-write-wins (primary entry origin). Flows to prospecting_buyer_matches.source.
     */
    public function up(): void
    {
        if (Schema::hasColumn('contacts', 'buyer_source')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('buyer_source', 32)->nullable()->after('buyer_pipeline_notes');
            $table->index(['agency_id', 'buyer_source'], 'contacts_buyer_source_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contacts', 'buyer_source')) {
            return;
        }

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_buyer_source_idx');
            $table->dropColumn('buyer_source');
        });
    }
};

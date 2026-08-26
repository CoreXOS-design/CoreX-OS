<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC possible-match (2026-08-22, Johan — the 43 Ridge investigation: "why did
 * we not match it there... it should have been caught in mic before it was
 * shown as pitch now").
 *
 * A SEPARATE, advisory column set from matched_property_id/matched_at (the
 * existing Pass 1/2 confident match, unchanged). Computed asynchronously
 * (ComputePossibleStockMatchJob, queue 'matching') — never on the list-page
 * read path, so cc4's MIC speed work is untouched. Populated only when Pass
 * 1/2 found nothing; a listing that is already confidently matched is never
 * touched by the possible-match job at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('possible_property_id')->nullable()->after('matched_at');
            $table->string('possible_match_verdict', 40)->nullable()->after('possible_property_id');
            $table->json('possible_match_candidate_ids')->nullable()->after('possible_match_verdict');
            $table->timestamp('possible_matched_at')->nullable()->after('possible_match_candidate_ids');

            $table->index(['agency_id', 'possible_property_id'], 'pl_possible_property_idx');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('pl_possible_property_idx');
            $table->dropColumn(['possible_property_id', 'possible_match_verdict', 'possible_match_candidate_ids', 'possible_matched_at']);
        });
    }
};

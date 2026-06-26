<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Part 6 — source dimension on the MIC demand cache so buyer demand can be shown
     * SEPARATELY per origin (portal-lead buyers vs everything else) and never merged
     * into one blended figure. Copied from contacts.buyer_source at recompute time.
     */
    public function up(): void
    {
        if (Schema::hasColumn('prospecting_buyer_matches', 'source')) {
            return;
        }

        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('tier');
            $table->index(['prospecting_listing_id', 'source', 'score'], 'pbm_listing_source_score');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('prospecting_buyer_matches', 'source')) {
            return;
        }

        Schema::table('prospecting_buyer_matches', function (Blueprint $table) {
            $table->dropIndex('pbm_listing_source_score');
            $table->dropColumn('source');
        });
    }
};

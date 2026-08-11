<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SUBURB RECONCILE (cc2).
 *
 * `last_search_id` = the ProspectingSearch (capture session) that most recently saw this
 * listing. The import stamps it on every touched listing; because all batches of one capture
 * upsert the SAME ProspectingSearch (per search_url per day), this is the cross-batch session
 * marker the suburb-reconcile uses to tell "present in this complete capture" from "gone".
 * Additive + nullable; existing rows read as NULL (never captured under the new import).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->unsignedBigInteger('last_search_id')->nullable()->after('is_active');
            $table->index('last_search_id');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex(['last_search_id']);
            $table->dropColumn('last_search_id');
        });
    }
};

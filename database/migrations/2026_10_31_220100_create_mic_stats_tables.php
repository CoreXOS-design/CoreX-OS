<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.5 (.ai/specs/mic-work-tab.md 11.1, AT-519): stored per-file counts and nightly pocket stats, so the Work page
 * and the hot-pocket tiles read numbers that were counted once (nightly + on change), never recounted per page load.
 *  - mic_agency_settings: one row per agency, every MIC threshold in one JSON column (cc6 builds the Settings page on it).
 *  - mic_file_stats: one row per tracked property file that has portal adverts.
 *  - mic_pocket_stats: one row per agency x day x (suburb, type, beds, price band).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mic_agency_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->json('values')->nullable();
            $table->timestamps();
        });

        Schema::create('mic_file_stats', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('tracked_property_id');
            // pocket keys of the file
            $table->unsignedBigInteger('p24_suburb_id')->nullable();
            $table->string('type_key', 24)->nullable();
            $table->unsignedTinyInteger('beds')->default(0);
            $table->string('band', 16)->nullable();
            $table->unsignedInteger('price')->nullable();
            // state
            $table->boolean('has_active_advert')->default(false);
            $table->boolean('own_stock')->default(false);
            $table->unsignedSmallInteger('adverts_active')->default(0);
            $table->unsignedSmallInteger('adverts_total')->default(0);
            // stored counts
            $table->unsignedSmallInteger('buyers_strong')->default(0);
            $table->unsignedSmallInteger('buyers_mid')->default(0);
            $table->unsignedSmallInteger('leads_total')->default(0);
            $table->unsignedSmallInteger('leads_30d')->default(0);
            $table->unsignedSmallInteger('cuts_count')->default(0);
            $table->unsignedInteger('cut_amount')->default(0);
            $table->unsignedSmallInteger('cuts_30d')->default(0);
            $table->unsignedSmallInteger('cuts_90d')->default(0);
            $table->dateTime('last_cut_at')->nullable();
            $table->boolean('relisted')->default(false);
            $table->unsignedSmallInteger('dom_days')->nullable();
            $table->string('dom_mode', 12)->nullable();
            // opportunity score (5.7)
            $table->decimal('score', 5, 2)->nullable();
            $table->unsignedTinyInteger('score_seller')->nullable();
            $table->unsignedTinyInteger('score_buyer')->nullable();
            $table->unsignedTinyInteger('score_pocket')->nullable();
            $table->unsignedTinyInteger('score_known')->nullable();
            $table->unsignedTinyInteger('score_reachable')->nullable();
            $table->string('reason_1', 32)->nullable();
            $table->string('reason_2', 32)->nullable();
            $table->dateTime('computed_at')->nullable();

            $table->primary(['agency_id', 'tracked_property_id']);
            $table->index(['agency_id', 'score'], 'mfs_agency_score_idx');
            $table->index(['agency_id', 'p24_suburb_id', 'type_key', 'beds', 'band'], 'mfs_pocket_idx');
            $table->index(['agency_id', 'has_active_advert', 'own_stock'], 'mfs_active_idx');
        });

        Schema::create('mic_pocket_stats', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->date('stat_date');
            $table->unsignedBigInteger('p24_suburb_id');
            $table->string('type_key', 24);
            $table->unsignedTinyInteger('beds');
            $table->string('band', 16);
            $table->unsignedInteger('market_stock')->default(0);
            $table->unsignedInteger('aged_90')->default(0);
            $table->unsignedInteger('aged_180')->default(0);
            $table->unsignedInteger('cut_30')->default(0);
            $table->unsignedInteger('cut_90')->default(0);
            $table->unsignedInteger('relisted')->default(0);
            $table->unsignedInteger('own_on_market')->default(0);
            $table->unsignedInteger('own_sold')->default(0);
            $table->unsignedInteger('own_sales_trusted')->default(0);
            $table->unsignedInteger('own_fast_sales')->default(0);
            $table->decimal('own_avg_days_to_sale', 7, 1)->nullable();
            $table->unsignedInteger('buyers')->default(0);
            $table->unsignedInteger('leads_30d')->default(0);
            $table->timestamps();

            $table->unique(['agency_id', 'stat_date', 'p24_suburb_id', 'type_key', 'beds', 'band'], 'mps_unique');
            $table->index(['agency_id', 'stat_date'], 'mps_agency_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_pocket_stats');
        Schema::dropIfExists('mic_file_stats');
        Schema::dropIfExists('mic_agency_settings');
    }
};

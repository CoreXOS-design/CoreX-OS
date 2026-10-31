<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.9 (.ai/specs/mic-work-tab.md 11.1b, AT-519): market context per file for the record card, stored so the page
 * never computes it. One row per file with portal adverts, rebuilt by mic:refresh-stats. Ids only - no personal names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mic_file_context', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('tracked_property_id');
            $table->string('sale_class', 10)->nullable();
            $table->unsignedBigInteger('suburb_id')->nullable();
            $table->unsignedInteger('suburb_avg_sold_12m')->nullable();
            $table->unsignedSmallInteger('suburb_avg_sold_n')->default(0);
            $table->unsignedInteger('suburb_r_per_m2')->nullable();
            $table->unsignedSmallInteger('suburb_r_per_m2_n')->default(0);
            $table->string('suburb_r_per_m2_basis', 5)->nullable();
            $table->unsignedInteger('own_size_m2')->nullable();
            $table->string('own_size_basis', 5)->nullable();
            $table->unsignedInteger('own_r_per_m2')->nullable();
            $table->decimal('own_vs_suburb_pct', 6, 1)->nullable();
            $table->decimal('asking_vs_avg_sold_pct', 6, 1)->nullable();
            $table->json('nearby_sales')->nullable();
            $table->json('last_deeds_sale')->nullable();
            $table->json('matched_buyers')->nullable();
            $table->json('our_history')->nullable();
            $table->dateTime('computed_at')->nullable();

            $table->primary(['agency_id', 'tracked_property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_file_context');
    }
};

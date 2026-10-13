<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — values the list builder used to hold as code constants become agency settings, each with the
 * old value as its default (.ai/specs/road-to-sold.md §9.11): the add-to-day maximum, the three
 * hot-pocket weights, which buyer match levels / buyer states drive "stock for buyers", and which
 * property statuses count as "on the market" for the agent's own stock.
 *
 * The list columns are JSON; NULL means "the standard list" (read through SettingsService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('add_to_day_max')->default(100)->after('stuck_stock_lookback_days');
            $table->unsignedTinyInteger('hot_pocket_weight_buyers')->default(1)->after('add_to_day_max');
            $table->unsignedTinyInteger('hot_pocket_weight_leads')->default(1)->after('hot_pocket_weight_buyers');
            $table->unsignedTinyInteger('hot_pocket_weight_sales')->default(1)->after('hot_pocket_weight_leads');
            $table->json('buyer_match_tiers')->nullable()->after('hot_pocket_weight_sales');
            $table->json('buyer_active_states')->nullable()->after('buyer_match_tiers');
            $table->json('on_market_statuses')->nullable()->after('buyer_active_states');
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->dropColumn([
                'add_to_day_max', 'hot_pocket_weight_buyers', 'hot_pocket_weight_leads', 'hot_pocket_weight_sales',
                'buyer_match_tiers', 'buyer_active_states', 'on_market_statuses',
            ]);
        });
    }
};

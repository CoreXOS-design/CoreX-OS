<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - audit gaps in lane 1's files (conductor, 13 Oct 2026).
 *  - rts_settings gets soft delete ("put everything back to the defaults" archives the row; the
 *    next save brings it back). rts_settings_audit stays append-only - no soft delete there.
 *  - public_holiday_country: which country's public holidays carry no target (was hard-coded ZA).
 *  - the values lane 2 hard-coded become agency settings (spec 9.11): buyer match tiers / buyer
 *    states, the "already a listing" and "on the market" status lists, the add-to-day ceiling,
 *    the scan page size and cap, and the three hot-pocket weights.
 * Lists are json and NULL = the code default, so an untouched agency is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->char('public_holiday_country', 2)->default('ZA')->after('area_refresh_warn_days');
            $table->json('buyer_match_tiers')->nullable();
            $table->json('active_buyer_states')->nullable();
            $table->json('taken_statuses')->nullable();
            $table->json('on_market_statuses')->nullable();
            $table->unsignedSmallInteger('add_to_day_max')->default(100);
            $table->unsignedSmallInteger('scan_page_size')->default(200);
            $table->unsignedSmallInteger('scan_max_pages')->default(8);
            $table->unsignedSmallInteger('hot_pocket_weight_buyers')->default(1);
            $table->unsignedSmallInteger('hot_pocket_weight_leads')->default(1);
            $table->unsignedSmallInteger('hot_pocket_weight_sales')->default(1);
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'public_holiday_country', 'buyer_match_tiers', 'active_buyer_states', 'taken_statuses', 'on_market_statuses',
                'add_to_day_max', 'scan_page_size', 'scan_max_pages',
                'hot_pocket_weight_buyers', 'hot_pocket_weight_leads', 'hot_pocket_weight_sales',
            ]);
        });
    }
};

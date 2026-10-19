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
        // Audit fix 2026-10-18: every add is hasColumn-guarded - a database that ran the since-deleted
        // 2026_10_29_120000_add_builder_limits_to_rts_settings already holds some of these (e.g. add_to_day_max).
        Schema::table('rts_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_settings', 'public_holiday_country')) {
                $table->char('public_holiday_country', 2)->default('ZA')->after('area_refresh_warn_days');
            }
            foreach (['buyer_match_tiers', 'active_buyer_states', 'taken_statuses', 'on_market_statuses'] as $column) {
                if (! Schema::hasColumn('rts_settings', $column)) {
                    $table->json($column)->nullable();
                }
            }
            foreach ([
                'add_to_day_max' => 100, 'scan_page_size' => 200, 'scan_max_pages' => 8,
                'hot_pocket_weight_buyers' => 1, 'hot_pocket_weight_leads' => 1, 'hot_pocket_weight_sales' => 1,
            ] as $column => $default) {
                if (! Schema::hasColumn('rts_settings', $column)) {
                    $table->unsignedSmallInteger($column)->default($default);
                }
            }
            if (! Schema::hasColumn('rts_settings', 'deleted_at')) {
                $table->softDeletes();
            }
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — lane 2 (the daily list) reads three look-back windows that must be agency
 * settings, not code constants (.ai/specs/road-to-sold.md §9.7, business rule "every
 * threshold/window/rule is an agency setting").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('hot_pocket_lookback_days')->default(180)->after('stuck_stock_max_leads');
            $table->unsignedSmallInteger('price_drop_lookback_days')->default(30)->after('hot_pocket_lookback_days');
            $table->unsignedSmallInteger('stuck_stock_lookback_days')->default(30)->after('price_drop_lookback_days');
        });
    }

    public function down(): void
    {
        Schema::table('rts_settings', function (Blueprint $table) {
            $table->dropColumn(['hot_pocket_lookback_days', 'price_drop_lookback_days', 'stuck_stock_lookback_days']);
        });
    }
};

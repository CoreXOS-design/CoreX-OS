<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WS1 (AT-158 / DR2) — the DR1↔DR2 link (D1/D3).
 *
 * A DR1 deal (`deals`) and its DR2 twin (`deals_v2`) point at each other so the
 * single-writer DealSyncService can mirror the shared core fields during the
 * parallel run. DR1's only change is this nullable pointer (D3: DR1 integrity
 * fully intact — no behavioural columns). No FK constraint across the two deal
 * generations (avoid a hard coupling / delete cascade between them); the pointer
 * is soft, validated by the sync service + parity harness.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (! Schema::hasColumn('deals', 'deal_v2_id')) {
                $table->unsignedBigInteger('deal_v2_id')->nullable()->after('id')->index();
            }
        });

        Schema::table('deals_v2', function (Blueprint $table) {
            if (! Schema::hasColumn('deals_v2', 'legacy_deal_id')) {
                $table->unsignedBigInteger('legacy_deal_id')->nullable()->after('id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            if (Schema::hasColumn('deals', 'deal_v2_id')) {
                $table->dropIndex(['deal_v2_id']);
                $table->dropColumn('deal_v2_id');
            }
        });
        Schema::table('deals_v2', function (Blueprint $table) {
            if (Schema::hasColumn('deals_v2', 'legacy_deal_id')) {
                $table->dropIndex(['legacy_deal_id']);
                $table->dropColumn('legacy_deal_id');
            }
        });
    }
};

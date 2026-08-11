<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC SOLD / OFF-MARKET + REF-TRACKING (cc2).
 *
 * Track each portal listing by its P24 ref across its lifecycle:
 *   - portal_status            — last portal-reported status (active/under_offer/sold/withdrawn)
 *   - portal_status_changed_at — when that status last changed
 *   - off_market_at            — when the listing left the active pool
 *                                (days-on-market = off_market_at − first_seen_at)
 *
 * Additive + backward-compatible: NULL portal_status = never observed (legacy rows /
 * pre-status extension) and the import keeps the existing is_active=true behaviour for
 * those. Indexed on portal_status so the off-market sweep's status predicate is sargable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->string('portal_status', 20)->nullable()->after('is_active');
            $table->timestamp('portal_status_changed_at')->nullable()->after('portal_status');
            $table->timestamp('off_market_at')->nullable()->after('portal_status_changed_at');
            $table->index('portal_status');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex(['portal_status']);
            $table->dropColumn(['portal_status', 'portal_status_changed_at', 'off_market_at']);
        });
    }
};

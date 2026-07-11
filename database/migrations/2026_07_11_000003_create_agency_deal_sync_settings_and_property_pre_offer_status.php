<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DR2 Wave 2 — "Deal → Property → Portal status sync" (Johan's design, m4's log).
 *
 * Cross-pillar reactivity (Deal event → Property status → portals via existing
 * syndication). Agency-configurable, OFF by default (conservative rollout), audit-
 * logged (PropertyObserver already logs status transitions), no hard deletes.
 *
 *   (a) deal created with a linked property → auto-flag the property UNDER OFFER
 *       (existing settled status — never invents statuses).  [default OFF]
 *   (b) which milestone marks SOLD on portals — commission GRANTED vs REGISTERED.
 *       [default OFF = null]
 *   (c) deal declined/lapsed → property auto-reverts to its prior on-market status.
 *       [default ON — the safety companion]
 *
 * `properties.pre_deal_offer_status` remembers the on-market status the property held
 * BEFORE it was flagged under-offer, so (c) can restore it exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agency_deal_sync_settings')) {
            Schema::create('agency_deal_sync_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('agency_id')->unique();
                // (a) OFF by default.
                $table->boolean('flag_property_under_offer_on_deal')->default(false);
                // (b) null = OFF; 'granted' = commission Granted; 'registered' = Registered.
                $table->enum('sold_milestone', ['granted', 'registered'])->nullable();
                // (c) ON by default — revert on decline/lapse.
                $table->boolean('revert_property_on_deal_declined')->default(true);
                $table->timestamps();

                $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            });
        }

        if (! Schema::hasColumn('properties', 'pre_deal_offer_status')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->string('pre_deal_offer_status', 100)->nullable()->after('status')
                    ->comment('Wave 2: the on-market status held before a deal flagged this property under-offer; restored on decline/lapse.');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_deal_sync_settings');
        if (Schema::hasColumn('properties', 'pre_deal_offer_status')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->dropColumn('pre_deal_offer_status');
            });
        }
    }
};

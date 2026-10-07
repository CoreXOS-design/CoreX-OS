<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §2 / §5.1.
 *
 * `sale_method` is the ONLY new column that says "is this an auction" — a
 * value on `properties`, deliberately NOT a third `listing_type` (the spec's
 * §2 governing rule: `listing_type` stays the two-value canon `Property::
 * LISTING_TYPES` every existing call site branches on via isRental()).
 *
 * `pre_auction_status` mirrors the existing `pre_deal_offer_status` /
 * `pre_tenant_link_status` snapshot-and-restore pattern already on this
 * table: the on-market status held before the auction catalogue published,
 * restored on cancel/withdraw/passed-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('sale_method', 30)->default('private_treaty')->after('listing_type');
            $table->string('pre_auction_status', 100)->nullable()->after('pre_tenant_link_status');
        });

        Schema::table('properties', function (Blueprint $table) {
            $table->index(['agency_id', 'sale_method', 'status'], 'idx_properties_agency_sale_method');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('idx_properties_agency_sale_method');
            $table->dropColumn(['sale_method', 'pre_auction_status']);
        });
    }
};

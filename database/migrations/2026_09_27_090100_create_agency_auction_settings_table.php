<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §4 / §5.7.
 *
 * One row per agency. Every column is nullable (bar the id/agency_id/
 * timestamps) so an agency that never opens Settings → Auctions still gets
 * every sensible default from AgencyAuctionSettings' own DEFAULT_* consts —
 * copying RentalApplicationQualifyingSetting's forAgency()-never-writes-on-
 * read pattern verbatim (spec §5.7: "do not invent a second accessor style").
 *
 * Andre's three structural decisions (spec §0) drive the shape:
 *   - auctioneer_mode default 'both' (internal OR external OR asked per auction)
 *   - bidding_modes_enabled default in_room only (agency turns on online/hybrid)
 *   - fee_model default buyers_premium (agency can add/switch to commission)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_auction_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->unique()->constrained()->cascadeOnDelete();

            // §4.1 — Who runs the auction.
            $table->string('auctioneer_mode', 20)->nullable(); // internal|external|both
            $table->json('external_auctioneer_required_fields')->nullable();

            // §4.2 — Where bidding happens.
            $table->json('bidding_modes_enabled')->nullable(); // subset of in_room|online|hybrid
            $table->string('default_bidding_mode', 20)->nullable();
            $table->boolean('online_auto_extend_enabled')->nullable();
            $table->unsignedInteger('online_auto_extend_minutes')->nullable();
            $table->string('online_bid_increment_mode', 10)->nullable(); // fixed|banded
            $table->json('online_bid_increment_bands')->nullable();
            $table->boolean('proxy_bidding_enabled')->nullable();
            $table->boolean('absentee_bids_enabled')->nullable();
            $table->boolean('phone_bidding_enabled')->nullable();
            $table->boolean('bid_retraction_allowed')->nullable();

            // §4.3 — How the agency is paid.
            $table->string('fee_model', 20)->nullable(); // buyers_premium|sellers_commission|both
            $table->decimal('buyers_premium_percent', 5, 2)->nullable();
            $table->boolean('buyers_premium_vat_inclusive')->nullable();
            $table->decimal('buyers_premium_minimum', 12, 2)->nullable();
            $table->decimal('sellers_commission_percent', 5, 2)->nullable();
            $table->string('vat_rate_source', 10)->nullable(); // system|override
            $table->string('premium_payable_on', 20)->nullable(); // fall_of_hammer|confirmation|registration

            // §4.4 — Bidder registration requirements.
            $table->boolean('registration_required')->nullable();
            $table->unsignedInteger('registration_opens_days_before')->nullable();
            $table->string('registration_closes', 20)->nullable(); // at_start|hours_before
            $table->unsignedInteger('registration_closes_hours_before')->nullable();
            $table->boolean('require_fica_before_paddle')->nullable();
            $table->json('fica_document_checklist')->nullable();
            $table->boolean('registration_deposit_required')->nullable();
            $table->decimal('registration_deposit_amount', 12, 2)->nullable();
            $table->unsignedInteger('registration_deposit_refund_days')->nullable();
            $table->boolean('require_signed_rules_before_paddle')->nullable();
            $table->string('paddle_number_mode', 10)->nullable(); // sequential|manual
            $table->boolean('entity_bidders_allowed')->nullable();

            // §4.5 — Reserve, guide and confirmation.
            $table->string('reserve_visibility', 20)->nullable(); // private|disclosed_on_the_day|published
            $table->boolean('guide_price_enabled')->nullable();
            $table->boolean('confirmation_period_enabled')->nullable();
            $table->unsignedInteger('confirmation_period_days')->nullable();
            $table->text('vendor_bidding_disclosure')->nullable();

            // §4.6 — Deposit and settlement on the day.
            $table->string('purchase_deposit_mode', 10)->nullable(); // percent|fixed|none
            $table->decimal('purchase_deposit_percent', 5, 2)->nullable();
            $table->decimal('purchase_deposit_fixed_amount', 12, 2)->nullable();
            $table->unsignedInteger('purchase_deposit_due_days')->nullable();
            $table->unsignedInteger('balance_due_days')->nullable();
            $table->unsignedBigInteger('default_attorney_provider_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_auction_settings');
    }
};

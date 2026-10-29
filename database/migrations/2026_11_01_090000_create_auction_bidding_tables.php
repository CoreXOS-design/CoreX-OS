<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2 phase 3 (.ai/specs/auctions.md §4.5-4.8): bidder registration, the documents behind it, bids,
 * auto-bids and the watchlist. Explicit `au2_` foreign-key names (constraint names are unique per schema and the
 * retired legacy_* tables still hold the old auto-generated ones).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_registrations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->unsignedBigInteger('client_user_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->string('bidder_type', 12)->default('individual');          // individual | company
            $t->string('company_name', 190)->nullable();
            $t->string('company_reg_no', 60)->nullable();
            $t->unsignedInteger('bidder_number')->nullable();              // issued on approval, unique per auction
            $t->string('status', 12)->default('pending');                  // pending | approved | declined | withdrawn
            $t->string('declined_reason', 300)->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->dateTime('accepted_conditions_at')->nullable();
            $t->string('accepted_conditions_ip', 45)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['auction_id', 'status'], 'au2_reg_auction_ix');
            $t->index(['client_user_id', 'auction_id'], 'au2_reg_client_ix');
            $t->index(['auction_id', 'bidder_number'], 'au2_reg_number_ix');
            $t->foreign('agency_id', 'au2_reg_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_reg_auction_fk')->references('id')->on('auctions');
            $t->foreign('client_user_id', 'au2_reg_client_fk')->references('id')->on('client_users');
        });

        // Documents a buyer has on file WITH ONE AGENCY ("use existing"). Per agency, not global: one agency never gets
        // another agency's copy of somebody's ID document.
        Schema::create('bidder_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('client_user_id');
            $t->string('kind', 30);                                        // id_or_passport | proof_of_address | company_resolution | other_fica
            $t->string('original_name', 255);
            $t->string('path', 500);
            $t->string('mime_type', 100)->nullable();
            $t->unsignedInteger('size')->default(0);
            $t->date('expires_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['agency_id', 'client_user_id', 'kind'], 'au2_bdoc_ix');
            $t->foreign('agency_id', 'au2_bdoc_agency_fk')->references('id')->on('agencies');
            $t->foreign('client_user_id', 'au2_bdoc_client_fk')->references('id')->on('client_users');
        });

        Schema::create('auction_registration_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('registration_id');
            $t->unsignedBigInteger('bidder_document_id');
            $t->string('kind', 30);
            $t->string('status', 12)->default('submitted');                // submitted | accepted | rejected
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->dateTime('reviewed_at')->nullable();
            $t->string('reject_reason', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('registration_id', 'au2_regdoc_reg_ix');
            $t->foreign('agency_id', 'au2_regdoc_agency_fk')->references('id')->on('agencies');
            $t->foreign('registration_id', 'au2_regdoc_reg_fk')->references('id')->on('auction_registrations');
            $t->foreign('bidder_document_id', 'au2_regdoc_doc_fk')->references('id')->on('bidder_documents');
        });

        Schema::create('auction_bids', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->unsignedBigInteger('lot_id');
            $t->unsignedBigInteger('registration_id');
            $t->decimal('amount', 14, 2);
            $t->dateTime('placed_at');
            $t->string('source', 12)->default('online');                   // online | auto_bid | floor
            $t->string('status', 12)->default('valid');                    // valid | retracted
            $t->unsignedBigInteger('retracted_by')->nullable();
            $t->dateTime('retracted_at')->nullable();
            $t->string('retract_reason', 300)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['lot_id', 'status', 'amount'], 'au2_bids_lot_ix');
            $t->index(['registration_id'], 'au2_bids_reg_ix');
            $t->index(['agency_id', 'placed_at'], 'au2_bids_list_ix');
            $t->foreign('agency_id', 'au2_bids_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_bids_auction_fk')->references('id')->on('auctions');
            $t->foreign('lot_id', 'au2_bids_lot_fk')->references('id')->on('auction_lots');
            $t->foreign('registration_id', 'au2_bids_reg_fk')->references('id')->on('auction_registrations');
        });

        Schema::create('auction_auto_bids', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('lot_id');
            $t->unsignedBigInteger('registration_id');
            $t->decimal('max_amount', 14, 2);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['lot_id', 'active'], 'au2_auto_lot_ix');
            $t->foreign('agency_id', 'au2_auto_agency_fk')->references('id')->on('agencies');
            $t->foreign('lot_id', 'au2_auto_lot_fk')->references('id')->on('auction_lots');
            $t->foreign('registration_id', 'au2_auto_reg_fk')->references('id')->on('auction_registrations');
        });

        Schema::create('auction_watchlist', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('lot_id');
            $t->unsignedBigInteger('client_user_id');
            $t->timestamps();
            $t->softDeletes();
            $t->index(['client_user_id', 'lot_id'], 'au2_watch_ix');
            $t->foreign('agency_id', 'au2_watch_agency_fk')->references('id')->on('agencies');
            $t->foreign('lot_id', 'au2_watch_lot_fk')->references('id')->on('auction_lots');
            $t->foreign('client_user_id', 'au2_watch_client_fk')->references('id')->on('client_users');
        });
    }

    public function down(): void
    {
        foreach (['auction_watchlist', 'auction_auto_bids', 'auction_bids', 'auction_registration_documents', 'bidder_documents', 'auction_registrations'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

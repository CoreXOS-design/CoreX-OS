<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2, phase 1 (.ai/specs/auctions.md §4.1-4.4, §4.10, §7).
 *
 * Foreign keys carry explicit `au2_` names: the retired tables (renamed to legacy_*)
 * keep their original auto-generated constraint names, and MySQL constraint names
 * are unique per schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_auction_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->string('site_title', 160)->nullable();
            $t->text('intro_text')->nullable();
            $t->string('contact_email', 190)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('reference_prefix', 12)->nullable();
            $t->json('default_increments')->nullable();
            $t->unsignedSmallInteger('soft_close_minutes')->nullable();
            $t->string('reserve_visibility', 20)->nullable();           // hidden | met_not_met
            $t->boolean('show_bid_count')->nullable();
            $t->boolean('show_bidder_numbers')->nullable();
            $t->unsignedSmallInteger('registration_closes_hours')->nullable();
            $t->json('docs_required_individual')->nullable();
            $t->json('docs_required_company')->nullable();
            $t->text('terms_text')->nullable();
            $t->string('notify_enquiry_to', 20)->nullable();            // listing_agent | branch_manager | both
            $t->boolean('outbid_emails')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique('agency_id', 'au2_settings_agency_uq');
            $t->foreign('agency_id', 'au2_settings_agency_fk')->references('id')->on('agencies');
        });

        Schema::create('auctions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('reference', 40);
            $t->string('title', 200);
            $t->text('description')->nullable();
            $t->string('cover_image_path', 500)->nullable();
            $t->string('format', 12)->default('timed');                 // timed | live
            $t->string('type', 40)->default('real_estate');
            $t->string('currency', 3)->default('ZAR');
            $t->string('status', 16)->default('draft');                 // draft published open closed completed cancelled
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->dateTime('registration_closes_at')->nullable();
            $t->string('venue_name', 200)->nullable();
            $t->string('venue_address', 300)->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('stream_url', 500)->nullable();
            $t->unsignedSmallInteger('soft_close_minutes')->nullable();
            $t->boolean('is_single_property')->default(false);          // auto-created from a property's Auction tab
            $t->dateTime('published_at')->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->string('cancel_reason', 300)->nullable();
            $t->dateTime('completed_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['agency_id', 'reference'], 'au2_auctions_ref_uq');
            $t->index(['agency_id', 'status', 'starts_at'], 'au2_auctions_list_ix');
            $t->foreign('agency_id', 'au2_auctions_agency_fk')->references('id')->on('agencies');
            $t->foreign('branch_id', 'au2_auctions_branch_fk')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::create('auction_increments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->decimal('from_amount', 14, 2);
            $t->decimal('increment', 14, 2);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['auction_id', 'from_amount'], 'au2_incr_ix');
            $t->foreign('agency_id', 'au2_incr_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_incr_auction_fk')->references('id')->on('auctions');
        });

        Schema::create('auction_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->string('kind', 40);                                     // conditions_of_sale | bidder_registration_form | offer_to_purchase | commission_breakdown | other
            $t->string('name', 200);
            $t->string('path', 500);
            $t->string('visibility', 12)->default('registered');        // public | registered
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index('auction_id', 'au2_docs_auction_ix');
            $t->foreign('agency_id', 'au2_docs_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_docs_auction_fk')->references('id')->on('auctions');
        });

        Schema::create('auction_lots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('auction_id');
            $t->unsignedBigInteger('property_id');
            $t->unsignedInteger('lot_number');
            $t->string('title', 200)->nullable();
            $t->string('reference', 60)->nullable();
            $t->string('external_ref', 60)->nullable();
            $t->text('description')->nullable();
            $t->dateTime('opens_at')->nullable();
            $t->dateTime('closes_at')->nullable();
            $t->decimal('opening_bid', 14, 2)->nullable();
            $t->decimal('reserve_price', 14, 2)->nullable();            // staff only, never serialised publicly
            $t->decimal('guide_price_min', 14, 2)->nullable();
            $t->decimal('guide_price_max', 14, 2)->nullable();
            $t->string('status', 32)->default('draft');                 // draft published open sold sold_subject_to_confirmation passed_in withdrawn
            $t->unsignedBigInteger('winning_bid_id')->nullable();       // FK arrives with auction_bids (phase 3)
            $t->decimal('hammer_price', 14, 2)->nullable();
            $t->dateTime('result_at')->nullable();
            $t->dateTime('confirmation_deadline')->nullable();
            $t->string('result_note', 300)->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['auction_id', 'lot_number'], 'au2_lots_order_ix');
            $t->index(['property_id', 'status'], 'au2_lots_property_ix');
            $t->foreign('agency_id', 'au2_lots_agency_fk')->references('id')->on('agencies');
            $t->foreign('auction_id', 'au2_lots_auction_fk')->references('id')->on('auctions');
            $t->foreign('property_id', 'au2_lots_property_fk')->references('id')->on('properties');
        });

        Schema::table('property_files', function (Blueprint $t) {
            $t->string('auction_visibility', 12)->nullable()->after('source_type');   // null = not in the auction pack | public | registered
        });
    }

    public function down(): void
    {
        Schema::table('property_files', function (Blueprint $t) {
            $t->dropColumn('auction_visibility');
        });
        foreach (['auction_lots', 'auction_documents', 'auction_increments', 'auctions', 'agency_auction_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

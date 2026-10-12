<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-450 round 2 (.ai/specs/title-deed-register.md §14):
 *  - title_deed_storage_locations: "where it is kept" becomes an agency-defined list (soft delete only).
 *  - title_deeds.storage_location_id + title_deeds.deal_id (the DR2 deal the deed came from). The old free-text
 *    `storage_location` column STAYS, untouched: existing deeds show it as "Legacy: ..." until someone picks a location.
 *  - title_deeds.listing_agent_id / additional_agent_ids: the deal's listing agent (for reference) and any further selling agents
 *    (notified on collection); title_deed_settings.notify_listing_agent (default on).
 *  - title_deed_events.opened_at / opened_by_user_id: the honest WhatsApp sequence (opened, then confirmed sent or not sent).
 *  - title_deed_signing_links (+ receipts.signed_via / signing_link_id, settings sign_by_link_enabled / signing_link_minutes): the
 *    collector can sign on their OWN phone from a single-use link, as an alternative to the on-screen pad (spec section 14.10).
 *  - title_deed_form_claims: one row per SUBMITTED form token. A form token is signed, not stored, so nothing is written
 *    until a form is actually submitted; the unique key on the token hash is what makes a re-submitted form (Back button,
 *    double click, refresh-resend) impossible to act on twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_deed_storage_locations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('name', 120);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('branch_id')->nullable();   // null = every branch of the agency
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'deleted_at', 'sort_order'], 'td_loc_agency_idx');
        });

        Schema::table('title_deeds', function (Blueprint $table) {
            $table->unsignedBigInteger('storage_location_id')->nullable()->after('storage_location');
            $table->unsignedBigInteger('deal_id')->nullable()->after('property_address');
            $table->unsignedBigInteger('listing_agent_id')->nullable()->after('agent_id');
            $table->json('additional_agent_ids')->nullable()->after('listing_agent_id');
            $table->index('deal_id', 'td_deal_idx');
            $table->index('storage_location_id', 'td_location_idx');
        });

        Schema::table('title_deed_events', function (Blueprint $table) {
            $table->timestamp('opened_at')->nullable()->after('confirmed_at');
            $table->unsignedBigInteger('opened_by_user_id')->nullable()->after('opened_at');
        });

        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->boolean('notify_listing_agent')->nullable();
            $table->boolean('sign_by_link_enabled')->nullable();
            $table->unsignedSmallInteger('signing_link_minutes')->nullable();
        });

        Schema::table('title_deed_receipts', function (Blueprint $table) {
            $table->string('signed_via', 10)->default('screen');          // screen | link
            $table->unsignedBigInteger('signing_link_id')->nullable();
        });

        Schema::create('title_deed_signing_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id');
            $table->char('token_hash', 64)->unique();
            $table->text('token_encrypted')->nullable();                  // only so the staff screen can show the QR / link while the link is live
            // waiting | signed | used | cancelled  (expired is derived from expires_at)
            $table->string('status', 12)->default('waiting');
            $table->string('collector_type', 15);                          // owner | representative
            $table->unsignedBigInteger('collector_contact_id')->nullable();
            $table->string('collector_name', 191);
            $table->string('send_email', 191)->nullable();                 // a representative's address captured at the counter
            $table->string('send_mobile', 40)->nullable();
            $table->json('snapshot');                                      // the gates already passed at the counter (method, consent, ID seen, override ...)
            $table->unsignedBigInteger('created_by_user_id');
            $table->timestamp('expires_at');
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_typed_name', 191)->nullable();
            $table->string('signature_path', 500)->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['title_deed_id', 'status'], 'td_sign_deed_idx');
            $table->foreign('title_deed_id', 'td_sign_deed_fk')->references('id')->on('title_deeds');
        });

        Schema::create('title_deed_form_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('purpose', 30);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('title_deed_id')->nullable();
            $table->unsignedBigInteger('result_deed_id')->nullable();   // create: the record this form made
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_deed_form_claims');
        Schema::dropIfExists('title_deed_signing_links');
        Schema::table('title_deed_receipts', function (Blueprint $table) {
            $table->dropColumn(['signed_via', 'signing_link_id']);
        });
        Schema::table('title_deed_settings', function (Blueprint $table) {
            $table->dropColumn(['notify_listing_agent', 'sign_by_link_enabled', 'signing_link_minutes']);
        });
        Schema::table('title_deed_events', function (Blueprint $table) {
            $table->dropColumn(['opened_at', 'opened_by_user_id']);
        });
        Schema::table('title_deeds', function (Blueprint $table) {
            $table->dropIndex('td_deal_idx');
            $table->dropIndex('td_location_idx');
            $table->dropColumn(['storage_location_id', 'deal_id', 'listing_agent_id', 'additional_agent_ids']);
        });
        Schema::dropIfExists('title_deed_storage_locations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-450 — the Title Deed Register (.ai/specs/title-deed-register.md §3). A SIBLING of the Filing Register: nothing in
 * `document_filing_register` changes.
 *
 * Soft deletes everywhere a row can be removed (no hard deletes). Every NOT NULL column without a DB default is supplied
 * by TitleDeedService on every path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('title_deeds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('agent_id');
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('property_id');
            // The address as it read when the record was saved, so an archived / deleted property still renders.
            $table->string('property_address', 255)->default('');
            $table->string('attorney_firm', 255)->nullable();
            $table->date('received_at');
            $table->string('storage_location', 255)->nullable();
            $table->text('notes')->nullable();

            // received | notified | collected  (Archived = soft delete)
            $table->string('status', 20)->default('received');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('collected_at')->nullable();
            $table->string('collected_by_name', 191)->nullable();
            $table->string('collected_method', 30)->nullable();
            $table->unsignedBigInteger('scan_document_id')->nullable();

            // The collection PIN (verified through the OTP engine; the encrypted copy exists only so "Resend PIN" can resend it).
            $table->unsignedInteger('pin_version')->default(0);
            $table->text('pin_encrypted')->nullable();
            $table->timestamp('pin_issued_at')->nullable();
            $table->unsignedInteger('pin_failed_attempts')->default(0);
            $table->timestamp('pin_locked_at')->nullable();

            $table->timestamp('last_reminder_at')->nullable();
            $table->unsignedInteger('reminder_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'status']);
            $table->index('received_at');
            $table->index('agent_id');
            $table->index('branch_id');
            $table->index('property_id');

            $table->foreign('property_id', 'td_property_fk')->references('id')->on('properties');
            $table->foreign('agent_id', 'td_agent_fk')->references('id')->on('users');
            $table->foreign('created_by', 'td_created_by_fk')->references('id')->on('users');
            $table->foreign('branch_id', 'td_branch_fk')->references('id')->on('branches');
        });

        Schema::create('title_deed_owners', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id');
            $table->unsignedBigInteger('contact_id');
            $table->string('name_snapshot', 255)->default('');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['title_deed_id', 'contact_id'], 'td_owner_unique');
            $table->index('contact_id');
            $table->foreign('title_deed_id', 'td_owner_deed_fk')->references('id')->on('title_deeds');
            $table->foreign('contact_id', 'td_owner_contact_fk')->references('id')->on('contacts');
        });

        // One row per owner per channel per send. Honest about WhatsApp: 'prepared' until the staff member confirms.
        Schema::create('title_deed_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->string('owner_name', 255)->nullable();
            // owner_notice | pin_resend | pin_new | consent_request | reminder | collected_notice
            $table->string('kind', 30);
            $table->string('channel', 20);              // email | whatsapp
            $table->string('destination_masked', 191)->nullable();
            // sent | failed | skipped | prepared
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedBigInteger('consent_id')->nullable();
            // A prepared WhatsApp for a consent request needs its link when the staff member opens it; the token is stored
            // nowhere else in the clear. Encrypted (app key) and cleared the moment the send is confirmed.
            $table->text('payload_encrypted')->nullable();
            $table->timestamps();

            $table->index(['title_deed_id', 'kind']);
            $table->foreign('title_deed_id', 'td_event_deed_fk')->references('id')->on('title_deeds');
        });

        Schema::create('title_deed_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id');
            $table->string('event', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('summary', 500)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['title_deed_id', 'created_at']);
            $table->foreign('title_deed_id', 'td_audit_deed_fk')->references('id')->on('title_deeds');
        });

        // A request for the owners' consent to a named representative collecting (link) or a phone consent staff recorded.
        Schema::create('title_deed_consents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id');
            $table->string('channel', 10);               // link | phone
            // waiting | approved | declined | cancelled | used
            $table->string('status', 15)->default('waiting');
            $table->string('rep_name', 191);
            $table->string('rep_id_number', 40);
            $table->string('rep_relationship', 100);
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('expires_at');              // link: the links stop working; phone: the unlock stops
            $table->timestamp('responded_at')->nullable();
            $table->unsignedBigInteger('responded_contact_id')->nullable();
            $table->string('responded_name', 191)->nullable();
            $table->string('responded_ip', 45)->nullable();
            $table->string('responded_user_agent', 500)->nullable();
            $table->timestamp('approved_until')->nullable();
            // phone consent only
            $table->unsignedBigInteger('called_contact_id')->nullable();
            $table->string('called_number_masked', 60)->nullable();
            $table->string('spoke_to', 191)->nullable();
            $table->timestamp('called_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['title_deed_id', 'status']);
            $table->foreign('title_deed_id', 'td_consent_deed_fk')->references('id')->on('title_deeds');
        });

        Schema::create('title_deed_consent_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('consent_id');
            $table->unsignedBigInteger('contact_id');
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamps();

            $table->index('consent_id');
            $table->foreign('consent_id', 'td_link_consent_fk')->references('id')->on('title_deed_consents');
        });

        // The handover. ONE per deed (unique) so two concurrent handovers can never both land.
        Schema::create('title_deed_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('title_deed_id')->unique();
            // pin | id_check | owner_consent | phone_consent
            $table->string('method', 20);
            $table->string('collector_type', 15);         // owner | representative
            $table->unsignedBigInteger('collector_contact_id')->nullable();
            $table->string('collector_name', 191);
            $table->string('collector_id_number', 40)->nullable();
            $table->string('collector_relationship', 100)->nullable();
            $table->string('id_number_seen', 40)->nullable();
            $table->unsignedBigInteger('consent_id')->nullable();
            $table->string('signature_path', 500)->nullable();
            $table->string('signed_typed_name', 191)->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_ip', 45)->nullable();
            $table->string('signed_user_agent', 500)->nullable();
            $table->unsignedBigInteger('recorded_by_user_id');
            $table->unsignedBigInteger('authority_letter_document_id')->nullable();
            $table->unsignedBigInteger('collector_id_photo_document_id')->nullable();
            $table->unsignedBigInteger('override_user_id')->nullable();
            $table->string('override_reason', 500)->nullable();
            $table->timestamps();

            $table->foreign('title_deed_id', 'td_receipt_deed_fk')->references('id')->on('title_deeds');
        });

        Schema::create('title_deed_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            $table->boolean('pin_required')->nullable();
            $table->boolean('signature_required')->nullable();
            $table->boolean('representatives_allowed')->nullable();
            $table->string('rep_without_pin_mode', 20)->nullable();   // refuse | owner_consent | consent_or_letter
            $table->boolean('phone_consent_allowed')->nullable();
            $table->boolean('authority_letter_required')->nullable();
            $table->boolean('collector_id_photo_required')->nullable();
            $table->unsignedSmallInteger('pin_lock_attempts')->nullable();
            $table->unsignedSmallInteger('consent_validity_minutes')->nullable();
            $table->unsignedSmallInteger('reminder_days')->nullable();
            $table->unsignedSmallInteger('max_reminders')->nullable();
            $table->unsignedSmallInteger('contact_changed_block_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('title_deed_settings');
        Schema::dropIfExists('title_deed_receipts');
        Schema::dropIfExists('title_deed_consent_links');
        Schema::dropIfExists('title_deed_consents');
        Schema::dropIfExists('title_deed_audit_log');
        Schema::dropIfExists('title_deed_events');
        Schema::dropIfExists('title_deed_owners');
        Schema::dropIfExists('title_deeds');
    }
};

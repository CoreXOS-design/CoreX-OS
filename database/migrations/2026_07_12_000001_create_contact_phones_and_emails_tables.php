<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-125 step 1 — child tables for multiple phones + emails per contact.
 *
 * A contact holds MANY phones and MANY emails (arbitrary count), each with one
 * primary per kind. `*_normalised` is the match key (computed with the SAME
 * normalisation as ContactDuplicateService: last-9 digits for phone, lower(trim)
 * for email) so the AT-122 ingestion resolver + dedup can match against ALL of a
 * contact's identifiers (wired in a later step). House pattern: BelongsToAgency
 * (agency_id) + SoftDeletes; no hard deletes.
 *
 * This step ONLY creates the tables. Backfill of the existing single
 * contacts.phone/email into primary rows + relaxing contacts.phone NOT-NULL is
 * the paired migration 2026_07_12_000002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'contact_phones_agency_fk')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts', 'id', 'contact_phones_contact_fk')->cascadeOnDelete();

            $table->string('phone', 255);                 // raw, as entered
            $table->string('phone_normalised', 32)->nullable(); // last-9 SA mobile core — the match key
            $table->string('label', 60)->nullable();      // optional: mobile/home/work
            $table->boolean('is_primary')->default(false);

            $table->timestamps();
            $table->softDeletes();

            // Within-contact dedupe + per-contact lookup.
            $table->index(['contact_id', 'phone_normalised'], 'contact_phones_contact_norm_idx');
            // Forward-looking: the AT-122/dedup resolver matches agency-wide on the key.
            $table->index(['agency_id', 'phone_normalised'], 'contact_phones_agency_norm_idx');
            $table->index(['contact_id', 'is_primary'], 'contact_phones_primary_idx');
        });

        Schema::create('contact_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'contact_emails_agency_fk')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts', 'id', 'contact_emails_contact_fk')->cascadeOnDelete();

            $table->string('email', 255);                  // raw, as entered
            $table->string('email_normalised', 255)->nullable(); // lower(trim) — the match key
            $table->string('label', 60)->nullable();
            $table->boolean('is_primary')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_id', 'email_normalised'], 'contact_emails_contact_norm_idx');
            $table->index(['agency_id', 'email_normalised'], 'contact_emails_agency_norm_idx');
            $table->index(['contact_id', 'is_primary'], 'contact_emails_primary_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_emails');
        Schema::dropIfExists('contact_phones');
    }
};

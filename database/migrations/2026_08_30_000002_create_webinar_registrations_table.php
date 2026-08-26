<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webinar registrations (AT-383) — one row per person who signed up.
 *
 * Spec: .ai/specs/webinar-registration.md §3.2
 *
 * THIS IS THE ONLY PLACE A REGISTRANT EXISTS. Johan's decision (spec §0 A5): webinar
 * registrants do NOT become Contacts. Nothing here writes to the Contact pillar, and
 * demo_access_grants.contact_id stays NULL for every grant this table issues. If that
 * decision is ever reversed, this table is the backfill source.
 *
 * UNIQUE (webinar_id, email) IS THE DEDUPE, AND IT IS A DATABASE CONSTRAINT ON
 * PURPOSE. A public form is hit by double-clicks, retries and bots; a check-then-
 * insert in PHP races itself and produces two registrations plus two access codes for
 * the same person. The index cannot lose that race.
 *
 * last_issued_at powers the 15-minute re-issue cooldown. Re-registering is a
 * legitimate act — the access code is bcrypt-only and unrecoverable, so "resend my
 * email" is impossible and re-issuing a fresh grant is the only honest way to serve
 * someone who lost theirs (spec §0 D5). The cooldown stops that being a free tap.
 *
 * ip_address / user_agent are the abuse trail: they make a burst visible after the
 * fact without gating anyone in the moment.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('webinar_registrations')) {
            return;
        }

        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('webinar_id');

            $table->string('name');
            $table->string('email');

            // NOT NULL: demo_access_grants.company_name is NOT NULL, and the company
            // is what makes a registration a sales lead rather than an address.
            $table->string('company_name');
            $table->string('phone')->nullable();

            // The MOST RECENT grant issued to this registration. Superseded grants
            // stay in demo_access_grants — that table is evidence, and rows there are
            // never removed.
            $table->unsignedBigInteger('demo_access_grant_id')->nullable();

            $table->timestamp('confirmation_sent_at')->nullable();

            // NULL = the reminder is still owed. Stamping it is what makes the
            // hourly command send exactly once.
            $table->timestamp('reminder_sent_at')->nullable();

            $table->timestamp('last_issued_at')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            $table->string('source')->default('website');

            $table->timestamps();

            $table->unique(['webinar_id', 'email'], 'webinar_registrations_person_unq');
            $table->index(['webinar_id', 'created_at'], 'webinar_registrations_listing_idx');
            $table->index('email', 'webinar_registrations_email_idx');

            $table->foreign('webinar_id', 'webinar_registrations_webinar_fk')
                  ->references('id')->on('webinars')
                  ->cascadeOnDelete();

            // nullOnDelete, not cascade: a grant row is never deleted, but if one
            // ever were, losing the REGISTRATION with it would destroy the sales
            // record of a person who really did sign up.
            $table->foreign('demo_access_grant_id', 'webinar_registrations_grant_fk')
                  ->references('id')->on('demo_access_grants')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
    }
};

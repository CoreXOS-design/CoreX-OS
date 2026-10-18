<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — who actually attended an inspection.
 *
 * Append-only, like signatures and findings: a correction writes a NEW row and marks the old one
 * `superseded_at` / `superseded_by_id`; nothing is edited in place and nothing is hard-deleted. A row
 * with `superseded_at` set and no `superseded_by_id` is a record that was withdrawn. The inspection's
 * own archive carries these rows with it (child of rental_inspections, no deleted_at of their own).
 *
 * One live row per EXPECTED party (a lease tenant, an invited landlord, the inspector) — `party_role`
 * + `party_contact_id` / `party_user_id`. `attended_as` says in what capacity they attended
 * (themselves, a representative on their behalf, …) and `attendee_name` is required whenever the person
 * in the room is not the party themselves. Anyone else who was in the room is a row with
 * `party_role = other`.
 *
 * The optional authority-letter upload from the spec's data model is deliberately NOT here: how a
 * representative's authority is evidenced waits on Johan's ruling (§45.9 Q11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->foreignId('rental_inspection_id')->constrained('rental_inspections')->cascadeOnDelete();

            $table->string('party_role', 20);                       // tenant | landlord | agent | other
            $table->unsignedBigInteger('party_contact_id')->nullable();
            $table->unsignedBigInteger('party_user_id')->nullable();   // the agent / inspector
            $table->string('attendee_name')->nullable();
            $table->string('attended_as', 20)->default('self');     // self | representative | co_occupant | other
            $table->string('represents_party_role', 20)->nullable();
            $table->string('outcome', 20);                          // attended | did_not_attend
            $table->time('arrived_at')->nullable();
            $table->text('note')->nullable();

            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('recorded_at')->nullable();
            $table->string('client_idempotency_key', 64)->nullable();

            $table->timestamp('superseded_at')->nullable();
            $table->unsignedBigInteger('superseded_by_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rental_inspection_id', 'superseded_at'], 'ria_inspection_live_index');
            $table->index('agency_id');
            $table->unique(['rental_inspection_id', 'client_idempotency_key'], 'ria_inspection_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_attendances');
    }
};

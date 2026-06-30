<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-132 Wave 1, Step 1 — per-thread settings store.
 *
 * A "thread" is a GROUP of communications rows sharing a thread_key, not a row of
 * its own — so a per-thread setting (Wave 1: the owner's "hide subject" toggle for
 * a sensitive-subject thread) can't live on `communications` without writing it to
 * every message and re-syncing on each new one. This table is the single source of
 * truth per (agency, contact, thread). It is the natural home for any future
 * per-thread setting (pin, mute, retention override).
 *
 * BelongsToAgency (AgencyScope) + SoftDeletes per CoreX non-negotiables. Default =
 * subject shown (a thread with no row here is not hidden). Only the owning agent /
 * communications.grant_access holder may write a row — enforced server-side in a
 * later step; this migration is the store only (no behaviour reads it yet).
 *
 * Spec: .ai/specs/at132-perthread-comms-gate.md §A, §3.3, §2 decision 1
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_thread_settings', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')
                ->constrained('contacts', 'id', 'cts_contact_fk')->cascadeOnDelete();

            // The thread this setting applies to (email References/In-Reply-To root
            // or WA chat id), scoped within the contact.
            $table->string('thread_key', 255);

            // The owner privacy control: hide the subject in the locked thread-list
            // (metadata-only fallback). Default false = subject shown.
            $table->boolean('hide_subject')->default(false);

            // Who last set it (audit / attribution). Nullable, nullOnDelete — users
            // are soft-deleted so this persists in practice.
            $table->foreignId('set_by_user_id')->nullable()
                ->constrained('users', 'id', 'cts_set_by_fk')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // One settings row per thread within a contact (one source of truth).
            $table->unique(['agency_id', 'contact_id', 'thread_key'], 'cts_agency_contact_thread_uq');
            $table->index(['agency_id', 'thread_key'], 'cts_agency_thread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_thread_settings');
    }
};

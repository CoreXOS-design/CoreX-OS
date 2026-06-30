<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-118 step 1 — the immutable POPIA evidence base for the Communications
 * Access Gate.
 *
 * Append-only forensic record of WHO did WHAT, WHEN, to WHOSE communications:
 *   request          — a non-owner asked to see a contact's threads
 *   grant            — an owner / grant_access holder authorised a request
 *   decline          — a request was refused
 *   session_expired  — a session-scoped grant ended (logout)
 *   midnight_reset    — the nightly 00:00 sweep revoked live grants
 *   ownership_transfer — Flow B moved a contact/property/comm to a successor
 *
 * Immutable by definition (model blocks update + delete). No updated_at,
 * no soft-delete — an audit trail is never edited or removed. Nullable FKs
 * use nullOnDelete to mirror the codebase convention (users/contacts/comms
 * are soft-deleted, so rows persist; the FK never fires in practice).
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.5
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_access_audit_log', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            $table->enum('event_type', [
                'request',
                'grant',
                'decline',
                'session_expired',
                'midnight_reset',
                'ownership_transfer',
            ]);

            // WHO did it (nullable: system-driven events like midnight_reset have no actor).
            $table->foreignId('actor_user_id')->nullable()
                ->constrained('users', 'id', 'comms_audit_actor_fk')->nullOnDelete();

            // The owner / grantee / successor the event concerns (nullable, context-dependent).
            $table->foreignId('subject_user_id')->nullable()
                ->constrained('users', 'id', 'comms_audit_subject_fk')->nullOnDelete();

            // Which contact's threads (nullable: ownership_transfer rows may be agent-level).
            $table->foreignId('contact_id')->nullable()
                ->constrained('contacts', 'id', 'comms_audit_contact_fk')->nullOnDelete();

            // A specific communication, when the event is thread-level (nullable).
            $table->foreignId('communication_id')->nullable()
                ->constrained('communications', 'id', 'comms_audit_comm_fk')->nullOnDelete();

            // Flexible context: which threads, decline reason, transfer scope, ip/ua, etc.
            $table->json('detail')->nullable();

            // Append-only: created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['agency_id', 'created_at'], 'comms_audit_agency_created_idx');
            $table->index('contact_id', 'comms_audit_contact_idx');
            $table->index('event_type', 'comms_audit_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_access_audit_log');
    }
};

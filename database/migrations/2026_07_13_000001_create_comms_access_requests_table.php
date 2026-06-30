<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-118 step 3 — Flow A request/grant store for the Communications Access Gate.
 *
 * A non-owner requests access to ONE contact's threads; the owning agent or a
 * communications.grant_access holder approves/declines (either/or). On approval
 * a session-scoped grant is created (bound to the requester's login session via
 * session_id) with a hard end-of-day expiry; it dies at logout (Logout listener
 * + session_id mismatch) and at the nightly 00:00 reset (comms-access:reset).
 *
 * Within-agency (BelongsToAgency / AgencyScope) — unlike AgencyAccessRequest,
 * which is intentionally cross-agency. Soft-deletes (no hard deletes).
 *
 * Spec: .ai/specs/at118-communications-access-gate.md §3.3
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comms_access_requests', function (Blueprint $table) {
            $table->id();

            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts', 'id', 'car_contact_fk');
            $table->foreignId('requester_user_id')->constrained('users', 'id', 'car_requester_fk');

            // pending → approved | declined | expired | revoked
            $table->string('status', 20)->default('pending');

            $table->text('reason')->nullable();           // why the requester needs access
            $table->text('denial_reason')->nullable();     // why an approver declined

            $table->foreignId('authorized_by_user_id')->nullable()
                ->constrained('users', 'id', 'car_authorizer_fk')->nullOnDelete();
            $table->timestamp('authorized_at')->nullable();

            $table->timestamp('expires_at');                          // pending TTL (end of day)
            $table->timestamp('granted_session_expires_at')->nullable(); // grant hard cap (end of day)

            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_reason', 40)->nullable();         // logout | midnight_reset | ...

            // The requester's login session the grant is bound to — true
            // session-scoping: a grant only opens the gate inside this session.
            $table->string('session_id', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'contact_id'], 'car_agency_contact_idx');
            $table->index(['requester_user_id', 'status'], 'car_requester_status_idx');
            $table->index('status', 'car_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comms_access_requests');
    }
};

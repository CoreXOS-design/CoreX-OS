<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-278 — the 30-day seat reinstatement lock.
 * Spec: .ai/specs/agent-seat-release-lock.md §4.1
 *
 * One row per release CYCLE. The row is CLOSED (reinstated_at set), never
 * deleted, when the person comes back — so the open row (reinstated_at IS NULL)
 * IS the lock. No denormalised copy of lock state lives on `users`: one source
 * of truth per data point.
 *
 * Deliberately NO deleted_at. This is an append-only audit record and the
 * evidence in a billing dispute — the same shape as comms_access_audit_log,
 * property_audit_log and domain_event_log. Nothing may remove a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_seat_releases', function (Blueprint $table) {
            $table->id();

            // The agency that was being billed for this seat. Carried per
            // non-negotiable #7, but the model does NOT use BelongsToAgency —
            // a System Owner must read across every agency and the AgencyScope
            // owner bypass dies once session('active_agency_id') is set.
            // Scoping is explicit at the query site. Spec §4.1.
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('released_at');
            $table->foreignId('released_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('release_reason', 20);           // deleted | deactivated

            // STORED, not derived (spec D4): changing the policy from 30 to 45
            // days must never retroactively extend a lock an agency is already
            // sitting inside.
            $table->timestamp('reinstatable_at');

            $table->timestamp('reinstated_at')->nullable();
            $table->foreignId('reinstated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reinstated_via', 20)->nullable(); // elapsed | owner_override
            $table->text('override_reason')->nullable();      // mandatory when owner_override

            $table->timestamps();

            // The hot path: "is this user locked right now?"
            $table->index(['user_id', 'reinstated_at'], 'asr_user_open_idx');
            // The churn report: "what is this agency doing to its headcount?"
            $table->index(['agency_id', 'released_at'], 'asr_agency_released_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_seat_releases');
    }
};

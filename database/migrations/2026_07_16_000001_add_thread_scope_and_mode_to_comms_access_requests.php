<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-132 Wave 1, Step 1 — make the Communications Access Gate grant THREAD-scoped.
 *
 * AT-118 issued grants per CONTACT (one grant opened every thread on the contact).
 * AT-132 narrows the grant to a single thread. This migration only adds the
 * columns + indexes; the gate logic that reads them lands in Step 2. Everything
 * here is ADDITIVE + NULLABLE so existing rows keep behaving exactly as today:
 *
 *   - thread_key       NULL  → not yet thread-scoped (legacy whole-contact grant)
 *   - communication_id NULL  → not keyed on a null-thread comm
 *   - grant_mode       'session' default → existing rows stay session-scoped
 *                              (end-of-day cap + midnight reset + logout revoke)
 *
 * Live comms_access_requests = 0 rows; staging = 2 (both approved). The 2 staging
 * rows pick up thread_key=NULL, communication_id=NULL, grant_mode='session' and
 * remain contact-level session grants until Step 2 rewires the gate.
 *
 * 'otp' is RESERVED for Wave 2 (AT-130 break-glass) — it is deliberately NOT a
 * usable value yet (no CHECK constraint pins the column, so Wave 2 adds it without
 * a schema change; application code in Steps 2-6 only ever writes 'session'|'always').
 *
 * Spec: .ai/specs/at132-perthread-comms-gate.md §A, §3.1, §3.2
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comms_access_requests', function (Blueprint $table) {
            // The thread this grant is scoped to (email References/In-Reply-To root
            // or WA chat id). NULL = not thread-scoped (legacy whole-contact grant,
            // or a null-thread comm keyed via communication_id below).
            $table->string('thread_key', 255)->nullable()->after('contact_id');

            // Null-thread keying (AT-132 §2 decision 2): comms with NULL/empty
            // thread_key can't be grouped, so the grant keys on the specific comm.
            $table->foreignId('communication_id')->nullable()->after('thread_key')
                ->constrained('communications', 'id', 'car_communication_fk')->nullOnDelete();

            // session = current behaviour (end-of-day cap, midnight reset, logout
            // revoke). always = permanent for that thread (skipped by the resets in
            // Step 2). 'otp' reserved for Wave 2 — not written by Wave 1 code.
            $table->string('grant_mode', 20)->default('session')->after('status');

            // Gate lookups (Step 2): "does this requester hold a live grant for THIS
            // thread / THIS null-thread comm?"
            $table->index(['requester_user_id', 'thread_key'], 'car_requester_thread_idx');
            $table->index(['requester_user_id', 'communication_id'], 'car_requester_comm_idx');
        });
    }

    public function down(): void
    {
        Schema::table('comms_access_requests', function (Blueprint $table) {
            $table->dropIndex('car_requester_thread_idx');
            $table->dropIndex('car_requester_comm_idx');
            $table->dropForeign('car_communication_fk');
            $table->dropColumn(['thread_key', 'communication_id', 'grant_mode']);
        });
    }
};

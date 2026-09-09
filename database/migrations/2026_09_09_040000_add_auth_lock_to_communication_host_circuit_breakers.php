<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-09 (Johan, via conductor) — Afrihost's unblock came with an
 * absolute, non-negotiable condition: "no more than 3 failed login attempts
 * ... the existing connection and security thresholds cannot be changed."
 * The circuit breaker built the night before (previous migration) is tuned
 * for a DIFFERENT shape of problem — a host that is slow or flaky, tripping
 * on a PERCENTAGE of mailboxes failing over a time window. That is the wrong
 * shape for a hard, tiny, absolute count: by the time a percentage-based
 * breaker would trip, the 3-attempt budget is already spent.
 *
 * This is a SEPARATE mechanism, deliberately not reusing state/opened_at:
 *  - auth_failure_count  — atomic count of AUTHENTICATION failures only
 *    (never connect/timeout/TLS — see HostCircuitBreaker::recordAuthFailureIfApplicable),
 *    summed across every mailbox that shares this host. Incremented via
 *    ->increment() specifically to avoid a lost-update race: two workers
 *    each reading count=1 and writing count=2 would silently drop an attempt
 *    that actually happened, which is exactly the one thing this budget
 *    cannot tolerate.
 *  - auth_locked_at      — set the moment the count reaches the trip
 *    threshold (HostCircuitBreaker::AUTH_FAILURE_LOCK_THRESHOLD, hard-coded
 *    at 2 — never agency-configurable, since raising it would erode the
 *    margin Johan explicitly asked for below Afrihost's real limit of 3).
 *    Once set, NOTHING in this codebase clears it automatically — no probe,
 *    no successful poll, no successful Test Connection on a different
 *    mailbox. Only a human, via the explicit "reset login lock" action,
 *    clears it. This is what "does not self-heal" means structurally.
 *
 * Deliberately does NOT touch communication_mailboxes.poll_disabled_at or
 * .next_poll_earliest_at — those already exist (previous migration) for the
 * MAILBOX-level "stop retrying this one" signal, which MailboxHealthRecorder
 * now also fires immediately (not after a threshold) for an auth_failed
 * reason specifically. Host-level lock and mailbox-level disable are two
 * different signals answering two different questions ("can ANYONE on this
 * host attempt a real login right now" vs "should THIS mailbox be
 * retried") and must never be conflated.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('communication_host_circuit_breakers', function (Blueprint $table) {
            $table->unsignedInteger('auth_failure_count')->default(0)->after('consecutive_probe_failures');
            $table->timestamp('auth_locked_at')->nullable()->after('auth_failure_count');
        });
    }

    public function down(): void
    {
        Schema::table('communication_host_circuit_breakers', function (Blueprint $table) {
            $table->dropColumn(['auth_failure_count', 'auth_locked_at']);
        });
    }
};

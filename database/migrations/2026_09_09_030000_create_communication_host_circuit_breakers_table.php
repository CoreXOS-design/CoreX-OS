<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-09-08/09 (Johan, circuit breaker) — "this is why today ran six hours
 * instead of ten minutes." Back-off (previous migration) paces ONE mailbox's
 * own retries; it does nothing when MANY mailboxes share a host that is
 * itself refusing every connection (a provider-side IP block). Twenty
 * mailboxes each independently backing off still adds up to twenty real
 * connection attempts to a blocked host every cycle — the standard circuit-
 * breaker pattern (closed -> open on threshold failures -> single-probe
 * half-open -> closed again on success) is what actually stops that: when a
 * HOST looks broken, stop sending it traffic at all except one occasional
 * probe, rather than reasoning about each mailbox independently.
 *
 * Scoped by host string, not by agency or mailbox — the failure mode
 * (a provider blocking our server's IP) is a property of the HOST, and two
 * agencies sharing a host must share one breaker or the exact problem this
 * fixes remains possible one agency's mailboxes at a time.
 *
 *  - host                        the mail server host this breaker guards
 *                                (communication_mailboxes.imap_host).
 *  - state                       'closed' (normal) or 'open' (stopped
 *                                sending traffic except probes).
 *  - opened_at                   when this OPEN episode started; null when
 *                                closed. Also the "one alert per episode"
 *                                dedup key, same pattern as
 *                                communication_mailboxes.failure_notified_at.
 *  - last_probe_at               last time a single-mailbox probe was sent
 *                                while open; paces the re-probe interval.
 *  - consecutive_probe_failures  probes that failed since opening; purely
 *                                informational (state stays 'open' either
 *                                way until a probe succeeds).
 *  - agencies.communication_circuit_breaker_min_mailboxes            do not
 *    trip for a lone mailbox having a bad day (NULL -> config default).
 *  - agencies.communication_circuit_breaker_failure_threshold_percent
 *    fraction of that host's active mailboxes that must show a recent
 *    connect-class failure to open the breaker (NULL -> config default).
 *  - agencies.communication_circuit_breaker_probe_interval_minutes   how
 *    often, while open, a single mailbox is allowed to re-probe the host
 *    (NULL -> config default).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('communication_host_circuit_breakers', function (Blueprint $table) {
            $table->id();
            $table->string('host', 255)->unique();
            $table->string('state', 20)->default('closed');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->unsignedInteger('consecutive_probe_failures')->default(0);
            $table->timestamps();
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'communication_circuit_breaker_min_mailboxes')) {
                $table->unsignedTinyInteger('communication_circuit_breaker_min_mailboxes')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_circuit_breaker_failure_threshold_percent')) {
                $table->unsignedTinyInteger('communication_circuit_breaker_failure_threshold_percent')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_circuit_breaker_probe_interval_minutes')) {
                $table->unsignedInteger('communication_circuit_breaker_probe_interval_minutes')->nullable();
            }
            if (!Schema::hasColumn('agencies', 'communication_circuit_breaker_lookback_minutes')) {
                $table->unsignedInteger('communication_circuit_breaker_lookback_minutes')->nullable();
            }
        });

        // CLAUDE.md non-negotiable #9a/deploy rule (AT-162) — a must-travel
        // GLOBAL reference row, backfilled here rather than left for
        // deploy:sync-reference-data, so the alert works the moment this
        // migration runs anywhere, QA1 included, with zero extra step.
        DB::table('notification_event_types')->insertOrIgnore([
            'key' => 'comms.host_circuit_breaker_open',
            'pillar' => 'agent',
            'group_label' => 'Communications',
            'label' => 'A mail host is refusing connections',
            'description' => 'Most or all mailboxes on one mail server are failing to connect — the circuit breaker has stopped polling that host to avoid making the problem worse (e.g. a provider-side IP block).',
            'default_enabled' => 1,
            'threshold_unit' => 'none',
            'supports_in_app' => 1,
            'supports_email' => 0,
            'supports_push' => 0,
            'is_adapter' => 0,
            'sort_order' => 30,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_host_circuit_breakers');

        Schema::table('agencies', function (Blueprint $table) {
            foreach ([
                'communication_circuit_breaker_min_mailboxes',
                'communication_circuit_breaker_failure_threshold_percent',
                'communication_circuit_breaker_probe_interval_minutes',
                'communication_circuit_breaker_lookback_minutes',
            ] as $col) {
                if (Schema::hasColumn('agencies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        DB::table('notification_event_types')->where('key', 'comms.host_circuit_breaker_open')->delete();
    }
};

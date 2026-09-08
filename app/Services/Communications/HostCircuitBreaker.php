<?php

declare(strict_types=1);

namespace App\Services\Communications;

use App\Models\Agency;
use App\Models\Communications\CommunicationHostCircuitBreaker;
use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\Communications\HostCircuitBreakerOpenNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * 2026-09-08/09 (Johan) — "this is why today ran six hours instead of ten
 * minutes." Standard circuit-breaker pattern: closed (normal) -> open (stop
 * sending traffic) on a concentrated failure rate -> a single probe while
 * open -> closed again the moment that probe succeeds. See the creating
 * migration's docblock for the full rationale — this is the piece that
 * protects a HOST from twenty mailboxes each independently backing off but
 * still adding up to twenty real connection attempts every cycle.
 *
 * Scoped by host string, never by agency or mailbox.
 */
class HostCircuitBreaker
{
    /** Reasons that mean "could not even connect/authenticate" — the shape a provider-side IP block produces. */
    private const CONNECT_CLASS_FAILURES = ['connect_failed', 'auth_failed', 'connect_timeout'];

    public function isOpen(string $host): bool
    {
        return $this->breaker($host)->isOpen();
    }

    /**
     * Decide whether THIS host should open, based on its currently active
     * mailboxes' own recorded health (already updated by whichever polls
     * completed since the last cycle — this does not poll anything itself).
     * Call once per host per scheduler tick, BEFORE deciding what to dispatch.
     * A no-op if the host is already open (opening again would reset
     * opened_at and re-alert for a episode that never actually closed).
     */
    public function evaluate(string $host, Collection $activeMailboxesOnHost): void
    {
        $breaker = $this->breaker($host);
        if ($breaker->isOpen()) {
            return; // already open -- stays open until a probe succeeds
        }

        $minMailboxes = $this->minMailboxes($activeMailboxesOnHost);
        if ($activeMailboxesOnHost->count() < $minMailboxes) {
            return; // too few mailboxes on this host to trust a percentage
        }

        $lookback = $this->lookbackMinutes($activeMailboxesOnHost);
        $failing = $activeMailboxesOnHost->filter(function (CommunicationMailbox $m) use ($lookback) {
            return in_array($m->last_error, self::CONNECT_CLASS_FAILURES, true)
                && $m->last_error_at !== null
                && $m->last_error_at->gte(now()->subMinutes($lookback));
        });

        $percentFailing = (int) round(($failing->count() / $activeMailboxesOnHost->count()) * 100);
        $threshold = $this->failureThresholdPercent($activeMailboxesOnHost);

        if ($percentFailing < $threshold) {
            return;
        }

        Log::error("Communication archive: circuit breaker OPENED for host {$host} — {$failing->count()}/{$activeMailboxesOnHost->count()} mailboxes ({$percentFailing}%) failing to connect within the last {$lookback} minutes. Polling stopped for this host except an occasional single-mailbox probe.");

        $breaker->forceFill(['state' => CommunicationHostCircuitBreaker::STATE_OPEN, 'opened_at' => now(), 'consecutive_probe_failures' => 0])->save();

        $this->notify($host, $failing->count(), $activeMailboxesOnHost);
    }

    /**
     * While a host's breaker is open, is THIS mailbox the one allowed through
     * as the periodic probe? Exactly one mailbox at a time, paced by the
     * probe interval — never the full fleet.
     */
    public function isAllowedProbe(CommunicationMailbox $mailbox): bool
    {
        $breaker = $this->breaker(strtolower(trim((string) $mailbox->imap_host)));
        if (!$breaker->isOpen()) {
            return false;
        }

        return $breaker->last_probe_at === null
            || $breaker->last_probe_at->lte(now()->subMinutes($this->probeIntervalMinutes($mailbox)));
    }

    /** Mark that a probe is being sent NOW, so a second one is not dispatched before the interval elapses. */
    public function markProbeSent(string $host): void
    {
        $this->breaker($host)->forceFill(['last_probe_at' => now()])->save();
    }

    /**
     * Called after ANY poll completes, for whichever host it targeted. A
     * no-op unless that host's breaker is currently open (a closed-breaker
     * host's successes/failures are handled by evaluate() instead, in batch,
     * on the next scheduler tick — this method is only the reactive
     * open-breaker half of the pattern).
     *
     * $provedConnectable means the poll got PAST connect+auth — true for a
     * genuine success AND for a read_timeout (auth worked, the READ was just
     * slow), false only for a connect-class failure. The caller (PollMailboxJob)
     * makes that classification from the poll() result, since this service
     * should not need to know poll()'s return shape.
     */
    public function recordPollOutcome(string $host, bool $provedConnectable): void
    {
        $breaker = $this->breaker($host);
        if (!$breaker->isOpen()) {
            return;
        }

        if ($provedConnectable) {
            Log::info("Communication archive: circuit breaker CLOSED for host {$host} — a probe connected successfully.");
            $breaker->forceFill(['state' => CommunicationHostCircuitBreaker::STATE_CLOSED, 'opened_at' => null, 'consecutive_probe_failures' => 0])->save();
            return;
        }

        $breaker->forceFill(['consecutive_probe_failures' => $breaker->consecutive_probe_failures + 1])->save();
    }

    private function breaker(string $host): CommunicationHostCircuitBreaker
    {
        $host = strtolower(trim($host));

        return CommunicationHostCircuitBreaker::firstOrCreate(['host' => $host], ['state' => CommunicationHostCircuitBreaker::STATE_CLOSED]);
    }

    private function notify(string $host, int $affectedCount, Collection $mailboxesOnHost): void
    {
        try {
            $agencyIds = $mailboxesOnHost->pluck('agency_id')->unique();
            $recipients = User::withoutGlobalScope(AgencyScope::class)
                ->whereIn('agency_id', $agencyIds)
                ->whereIn('role', ['super_admin', 'admin', 'owner'])
                ->get();

            $gateway = app(NotificationDispatcher::class);
            foreach ($recipients as $recipient) {
                $gateway->send(
                    $recipient,
                    'comms.host_circuit_breaker_open',
                    $mailboxesOnHost->first(),
                    new HostCircuitBreakerOpenNotification($host, $affectedCount),
                    ['threshold_hit_at' => now()],
                );
            }
        } catch (\Throwable $e) {
            // An alert failure must never break the poll cycle.
            Log::error("Circuit breaker alert failed (host {$host}): {$e->getMessage()}");
        }
    }

    /**
     * Thresholds are per-agency settings, but a breaker is scoped to a HOST
     * that could in principle be shared by multiple agencies. Using the
     * MINIMUM configured value among the agencies actually on this host is
     * deliberately the most conservative choice — the shared resource this
     * protects trips at whichever affected agency wants the most protection,
     * not the least.
     */
    private function minMailboxes(Collection $mailboxes): int
    {
        return $this->minAcrossAgencies($mailboxes, 'communication_circuit_breaker_min_mailboxes', 'circuit_breaker_min_mailboxes', 1, 50, 3);
    }

    private function failureThresholdPercent(Collection $mailboxes): int
    {
        return $this->minAcrossAgencies($mailboxes, 'communication_circuit_breaker_failure_threshold_percent', 'circuit_breaker_failure_threshold_percent', 10, 100, 80);
    }

    private function probeIntervalMinutes(CommunicationMailbox $mailbox): int
    {
        $override = $mailbox->agency?->communication_circuit_breaker_probe_interval_minutes;
        $n = (int) ($override ?? config('communications.circuit_breaker_probe_interval_minutes', 60));

        return max(5, min(1440, $n ?: 60));
    }

    private function lookbackMinutes(Collection $mailboxes): int
    {
        return $this->minAcrossAgencies($mailboxes, 'communication_circuit_breaker_lookback_minutes', 'circuit_breaker_lookback_minutes', 1, 1440, 15);
    }

    private function minAcrossAgencies(Collection $mailboxes, string $agencyColumn, string $configKey, int $min, int $max, int $default): int
    {
        $agencyIds = $mailboxes->pluck('agency_id')->unique();
        $overrides = Agency::whereIn('id', $agencyIds)->pluck($agencyColumn)->filter(fn ($v) => $v !== null);

        $configDefault = (int) config("communications.{$configKey}", $default);
        $value = $overrides->isEmpty() ? $configDefault : (int) $overrides->min();

        return max($min, min($max, $value ?: $default));
    }
}

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

    /**
     * 2026-09-09 (Johan, real-attempt-honesty incident) — reasons that PROVE
     * no real authenticating connection was ever opened to the host. Thrown
     * before any socket is touched (empty credentials, or a local Transport
     * object failing to construct) or a deliberate skip (outbound guard on).
     * The ONLY reasons that never count, because there is no attempt to count.
     */
    private const NO_REAL_ATTEMPT_REASONS = ['incomplete_credentials', 'intercepted'];

    /**
     * Reasons that PROVE the login itself succeeded — the failure happened
     * strictly AFTER authentication. Counting these as a login failure would
     * be wrong in the other direction: it would fail-closed a mailbox whose
     * credentials are actually fine. 'send_rejected' = SMTP accepted AUTH and
     * only refused the message/recipient; 'no_sent_folder'/'append_failed' =
     * IMAP LOGIN succeeded and only the folder step failed.
     */
    private const LOGIN_PROVEN_SUCCESSFUL_REASONS = ['send_rejected', 'no_sent_folder', 'append_failed'];

    /**
     * 2026-09-09 (Johan, auth-lock safeguard) — Afrihost's absolute condition
     * is "no more than 3 failed login attempts", full stop, and "the
     * existing ... thresholds cannot be changed." Trip our OWN internal
     * budget at 2, not 3 — "leave ourselves margin; do not sit on the line."
     * Deliberately a hard-coded constant, NOT agency-configurable: unlike
     * the general breaker's percentage/lookback settings (safe to loosen for
     * a merely slow-or-flaky host), raising this number trades away the one
     * thing standing between us and a second ban. No setting exists that
     * could accidentally raise it.
     */
    public const AUTH_FAILURE_LOCK_THRESHOLD = 2;

    /** The REAL provider-stated ceiling, shown on screen for context only — never used as our own trip point. */
    public const KNOWN_PROVIDER_LOGIN_LIMIT = 3;

    public function isOpen(string $host): bool
    {
        return $this->breaker($host)->isOpen();
    }

    /**
     * 2026-09-09 (Johan, auth-lock safeguard) — true once this host's
     * authentication-failure budget has tripped. Distinct from isOpen(): the
     * general breaker still allows a single paced probe while open; an auth
     * lock allows NOTHING through — no probe, no Test Connection — until a
     * human explicitly clears it (see resetAuthLock()). Checked by every
     * real-connection call site (the poll job, all three Test Connection
     * controllers) BEFORE making any attempt.
     */
    public function isAuthLocked(string $host): bool
    {
        return $this->breaker($host)->isAuthLocked();
    }

    /**
     * Call once, immediately after any REAL connect/login attempt that
     * failed, for whichever host that attempt actually targeted (see
     * hostsFor()).
     *
     * 2026-09-09 (Johan, real-attempt-honesty incident) — REWRITTEN after a
     * real Afrihost 535 ("535 Incorrect authentication data") was classified
     * as 'unknown' by MailFailureClassifier (its phrase list didn't recognise
     * Afrihost's exact wording) and this method's old `$reason !== 'auth_failed'`
     * check silently discarded it — one genuine failed login against a host
     * with a hard external cap of 3 was never counted at all. Afrihost does
     * not care whether OUR classifier recognised its rejection; it counted
     * the login failure regardless. This method must be equally unforgiving.
     *
     * The rule is now a BLOCKLIST, not an allowlist: every reason counts
     * UNLESS it is in one of the two sets that PROVE no real attempt is being
     * under-counted — NO_REAL_ATTEMPT_REASONS (no socket was ever opened, so
     * there is nothing to count) or LOGIN_PROVEN_SUCCESSFUL_REASONS (the
     * login itself is proven to have succeeded, so counting it would
     * fail-closed a mailbox with perfectly good credentials). Anything else —
     * 'auth_failed', 'connect_failed', 'tls_failed', 'unknown', or any reason
     * string not yet invented — counts. Assume the worst, never the best: an
     * unrecognised failure against a host with a hard external ban threshold
     * is treated as a spent login attempt, not given the benefit of the doubt.
     *
     * Uses an atomic ->increment() rather than read-modify-write: two queue
     * workers (or a worker racing a human's Test Connection click) each
     * reading count=1 and separately writing count=2 would silently drop one
     * real attempt from the count — exactly the one thing this budget cannot
     * tolerate, since the external limit it protects is absolute.
     */
    public function recordAuthFailureIfApplicable(string $host, ?string $reason): void
    {
        if ($reason === null || in_array($reason, self::NO_REAL_ATTEMPT_REASONS, true) || in_array($reason, self::LOGIN_PROVEN_SUCCESSFUL_REASONS, true)) {
            return;
        }

        $breaker = $this->breaker($host);
        if ($breaker->isAuthLocked()) {
            return; // already locked -- nothing further to count or re-trip
        }

        $breaker->increment('auth_failure_count');
        $breaker->refresh();

        if ($breaker->auth_failure_count >= self::AUTH_FAILURE_LOCK_THRESHOLD) {
            Log::error("Communication archive: AUTH LOCK tripped for host {$host} — {$breaker->auth_failure_count} authentication failure(s) recorded, at or above our internal limit of " . self::AUTH_FAILURE_LOCK_THRESHOLD . ' out of the provider\'s stated ' . self::KNOWN_PROVIDER_LOGIN_LIMIT . '. ALL further real connection attempts to this host (polling and Test Connection) are refused until a human clears this.');
            $breaker->forceFill(['auth_locked_at' => now()])->save();
        }
    }

    /**
     * 2026-09-09 (Johan, auth-lock safeguard) — "an auth-tripped breaker
     * requires a human to clear it." The ONLY place auth_locked_at is ever
     * cleared. Deliberately NOT called by any automatic success path (a
     * successful poll or Test Connection on one mailbox does not prove every
     * OTHER mailbox on this shared host has good credentials) — a human must
     * make this call deliberately, typically after confirming credentials
     * were fixed at the host.
     */
    public function resetAuthLock(string $host): void
    {
        $this->breaker($host)->forceFill(['auth_locked_at' => null, 'auth_failure_count' => 0])->save();
    }

    /** Plain-English "N of 3 used" label for the mailbox screen — Johan: "Johan must be able to see it before he clicks anything." */
    public function authBudgetLabel(string $host): string
    {
        $breaker = $this->breaker($host);

        return "{$breaker->auth_failure_count} of " . self::KNOWN_PROVIDER_LOGIN_LIMIT . ' login failures used';
    }

    /** Raw count, for callers that need the number itself (e.g. deciding whether to show a budget row at all). */
    public function authFailureCount(string $host): int
    {
        return (int) $this->breaker($host)->auth_failure_count;
    }

    /**
     * Every distinct host a Test Connection click will actually contact.
     *
     * 2026-09-09 — deliberately NOT conditioned on outgoing_enabled, unlike
     * MailboxConnectionRateLimiter::hostsFor() (which this otherwise
     * mirrors): PerMailboxMailTransportBuilder::send() attempts a real SMTP
     * login whenever smtp_host/username/password are populated, regardless
     * of that flag — Test Connection's leg 1 is unconditional (see
     * CommunicationMailboxController::testConnection()). Gating this on
     * outgoing_enabled would let a real login through to an auth-locked
     * smtp_host on any mailbox that has outgoing configured but not yet
     * flagged "enabled" — exactly the gap this absolute budget cannot afford.
     */
    public function hostsFor(CommunicationMailbox $mailbox): array
    {
        $hosts = [strtolower(trim((string) $mailbox->imap_host))];

        if ($mailbox->smtp_host) {
            $smtpHost = strtolower(trim((string) $mailbox->smtp_host));
            if ($smtpHost !== '' && $smtpHost !== $hosts[0]) {
                $hosts[] = $smtpHost;
            }
        }

        return array_values(array_filter($hosts, fn ($h) => $h !== ''));
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

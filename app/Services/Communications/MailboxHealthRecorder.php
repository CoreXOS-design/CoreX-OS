<?php

namespace App\Services\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\Communications\MailboxPollFailureNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * AT-181 — records the health outcome of a mailbox poll and raises the admin alert.
 *
 * Split out of {@see ImapMailboxPoller} so the health/alert semantics are unit-testable without
 * an IMAP server. The poller decides success vs failure (and the failure reason); this class
 * owns the persistence + the episode-based notification:
 *
 *  - recordSuccess() clears the failure state and ends any open alert episode.
 *  - recordFailure() stores the sanitized reason + timestamp, increments the streak, and NEVER
 *    stamps last_polled_at (its only-advances-on-success semantics is the truth signal).
 *  - the admin alert fires ONCE when the streak first reaches the agency threshold; a marker
 *    (failure_notified_at) is set then and cleared on recovery, so one episode = one alert.
 */
class MailboxHealthRecorder
{
    /** A successful poll clears failure state. Only writes when something changed (no churn). */
    public function recordSuccess(CommunicationMailbox $mailbox): void
    {
        if ($mailbox->last_error === null
            && (int) $mailbox->consecutive_failures === 0
            && $mailbox->failure_notified_at === null
            && $mailbox->messages_behind_estimate === null) {
            return;
        }

        $mailbox->forceFill([
            'last_error' => null,
            'last_error_at' => null,
            'last_error_detail' => null,
            'consecutive_failures' => 0,
            'failure_notified_at' => null, // recovery ends the episode → the next failure alerts again
            // 2026-09-08 (Johan, part B) — a fully successful poll means nothing
            // is left unprocessed FOR THIS RUN; a stale "N behind" from a
            // previous cut-off run must not linger once the mailbox has
            // genuinely caught up.
            'messages_behind_estimate' => null,
            // 2026-09-08/09 (Johan, back-off on failure) — a genuine success
            // resets the back-off clock and un-disables the mailbox. "A
            // successful poll or test resets the counter and the back-off."
            'next_poll_earliest_at' => null,
            'poll_disabled_at' => null,
        ])->save();
    }

    /**
     * Record a failed poll, alert if the streak first reaches the alert threshold,
     * and back off the NEXT attempt exponentially. After the disable threshold,
     * polling STOPS entirely (PollMailboxes::isDue() excludes a disabled mailbox)
     * until a human intervenes or a successful Test Connection clears it — see
     * resetBackoffOnManualSuccess().
     *
     * 2026-09-08/09 (Johan) — this is what closes the six-hour tail of today's
     * incident: consecutive_failures existed before today but was only ever
     * recorded, never used to slow anything down, so a mailbox that failed
     * every cycle was retried at the SAME fixed cadence forever.
     *
     * 2026-09-09 (Johan, auth-lock safeguard) — 'auth_failed' is TERMINAL,
     * not a retry candidate: "on the FIRST authentication failure for a
     * mailbox, stop polling that mailbox immediately. Not after three. After
     * one." The exponential back-off ladder above is the right shape for a
     * host that is slow or flaky; it is the WRONG shape for a hard, tiny,
     * external login budget — by the time back-off would have escalated to
     * disabling, several more real logins have already been attempted
     * against a limit that cannot absorb them. An auth failure therefore
     * disables on failures===1, bypassing $disableThreshold entirely, but
     * still using the SAME poll_disabled_at/next_poll_earliest_at columns
     * (and the same "a human or a successful Test Connection clears it"
     * semantics) as the generic disable below — this is not a new state,
     * just a different, immediate trigger for the one that already existed.
     *
     * 2026-09-09 (Johan, diagnostics) — $detail is the RAW server/socket
     * text behind $reason ("tell us why the server is rejecting the
     * connection") — stored verbatim alongside the classified reason so an
     * engineer can always see exactly what the server said, never just a
     * category label.
     */
    public function recordFailure(CommunicationMailbox $mailbox, string $reason, ?string $detail = null): void
    {
        $failures = ((int) $mailbox->consecutive_failures) + 1;
        $disableThreshold = $reason === 'auth_failed' ? 1 : $this->disableThreshold($mailbox);
        $alreadyDisabled = $mailbox->poll_disabled_at !== null;

        $mailbox->forceFill(array_merge([
            'last_error' => $reason,
            'last_error_at' => now(),
            'last_error_detail' => $detail,
            'consecutive_failures' => $failures,
            // 2026-09-08 (Johan, part B) — a genuine connect/auth/poll failure
            // supersedes "behind": a broken mailbox isn't behind, it's broken.
            // Showing a stale backlog count next to Failing would be a second,
            // quieter version of the exact lie this fix removes.
            'messages_behind_estimate' => null,
        ], $failures >= $disableThreshold
            ? [
                // Disabled: no further point scheduling a back-off, PollMailboxes
                // will exclude it entirely. Only stamp poll_disabled_at the FIRST
                // time the threshold is crossed, so "stopped N ago" stays honest
                // even if something calls recordFailure again before isDue()
                // catches up (e.g. a manually forced poll).
                'poll_disabled_at' => $alreadyDisabled ? $mailbox->poll_disabled_at : now(),
                'next_poll_earliest_at' => null,
            ]
            : ['next_poll_earliest_at' => now()->addSeconds($this->backoffSeconds($mailbox, $failures))]
        ))->save();

        $this->maybeNotify($mailbox, $reason, $failures);
    }

    /**
     * 2026-09-08 (Johan, part B) — a read cut short by OUR OWN time budget is not
     * a failure: connect + auth genuinely succeeded this run (that is WHY we got
     * to the read phase at all). Deliberately mirrors recordSuccess()'s failure-
     * state clearing (a mailbox that was previously failing and is now merely
     * behind has, in fact, recovered its connection) but sets the backlog
     * estimate instead of leaving it null, and — the whole point — NEVER calls
     * maybeNotify(). Alerting an admin that a working mailbox is broken is the
     * same lie in a different channel; a mailbox catching up on a backlog does
     * not belong in the same alert episode as one that cannot connect at all.
     */
    public function recordBehind(CommunicationMailbox $mailbox, ?int $messagesBehind): void
    {
        $mailbox->forceFill([
            'last_error' => null,
            'last_error_at' => null,
            'last_error_detail' => null,
            'consecutive_failures' => 0,
            'failure_notified_at' => null,
            'messages_behind_estimate' => $messagesBehind !== null ? max(0, $messagesBehind) : null,
            // 2026-09-08/09 (Johan, back-off) — auth + connect genuinely worked
            // this run, so any prior back-off/disable is stale. Same reasoning
            // as recordSuccess().
            'next_poll_earliest_at' => null,
            'poll_disabled_at' => null,
        ])->save();
    }

    /**
     * 2026-09-08/09 (Johan, back-off on failure) — "A successful poll or test
     * resets the counter and the back-off." Called by Test Connection when its
     * IMAP leg succeeds, so a mailbox a human just proved working is
     * immediately eligible for normal polling again rather than waiting out
     * whatever back-off window or disable state it was in.
     */
    public function resetBackoffOnManualSuccess(CommunicationMailbox $mailbox): void
    {
        if ($mailbox->consecutive_failures === 0 && $mailbox->next_poll_earliest_at === null && $mailbox->poll_disabled_at === null) {
            return;
        }

        $mailbox->forceFill([
            'consecutive_failures' => 0,
            'failure_notified_at' => null,
            'next_poll_earliest_at' => null,
            'poll_disabled_at' => null,
        ])->save();
    }

    /**
     * Exponential back-off for the Nth consecutive failure: base, base*2,
     * base*4, … capped at the max. Agency-overridable base/max via
     * agencies.communication_poll_backoff_base_seconds / _max_seconds.
     */
    public function backoffSeconds(CommunicationMailbox $mailbox, int $failures): int
    {
        $base = $this->backoffBaseSeconds($mailbox);
        $max = $this->backoffMaxSeconds($mailbox);
        $exponent = max(0, $failures - 1);
        // Cap the exponent itself so 2**N can never overflow into a huge int
        // before the min() below has a chance to clamp it — 30 consecutive
        // failures already implies a multi-day back-off well past any sane
        // max, so 2**30 headroom is far more than this will ever need.
        $exponent = min($exponent, 30);

        return max($base, min($max, (int) ($base * (2 ** $exponent))));
    }

    /** Agency override (agencies.communication_poll_backoff_base_seconds) ?? config default (300). Clamped [30, 3600]. */
    public function backoffBaseSeconds(CommunicationMailbox $mailbox): int
    {
        $override = \App\Models\Agency::where('id', $mailbox->agency_id)->value('communication_poll_backoff_base_seconds');
        $n = (int) ($override ?? config('communications.poll_backoff_base_seconds', 300));

        return max(30, min(3600, $n ?: 300));
    }

    /** Agency override (agencies.communication_poll_backoff_max_seconds) ?? config default (21600 = 6h). Clamped [300, 86400]. */
    public function backoffMaxSeconds(CommunicationMailbox $mailbox): int
    {
        $override = \App\Models\Agency::where('id', $mailbox->agency_id)->value('communication_poll_backoff_max_seconds');
        $n = (int) ($override ?? config('communications.poll_backoff_max_seconds', 21600));

        return max(300, min(86400, $n ?: 21600));
    }

    /** Agency override (agencies.communication_poll_disable_threshold) ?? config default (10). Clamped [2, 100]. */
    public function disableThreshold(CommunicationMailbox $mailbox): int
    {
        $override = \App\Models\Agency::where('id', $mailbox->agency_id)->value('communication_poll_disable_threshold');
        $n = (int) ($override ?? config('communications.poll_disable_threshold', 10));

        return max(2, min(100, $n ?: 10));
    }

    /**
     * Alert once per episode: only when the streak first crosses the threshold AND no alert has
     * been sent for this episode (failure_notified_at null). Setting the marker here means N+1,
     * N+2… do not re-alert; a fresh episode after recovery alerts again.
     */
    private function maybeNotify(CommunicationMailbox $mailbox, string $reason, int $failures): void
    {
        if ($failures < $this->failureAlertThreshold($mailbox) || $mailbox->failure_notified_at !== null) {
            return;
        }

        // AT-235 (S2b) — THE EPISODE IS THE FACT, so the episode marker is the dedup key.
        //
        // A failing mailbox is a PERSISTENT condition, not a discrete event: the poller
        // re-runs constantly while it is down. The guard above already alerts once per
        // episode (via failure_notified_at), but that is a FIFTH private idempotency
        // mechanism, invisible to everything else — the exact fragmentation this
        // consolidation exists to end.
        //
        // The marker is now stamped BEFORE the send and passed as threshold_hit_at, so
        // the GATEWAY dedups on the episode too. Two independent guards now agree on
        // one identity, instead of one guard that nothing else can see. Behaviourally
        // identical: the marker was previously written after the try/catch, so it was
        // set whether the send succeeded or not — moving it up changes nothing except
        // that we now have a stable key to hand the gateway.
        //
        // (A time-based key would be wrong here for the same reason it was wrong for
        // portal leads: the poll re-fires every few minutes, and now() would mint a
        // fresh key each time — the 1.9M storm's mechanism on a new surface.)
        $episodeStartedAt = now();
        $mailbox->forceFill(['failure_notified_at' => $episodeStartedAt])->save();

        try {
            $recipients = $this->notificationRecipients($mailbox);
            $gateway    = app(NotificationDispatcher::class);

            foreach ($recipients as $recipient) {
                $gateway->send(
                    $recipient,
                    'comms.mailbox_poll_failure',
                    $mailbox,
                    new MailboxPollFailureNotification($mailbox, $reason, $failures),
                    ['threshold_hit_at' => $episodeStartedAt],
                );
            }
        } catch (\Throwable $e) {
            // An alert failure must never break the poll or its retry loop.
            Log::error("Mailbox health alert failed (mailbox {$mailbox->id}): {$e->getMessage()}");
        }
    }

    /**
     * Consecutive-failure threshold before alerting: agency override
     * (agencies.communication_failure_alert_threshold) ?? config default (3). Clamped [1,50].
     */
    public function failureAlertThreshold(CommunicationMailbox $mailbox): int
    {
        $override = \App\Models\Agency::where('id', $mailbox->agency_id)->value('communication_failure_alert_threshold');
        $n = (int) ($override ?? config('communications.failure_alert_threshold', 3));

        return max(1, min(50, $n ?: 3));
    }

    /** The agency's admins/owner (fallback: the mailbox's owning user). AgencyScope off — no auth in a job. */
    private function notificationRecipients(CommunicationMailbox $mailbox): Collection
    {
        $recipients = User::withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', $mailbox->agency_id)
            ->whereIn('role', ['super_admin', 'admin', 'owner'])
            ->get();

        if ($recipients->isEmpty() && $mailbox->user_id) {
            $owner = User::withoutGlobalScope(AgencyScope::class)->find($mailbox->user_id);
            if ($owner) {
                $recipients = collect([$owner]);
            }
        }

        return $recipients;
    }
}

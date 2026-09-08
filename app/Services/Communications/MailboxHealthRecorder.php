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
            'consecutive_failures' => 0,
            'failure_notified_at' => null, // recovery ends the episode → the next failure alerts again
            // 2026-09-08 (Johan, part B) — a fully successful poll means nothing
            // is left unprocessed FOR THIS RUN; a stale "N behind" from a
            // previous cut-off run must not linger once the mailbox has
            // genuinely caught up.
            'messages_behind_estimate' => null,
        ])->save();
    }

    /** Record a failed poll and alert if the streak first reaches the threshold. */
    public function recordFailure(CommunicationMailbox $mailbox, string $reason): void
    {
        $failures = ((int) $mailbox->consecutive_failures) + 1;
        $mailbox->forceFill([
            'last_error' => $reason,
            'last_error_at' => now(),
            'consecutive_failures' => $failures,
            // 2026-09-08 (Johan, part B) — a genuine connect/auth/poll failure
            // supersedes "behind": a broken mailbox isn't behind, it's broken.
            // Showing a stale backlog count next to Failing would be a second,
            // quieter version of the exact lie this fix removes.
            'messages_behind_estimate' => null,
        ])->save();

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
            'consecutive_failures' => 0,
            'failure_notified_at' => null,
            'messages_behind_estimate' => $messagesBehind !== null ? max(0, $messagesBehind) : null,
        ])->save();
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

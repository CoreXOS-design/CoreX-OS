<?php

namespace App\Services\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\Communications\MailboxPollFailureNotification;
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
            && $mailbox->failure_notified_at === null) {
            return;
        }

        $mailbox->forceFill([
            'last_error' => null,
            'last_error_at' => null,
            'consecutive_failures' => 0,
            'failure_notified_at' => null, // recovery ends the episode → the next failure alerts again
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
        ])->save();

        $this->maybeNotify($mailbox, $reason, $failures);
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

        try {
            $recipients = $this->notificationRecipients($mailbox);
            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new MailboxPollFailureNotification($mailbox, $reason, $failures));
            }
        } catch (\Throwable $e) {
            // An alert failure must never break the poll or its retry loop.
            Log::error("Mailbox health alert failed (mailbox {$mailbox->id}): {$e->getMessage()}");
        }

        $mailbox->forceFill(['failure_notified_at' => now()])->save();
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

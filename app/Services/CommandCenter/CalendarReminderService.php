<?php

namespace App\Services\CommandCenter;

use App\Models\AgencyContactSettings;
use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarReminderLog;
use App\Models\CommandCenter\UserDashboardSetting;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Mail\CommandCenter\EventReminderMail;
use App\Notifications\EventDueReminderNotification;
use App\Services\CommandCenter\Calendar\RecurrenceExpander;
use App\Services\Push\PushNotificationService;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * AT-178 — Event reminder delivery engine.
 *
 * One method per tick: {@see processDue()} finds every reminder that has just come
 * due across ALL agencies and sends it EXACTLY ONCE per (event, user, channel,
 * offset, occurrence). Idempotency is enforced structurally by the UNIQUE index on
 * calendar_reminders_log — a duplicate insert is caught and the send is skipped, so a
 * double scheduler tick (or an overlapping run) can never double-send.
 *
 * Recurring events fire per OCCURRENCE: occurrences are computed on the fly by
 * {@see RecurrenceExpander}, never persisted, so the engine expands each recurring
 * parent over its own look-ahead window and treats every occurrence start like a
 * standalone event start. The occurrence date is the dedup discriminator
 * (occurrence_key = 'YYYYMMDD'; 'single' for non-recurring).
 *
 * Recipients are the users ON an event (owner + agent attendees with accounts) —
 * never contacts. See {@see CalendarEvent::reminderRecipients()}.
 */
class CalendarReminderService
{
    /**
     * Timing tolerance (minutes) so a per-minute tick that fires a few seconds late
     * still catches an "at time of event" (offset 0) or just-in-time reminder, while a
     * lead reminder is never sent long after the event has already started.
     */
    private const GRACE_MINUTES = 2;

    /** Snooze duration for the popup toast. */
    public const SNOOZE_MINUTES = 10;

    public function __construct(
        private RecurrenceExpander $expander,
        private PushNotificationService $push,
    ) {}

    /**
     * Process all due reminders as of $now (defaults to now()). Returns the number of
     * individual sends performed (one per user+channel+offset+occurrence).
     */
    public function processDue(?Carbon $now = null): int
    {
        $now       = $now ? $now->copy() : now();
        $lookahead = $this->maxLookaheadMinutes();
        $windowEnd = $now->copy()->addMinutes($lookahead);
        $windowStart = $now->copy()->subMinutes(self::GRACE_MINUTES);
        $sent = 0;

        // ── Non-recurring candidates ──
        // withoutGlobalScopes(AgencyScope): the engine is system-wide and must see
        // every agency's events regardless of ambient auth context (e.g. a test that
        // called actingAs()). SoftDeletes + LivePropertyScope stay applied so trashed
        // events and events on soft-deleted properties are correctly suppressed.
        $single = CalendarEvent::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('is_recurring', false)
            ->where('send_reminder', true)
            ->where('status', 'pending')
            ->whereBetween('event_date', [$windowStart, $windowEnd])
            ->get();

        foreach ($single as $event) {
            $sent += $this->processEventOccurrence($event, $event, $event->event_date, 'single', $now);
        }

        // ── Recurring parents → per-occurrence ──
        $parents = CalendarEvent::query()
            ->withoutGlobalScopes([AgencyScope::class])
            ->where('is_recurring', true)
            ->where('send_reminder', true)
            ->where('status', 'pending')
            ->where('event_date', '<=', $windowEnd)
            ->get();

        foreach ($parents as $parent) {
            $occurrences = $this->expander->expand($parent, $windowStart, $windowEnd);
            foreach ($occurrences as $occ) {
                $key = $occ->event_date->format('Ymd');
                // Recipients + reminder config live on the real parent row (occurrence
                // clones strip reminder_offsets and carry a synthetic id).
                $sent += $this->processEventOccurrence($parent, $occ, $occ->event_date, $key, $now);
            }
        }

        return $sent;
    }

    /**
     * Fire any due reminders for one concrete occurrence of an event.
     *
     * @param CalendarEvent $configEvent  the REAL row that owns reminder config + recipients (parent)
     * @param CalendarEvent $occurrence   the concrete occurrence (== $configEvent for non-recurring)
     * @param Carbon        $start        the occurrence start datetime
     * @param string        $occKey       'single' or 'YYYYMMDD'
     */
    private function processEventOccurrence(
        CalendarEvent $configEvent,
        CalendarEvent $occurrence,
        Carbon $start,
        string $occKey,
        Carbon $now,
    ): int {
        $offsets  = $configEvent->effectiveReminderOffsets();
        $channels = $configEvent->effectiveReminderChannels();
        if (empty($offsets) || empty($channels)) {
            return 0;
        }

        // Which offsets are due right now: fireAt = start - offset; due when
        // fireAt <= now <= start + grace. Never remind meaningfully after start
        // (grace only absorbs tick jitter so a 0-offset isn't missed).
        $dueOffsets = array_filter($offsets, function (int $offset) use ($start, $now) {
            $fireAt = $start->copy()->subMinutes($offset);
            return $now->greaterThanOrEqualTo($fireAt)
                && $now->lessThanOrEqualTo($start->copy()->addMinutes(self::GRACE_MINUTES));
        });
        if (empty($dueOffsets)) {
            return 0;
        }

        $recipients = $configEvent->reminderRecipients();
        if ($recipients->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach ($recipients as $user) {
            $userChannels = $this->channelsForUser($user, $channels);
            foreach ($dueOffsets as $offset) {
                foreach ($userChannels as $channel) {
                    if ($this->deliver($configEvent, $occurrence, $user, $channel, $offset, $occKey, $now)) {
                        $sent++;
                    }
                }
            }
        }

        return $sent;
    }

    /**
     * Attempt exactly-once delivery of one reminder. Returns true if a send happened.
     *
     * The log row is inserted FIRST (inside the unique-index guard): if the insert
     * wins the race we dispatch; if it loses (duplicate) we skip. Dispatch failures
     * are isolated + logged and do NOT roll back the guard row — that would re-open
     * the door to a duplicate on the next tick (a real-estate reminder emailed twice
     * is worse than one that silently failed and is logged for follow-up).
     */
    private function deliver(
        CalendarEvent $configEvent,
        CalendarEvent $occurrence,
        User $user,
        string $channel,
        int $offset,
        string $occKey,
        Carbon $now,
    ): bool {
        try {
            CalendarReminderLog::create([
                'calendar_event_id' => $configEvent->id,
                'agency_id'         => $configEvent->agency_id,
                'user_id'           => $user->id,
                'channel'           => $channel,
                'offset_minutes'    => $offset,
                'occurrence_key'    => $occKey,
                'sent_at'           => $now,
            ]);
        } catch (QueryException $e) {
            // 23000 = integrity constraint violation (our UNIQUE index) → already sent.
            if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'cal_reminder_once_idx')) {
                return false;
            }
            throw $e;
        }

        try {
            if ($channel === 'popup') {
                $this->dispatchPopup($occurrence, $user);
            } elseif ($channel === 'email') {
                $this->dispatchEmail($configEvent, $occurrence, $user, $offset);
            }
        } catch (\Throwable $e) {
            Log::warning(sprintf(
                'Reminder %s send failed (event #%s occ %s user #%s): %s',
                $channel, $configEvent->id, $occKey, $user->id, $e->getMessage()
            ));
        }

        return true;
    }

    /**
     * Popup channel: (a) a database notification for the bell / notifications page,
     * (b) an FCM device push through the storm-guarded funnel. The toast itself reads
     * the calendar_reminders_log row we just inserted (via the due endpoint), so the
     * popup surface is delivered even if the DB-notification write is skipped.
     *
     * sendNow(..., ['database']) forces the DB channel only — bypassing the
     * notification's via() which would also send mail (the email channel is handled
     * separately + independently, so reusing via() here would double-send).
     */
    private function dispatchPopup(CalendarEvent $occurrence, User $user): void
    {
        Notification::sendNow($user, new EventDueReminderNotification($occurrence), ['database']);

        // Device push — gated by the user's own push master switch, failure-isolated.
        $settings = UserDashboardSetting::getEffective($user);
        if ($settings->notify_push ?? true) {
            try {
                $this->push->sendToUser(
                    $user,
                    sprintf('user:%s|event.reminder|CalendarEvent:%s', $user->id, $occurrence->id),
                    (new EventDueReminderNotification($occurrence))->toFcmPayload(),
                );
            } catch (\Throwable $e) {
                Log::warning("Reminder push failed for user #{$user->id}, event #{$occurrence->id}: {$e->getMessage()}");
            }
        }
    }

    /** Email channel: a clean reminder email to the recipient's own address. */
    private function dispatchEmail(CalendarEvent $configEvent, CalendarEvent $occurrence, User $user, int $offset): void
    {
        if (empty($user->email)) {
            return;
        }
        Mail::to($user->email)->send(new EventReminderMail($configEvent, $occurrence, $user, $offset));
    }

    /**
     * Intersect the event's channels with the user's global channel master switches so
     * a user who turned OFF in-app or email notifications does not get that channel,
     * while the per-event choice still governs which channels are eligible at all.
     *
     * @param  string[] $eventChannels
     * @return string[]
     */
    private function channelsForUser(User $user, array $eventChannels): array
    {
        $settings = UserDashboardSetting::getEffective($user);
        return array_values(array_filter($eventChannels, function (string $channel) use ($settings) {
            return match ($channel) {
                'popup' => (bool) ($settings->notify_in_app ?? true),
                'email' => (bool) ($settings->notify_email ?? true),
                default => false,
            };
        }));
    }

    /**
     * The furthest-out lead time any event could use, so the DB pre-filter window is
     * bounded but never clips a legitimately-configured reminder. Since the form
     * restricts a per-event offset to the agency option list, the max option across
     * all agencies (∪ the system default) is a correct upper bound. Events beyond this
     * horizon simply aren't due yet and are picked up on a later tick.
     */
    private function maxLookaheadMinutes(): int
    {
        $max = collect(AgencyContactSettings::DEFAULT_EVENT_REMINDER_OFFSETS)->max() ?: 60;

        try {
            AgencyContactSettings::query()
                ->withoutGlobalScopes()
                ->get()
                ->each(function (AgencyContactSettings $s) use (&$max) {
                    $optMax = collect($s->calendarReminderLeadOptions())->max();
                    if ($optMax !== null && $optMax > $max) {
                        $max = (int) $optMax;
                    }
                });
        } catch (\Throwable $e) {
            // Settings unreadable → fall back to the default option ceiling.
            $max = max($max, (int) collect(AgencyContactSettings::DEFAULT_CALENDAR_REMINDER_LEAD_OPTIONS)->max());
        }

        // Also honour any class-default offsets that exceed the option list.
        try {
            $classMax = (int) DB::table('calendar_event_class_settings')
                ->whereNotNull('default_reminder_offsets')
                ->get()
                ->flatMap(fn ($row) => json_decode($row->default_reminder_offsets, true) ?: [])
                ->map(fn ($v) => (int) $v)
                ->max();
            if ($classMax > $max) {
                $max = $classMax;
            }
        } catch (\Throwable $e) {
            // ignore — option-list bound already applied
        }

        return max(1, min(43200, $max));
    }

    // ── Popup toast feed / actions (self-scoped to the given user) ──────────────

    /**
     * Undelivered popup reminders for a user's toast: popup-channel log rows that are
     * unread, not currently snoozed, sent recently, whose event is still live.
     * Returns display-ready rows (title, when, lead label, deep link).
     *
     * @return array<int,array<string,mixed>>
     */
    public function dueForUser(User $user, ?Carbon $now = null): array
    {
        $now = $now ? $now->copy() : now();

        $logs = CalendarReminderLog::query()
            ->where('user_id', $user->id)
            ->where('channel', 'popup')
            ->whereNull('read_at')
            ->where('sent_at', '>=', $now->copy()->subHours(2))
            ->where(function ($q) use ($now) {
                $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', $now);
            })
            ->orderBy('sent_at')
            ->with(['event' => fn ($q) => $q->withoutGlobalScopes([AgencyScope::class])->with('property')])
            ->get();

        return $logs->filter(function (CalendarReminderLog $log) {
            $event = $log->event;
            return $event && $event->status === 'pending';
        })->map(function (CalendarReminderLog $log) {
            $event = $log->event;
            $start = $this->occurrenceStartFor($event, $log->occurrence_key);
            return [
                'id'              => $log->id,
                'event_id'        => $event->id,
                'title'           => $event->title,
                'when_h'          => $start->format('D, d M H:i'),
                'lead_label'      => $this->leadLabel((int) $log->offset_minutes),
                'starts_label'    => $start->diffForHumans(now(), ['parts' => 2]),
                'property'        => $event->property?->buildDisplayAddress(),
                'occurrence_date' => $log->occurrence_key !== 'single' ? $log->occurrence_key : null,
                'view_url'        => url('/corex/command-center/calendar/' . $event->id),
            ];
        })->values()->all();
    }

    /** Mark a reminder read (dismissed / clicked-through). Self-scoped. */
    public function markRead(User $user, int $logId): bool
    {
        return (bool) CalendarReminderLog::where('user_id', $user->id)
            ->where('id', $logId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /** Snooze a reminder for SNOOZE_MINUTES; it re-surfaces on the toast after that. */
    public function snooze(User $user, int $logId): bool
    {
        return (bool) CalendarReminderLog::where('user_id', $user->id)
            ->where('id', $logId)
            ->update(['snoozed_until' => now()->addMinutes(self::SNOOZE_MINUTES)]);
    }

    /** Resolve the concrete occurrence start for a log row's occurrence_key. */
    private function occurrenceStartFor(CalendarEvent $event, string $occKey): Carbon
    {
        if ($occKey === 'single' || strlen($occKey) !== 8) {
            return $event->event_date->copy();
        }
        // 'YYYYMMDD' + the event's time-of-day.
        $date = Carbon::createFromFormat('Ymd', $occKey)->startOfDay();
        return $date->setTimeFrom($event->event_date);
    }

    /** Humanise a lead offset for display ("at start", "10 min before", "1 hr before"). */
    private function leadLabel(int $minutes): string
    {
        if ($minutes <= 0)     return 'now';
        if ($minutes < 60)     return "in {$minutes} min";
        if ($minutes === 60)   return 'in 1 hour';
        if ($minutes % 60 === 0) return 'in ' . ($minutes / 60) . ' hours';
        if ($minutes === 1440) return 'tomorrow';
        return 'in ' . round($minutes / 60, 1) . ' hours';
    }
}

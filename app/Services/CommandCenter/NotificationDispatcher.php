<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\Push\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NotificationDispatcher
{
    public function __construct(
        private NotificationPreferenceService $prefs,
        private PushNotificationService $push,
    ) {}

    /**
     * Dispatch a pillar event notification respecting user preferences and idempotency.
     *
     * @param string $eventKey   e.g. "property.documents_missing"
     * @param Model  $subject    The Property / Contact / Deal / etc.
     * @param array  $args       title, body, action_url, severity, payload, threshold_hit_at (Carbon)
     * @return bool true if anything was dispatched
     */
    public function fire(User $user, string $eventKey, Model $subject, array $args): bool
    {
        $eff = $this->prefs->effective($user, $eventKey);
        if (! $eff || ! $eff['enabled']) return false;

        $channels = [];
        if ($eff['channel_in_app']) $channels[] = 'database';
        if ($eff['channel_email'])  $channels[] = 'mail';
        if ($eff['channel_push'])   $channels[] = 'fcm';
        if (empty($channels)) return false;

        // Open-hours schedule gates ALL channels (in-app, email, push). When the
        // user's per-weekday window is closed for "now" (evaluated in the user's
        // timezone), suppress the alert entirely — there is no queue-for-later, so
        // we drop rather than defer. The next scan tick re-evaluates and will fire
        // once the window reopens (the threshold predicate is still true).
        if (! $this->prefs->withinOpenHours($user)) {
            return false;
        }

        // AT-235 (R3) — THE DEDUP KEY MUST BE STABLE. This line used to read
        // `?? now()`, and that single default let 1,903,039 notifications out.
        //
        // The idempotency check below asks: "is there already a log row for this
        // (user, event, subject) at or after this threshold?" That question only
        // means anything if `threshold_hit_at` is the SAME value on every scan tick
        // for the same underlying fact. With `now()` it is a fresh value every
        // tick, so the check never matched, and `contact.fica_missing` re-told the
        // same user the same fact about the same contact every 30 minutes for 24
        // days (26 May → 19 Jun 2026; 286,070 in one day; 99.5% of the entire
        // dispatch log). A human eventually noticed and soft-deleted the event type
        // by hand — nothing in the system detected or capped it.
        //
        // A caller that omits the key now gets a STABLE hourly bucket instead of a
        // moving one: worst case the alert repeats hourly (and the cooldown caps
        // even that), rather than unbounded. And we log the omission loudly, because
        // an omitted threshold is a caller bug — the caller alone knows which fact
        // this alert is about, and therefore what "the same fact" means.
        //
        // BUILD_STANDARD §3 — PREVENT, do not absorb. The dispatcher cannot invent a
        // safe default here, because only the CALLER knows what "the same fact" means:
        //
        //   - a PERSISTENT condition ("this contact still has no FICA") must key off
        //     something stable — when the fact became true — so it notifies ONCE.
        //     ScanPropertyNotifications does this correctly for mandate_expiring:
        //     `$property->mandate_expires_at->startOfDay()`.
        //   - a DISCRETE event ("feedback was just captured") is a new fact every
        //     time, so `now()` is correct — and passing it is a conscious choice.
        //
        // Any default the dispatcher picks is a guess at which of those two it is,
        // and the guess it used to make (`now()`) silently turned every persistent
        // condition into a discrete one. A time-bucket default (startOfHour) is no
        // better — it just makes the storm hourly instead of half-hourly.
        //
        // So: the key is REQUIRED. A caller that forgets fails immediately and
        // loudly, in dev, instead of quietly shipping a tap that cannot be turned
        // off. This cannot fire in production: all 8 call sites pass it, and
        // NotificationDispatcherDedupTest asserts statically that every `->fire(`
        // site in app/ still does — so the guard fails the BUILD, not the user.
        if (! isset($args['threshold_hit_at']) || $args['threshold_hit_at'] === null) {
            throw new \InvalidArgumentException(sprintf(
                'NotificationDispatcher::fire("%s") requires an explicit threshold_hit_at. '
                . 'It is the dedup key: pass a STABLE value derived from the fact for a persistent '
                . 'condition (so it notifies once), or now() for a discrete one-off event (so each '
                . 'occurrence notifies). Omitting it is what let contact.fica_missing fire 1,903,039 '
                . 'times — see .ai/audits/2026-07-13-at235-notifications-vs-event-classes.md §2.',
                $eventKey
            ));
        }

        $thresholdHit = $args['threshold_hit_at'];

        $subjectType = $subject->getMorphClass();
        $subjectId   = $subject->getKey();

        $alreadySent = NotificationDispatchLog::where('user_id', $user->id)
            ->where('notification_event_type_id', $eff['event_type']->id)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->where('threshold_hit_at', '>=', $thresholdHit)
            ->exists();
        if ($alreadySent) return false;

        // Cooldown: skip if the same (user, event-type, subject) was dispatched
        // within the user's min_minutes_between_same window. Stops the hourly-spam
        // where scheduler scans re-fire the same alert each tick.
        $cooldown = $this->prefs->cooldownMinutes($user);
        if ($cooldown > 0) {
            $recent = NotificationDispatchLog::where('user_id', $user->id)
                ->where('notification_event_type_id', $eff['event_type']->id)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('dispatched_at', '>=', now()->subMinutes($cooldown))
                ->exists();
            if ($recent) return false;
        }

        $notification = new PillarEventNotification(
            eventKey:     $eventKey,
            pillar:       $eff['event_type']->pillar,
            title:        $args['title']     ?? $eff['event_type']->label,
            body:         $args['body']      ?? '',
            subjectType:  $subjectType,
            subjectId:    $subjectId,
            subjectLabel: $args['subject_label'] ?? null,
            actionUrl:    $args['action_url']    ?? null,
            severity:     $args['severity']      ?? 'info',
            payload:      $args['payload']       ?? [],
            channels:     array_intersect($channels, ['database', 'mail']), // FCM handled below
        );

        // Pre-assign the notification UUID so the same id flows to both the
        // saved database row and the FCM data payload (notification_id).
        $notification->id = (string) Str::uuid();

        // 1) Database + mail via Laravel notifications
        try {
            $user->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('Pillar notification dispatch failed', [
                'user' => $user->id, 'key' => $eventKey, 'error' => $e->getMessage(),
            ]);
        }

        // 2) FCM push — routed through the guarded PushNotificationService.
        //    The idempotency key is STABLE per logical alert (user + event +
        //    subject + threshold bucket), NOT the per-dispatch UUID — so even if
        //    this path is reached twice for the same alert, the device is hit once.
        if (in_array('fcm', $channels, true)) {
            $idempotencyKey = sprintf(
                'user:%s|%s|%s:%s|%s',
                $user->id,
                $eventKey,
                $subjectType,
                $subjectId,
                $thresholdHit instanceof \Carbon\CarbonInterface ? $thresholdHit->format('YmdHi') : (string) $thresholdHit,
            );
            $this->push->sendToUser($user, $idempotencyKey, $notification->toFcmPayload());
        }

        foreach ($channels as $ch) {
            $logChannel = $ch === 'database' ? 'in_app' : ($ch === 'mail' ? 'email' : 'push');
            NotificationDispatchLog::create([
                'user_id' => $user->id,
                'notification_event_type_id' => $eff['event_type']->id,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'threshold_hit_at' => $thresholdHit,
                'dispatched_at'    => now(),
                'channel'          => $logChannel,
            ]);
        }

        return true;
    }
}

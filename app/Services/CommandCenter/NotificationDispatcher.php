<?php

namespace App\Services\CommandCenter;

use App\Models\CommandCenter\NotificationDispatchLog;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use App\Services\Push\PushNotificationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;

class NotificationDispatcher
{
    public function __construct(
        private NotificationPreferenceService $prefs,
        private PushNotificationService $push,
    ) {}

    /**
     * AT-235 (S0) — THE GATEWAY. Send ANY notification through the guard chain.
     *
     * This is the method that makes the consolidation possible at all.
     *
     * `fire()` below could only ever send a PillarEventNotification — a generic
     * title/body alert it constructed itself. But every one of the 22 bypasses has
     * its OWN notification class, with its own mail template
     * (ProformaCreatedNotification, SignatureActivityNotification, the seven
     * Presentations ones…). A producer that switched to fire() would have LOST its
     * email and sent a generic stub instead.
     *
     * That is why 31 bypasses exist. Nobody was being lazy — the door was locked.
     *
     * The move is to separate two things that were welded together:
     *
     *   WHO / WHETHER / WHERE  — preference, agency policy, open hours, cooldown,
     *                            idempotency, ledger.  ← the gateway's job, ONLY.
     *   WHAT                   — the message, its mail template, its push payload.
     *                            ← the caller's notification class, unchanged.
     *
     * Channel selection therefore stops living inside each notification's via() —
     * where it is invisible, per-class and inconsistent — and lives here, once.
     * Notification::sendNow() with an explicit channel list overrides via().
     *
     * ── THE CONSENT INVARIANT ───────────────────────────────────────────────
     * The channels resolved here are a CEILING, never a floor. A producer, an
     * agency setting or a class config may NARROW what is sent. None of them may
     * WIDEN it past what the user asked for. (In R2 the fix that made the agency's
     * channel config work also bypassed via() — where the user's notify_email
     * master switch was being checked — and would have let agencies override user
     * consent. The veto had to be re-applied deliberately.)
     *
     * @param string       $eventKey     MUST exist in notification_event_types.
     * @param Model        $subject      What the alert is ABOUT (the dedup identity).
     * @param Notification $notification The caller's own notification class.
     * @param array        $args         threshold_hit_at (REQUIRED) …
     */
    public function send(
        User $user,
        string $eventKey,
        Model $subject,
        Notification $notification,
        array $args = [],
    ): bool {
        return $this->dispatch($user, $eventKey, $subject, $args, $notification);
    }

    /**
     * The original entry point: send a generic pillar alert (title/body) built by the
     * gateway itself. Unchanged for its 8 existing callers — it is now simply send()
     * with a PillarEventNotification, so it cannot drift from the guard chain.
     */
    public function fire(User $user, string $eventKey, Model $subject, array $args): bool
    {
        return $this->dispatch($user, $eventKey, $subject, $args, null);
    }

    /**
     * The one guard chain. `fire()` and `send()` differ ONLY in what gets delivered:
     * fire() builds a generic PillarEventNotification; send() carries the caller's own.
     */
    private function dispatch(
        User $user,
        string $eventKey,
        Model $subject,
        array $args,
        ?Notification $given,
    ): bool {
        $eff = $this->prefs->effective($user, $eventKey);
        if (! $eff || ! $eff['enabled']) return false;

        // What the USER wants.
        $channels = [];
        if ($eff['channel_in_app']) $channels[] = 'database';
        if ($eff['channel_email'])  $channels[] = 'mail';
        if ($eff['channel_push'])   $channels[] = 'fcm';

        // AT-235 (S0 / C11) — …intersected with what this event type SUPPORTS.
        //
        // notification_event_types carries supports_in_app / supports_email /
        // supports_push, and NOTHING READ THEM AT SEND TIME. They were rendered in
        // the settings UI and then ignored — so a type marked "does not support
        // email" would still email.
        //
        // That became load-bearing the moment the gateway started carrying the
        // caller's OWN notification class: ProformaCreatedNotification, for
        // instance, is database-only and has no toMail(). A user who enables email
        // would have made the gateway resolve `mail` and blow up inside the mailer.
        // Preference says what the user WANTS; the catalogue says what the event CAN
        // do. You need both.
        $type = $eff['event_type'];
        $supported = [];
        if ($type->supports_in_app) $supported[] = 'database';
        if ($type->supports_email)  $supported[] = 'mail';
        if ($type->supports_push)   $supported[] = 'fcm';

        $channels = array_values(array_intersect($channels, $supported));

        // …and with what the notification can actually RENDER. A carried class that
        // cannot build a mail must never be handed the mail channel, whatever the
        // catalogue claims — belt and braces, because a wrong catalogue row should
        // degrade to "no email", not to a 500 inside the mailer.
        if ($given !== null) {
            $channels = array_values(array_filter($channels, static function (string $ch) use ($given): bool {
                return match ($ch) {
                    'mail'     => method_exists($given, 'toMail'),
                    'database' => method_exists($given, 'toArray') || method_exists($given, 'toDatabase'),
                    'fcm'      => method_exists($given, 'toFcmPayload'),
                    default    => false,
                };
            }));
        }

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

        // The channels the GATEWAY has resolved — preference ∩ agency ∩ open-hours.
        // These are a ceiling: whatever we deliver, we deliver on THESE and no others.
        $laravelChannels = array_values(array_intersect($channels, ['database', 'mail']));

        $notification = $given ?? new PillarEventNotification(
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
            channels:     $laravelChannels, // FCM handled below
        );

        // Pre-assign the notification UUID so the same id flows to both the
        // saved database row and the FCM data payload (notification_id).
        $notification->id = (string) Str::uuid();

        // 1) Database + mail.
        //
        // sendNow() with an EXPLICIT channel list overrides the notification's own
        // via(). That is deliberate and is the heart of the consolidation: channel
        // selection belongs to the gateway, not to each notification class. A
        // caller's via() may still exist (it is what the class does when sent
        // outside the gateway, during migration) — but here the gateway's answer
        // wins, so a user's preference cannot be quietly widened by a class.
        //
        // If the gateway resolved NO Laravel channels (e.g. the user wants push
        // only), we must not call sendNow() with an empty list — Laravel would fall
        // back to via() and deliver on channels the user did not ask for. Skip it.
        try {
            if (! empty($laravelChannels)) {
                NotificationFacade::sendNow($user, $notification, $laravelChannels);
            }
        } catch (\Throwable $e) {
            Log::warning('Notification dispatch failed', [
                'user'         => $user->id,
                'key'          => $eventKey,
                'notification' => get_class($notification),
                'error'        => $e->getMessage(),
            ]);
        }

        // 2) FCM push — routed through the guarded PushNotificationService.
        //    The idempotency key is STABLE per logical alert (user + event +
        //    subject + threshold bucket), NOT the per-dispatch UUID — so even if
        //    this path is reached twice for the same alert, the device is hit once.
        //
        // A CARRIED notification (send()) may not know how to render a push payload
        // — only PillarEventNotification implements toFcmPayload(). Duck-type it
        // rather than forcing an interface onto 22 classes mid-migration: a class
        // that wants push implements toFcmPayload(); one that does not simply gets
        // in-app + email, and we record only the channels we actually delivered on
        // (see $delivered below) so the ledger never claims a push that never left.
        $canPush = in_array('fcm', $channels, true) && method_exists($notification, 'toFcmPayload');

        if ($canPush) {
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

        // The ledger records what was DELIVERED, not what was merely wanted. A push
        // channel that the notification cannot render is not a dispatch, and logging
        // it as one would make the idempotency ledger lie — which is exactly the
        // class of bug that produced the 1.9M storm.
        $delivered = $laravelChannels;
        if ($canPush) {
            $delivered[] = 'fcm';
        }

        foreach ($delivered as $ch) {
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

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

        $thresholdHit = $args['threshold_hit_at'] ?? now();
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

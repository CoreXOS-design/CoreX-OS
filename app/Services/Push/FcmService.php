<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;

/**
 * FCM v1 push transport — a thin adapter over kreait's Messaging client.
 *
 * Deliberately dumb: it performs exactly ONE multicast attempt. It does NOT
 * retry, rate-cap, de-dupe, or check idempotency — those guards live in
 * App\Services\Push\PushNotificationService, which is the only thing that
 * should call this class, and which must work even when this transport is
 * swapped for NullPushTransport. Transport-level failures propagate as
 * exceptions (the service owns the retry/backoff policy); per-token permanent
 * rejections are returned as dead tokens for the service to prune.
 */
class FcmService implements PushTransport
{
    public function __construct(private Messaging $messaging) {}

    public function send(array $tokens, array $payload): PushSendResult
    {
        $tokens = array_values(array_filter(array_unique(array_map('strval', $tokens))));
        if (empty($tokens)) {
            return PushSendResult::none();
        }

        $message = CloudMessage::new()
            ->withNotification(FcmNotification::create(
                $payload['notification']['title'] ?? '',
                $payload['notification']['body']  ?? '',
            ))
            ->withData(array_map('strval', $payload['data'] ?? []))
            // Without an explicit channel_id, Android 8+ routes the push into
            // FCM's auto-created fallback channel at default importance — it
            // lands quietly in the shade with no heads-up banner, regardless
            // of any high-importance channel the app itself defines (the app
            // only used one for its own local test-notification button, never
            // for a server-sent push). 'corex_alerts' MUST exist as a real,
            // high-importance NotificationChannel created client-side at app
            // startup — this string is the shared contract between the two.
            // See .ai/specs/push-notifications.md.
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => [
                    'channel_id'             => 'corex_alerts',
                    'notification_priority'  => 'PRIORITY_HIGH',
                    'default_sound'          => true,
                    'default_vibrate_timings' => true,
                ],
            ]));

        // Let transport-level failures throw — PushNotificationService retries
        // with bounded backoff. We do not catch here.
        $report = $this->messaging->sendMulticast($message, $tokens);

        $dead = array_merge($report->unknownTokens(), $report->invalidTokens());

        // Was: count($tokens) - count($dead) — that counts anything NOT
        // reported unknown/invalid as "sent", so a real per-message failure
        // (SenderIdMismatch, a transient auth error, quota) was silently
        // counted as delivered. successes() is FCM's own per-message result,
        // not an inference from what ISN'T in two specific failure buckets.
        return new PushSendResult(
            sent: $report->successes()->count(),
            deadTokens: array_values(array_unique($dead)),
        );
    }

    public function isOperational(): bool
    {
        return true;
    }
}

<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
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
    /**
     * The Android notification channel every CoreX push is posted to.
     *
     * MUST match `default_notification_channel_id` in the mobile app's
     * AndroidManifest and `_pushChannelId` in lib/services/messaging_service.dart.
     * Sending no channel_id is not neutral: Android 8+ then files the push under
     * the Firebase SDK's auto-created fallback channel at IMPORTANCE_DEFAULT, so
     * it appears silently in the shade with no heads-up banner — delivered, but
     * invisible to an agent who isn't already looking at their phone.
     */
    private const ANDROID_CHANNEL_ID = 'corex_push';

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
            ->withAndroidConfig(AndroidConfig::fromArray([
                // Wake a dozing device rather than waiting for the next
                // maintenance window — a portal lead is only worth anything hot.
                'priority'     => 'high',
                'notification' => [
                    'channel_id'            => self::ANDROID_CHANNEL_ID,
                    // Pre-Oreo equivalent of the channel's importance.
                    'notification_priority' => 'PRIORITY_HIGH',
                    'default_sound'         => true,
                ],
            ]))
            ->withApnsConfig(ApnsConfig::fromArray([
                // 10 = deliver immediately (the iOS counterpart of priority high).
                'headers' => ['apns-priority' => '10'],
                'payload' => ['aps' => ['sound' => 'default']],
            ]));

        // Let transport-level failures throw — PushNotificationService retries
        // with bounded backoff. We do not catch here.
        $report = $this->messaging->sendMulticast($message, $tokens);

        // Only unknown/invalid tokens are prunable: those are the permanent
        // "this device is gone" rejections. Other per-token failures (quota,
        // SenderIdMismatch, transient unavailability) are real failures but the
        // row must survive them.
        $dead = array_merge($report->unknownTokens(), $report->invalidTokens());

        return new PushSendResult(
            // Count what FCM actually accepted, NOT tokens-minus-prunable. The
            // old arithmetic scored every non-prunable failure as a success, so
            // a push rejected for quota or a sender-id mismatch still logged
            // "sent": 1 — which is exactly the signal used to conclude delivery
            // worked and look for the bug on the handset instead.
            sent: $report->successes()->count(),
            deadTokens: array_values(array_unique($dead)),
        );
    }

    public function isOperational(): bool
    {
        return true;
    }
}

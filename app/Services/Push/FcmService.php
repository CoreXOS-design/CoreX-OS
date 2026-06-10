<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;
use Kreait\Firebase\Contract\Messaging;
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
            ->withData(array_map('strval', $payload['data'] ?? []));

        // Let transport-level failures throw — PushNotificationService retries
        // with bounded backoff. We do not catch here.
        $report = $this->messaging->sendMulticast($message, $tokens);

        $dead = array_merge($report->unknownTokens(), $report->invalidTokens());

        return new PushSendResult(
            sent: max(0, count($tokens) - count($dead)),
            deadTokens: array_values(array_unique($dead)),
        );
    }

    public function isOperational(): bool
    {
        return true;
    }
}

<?php

namespace App\Services\Push\Contracts;

use App\Services\Push\PushSendResult;

/**
 * The raw device-push transport — a single attempt at delivering one payload to
 * a set of device tokens. Implementations MUST NOT swallow transport-level
 * failures (network / auth / 5xx): they throw, and the caller
 * (App\Services\Push\PushNotificationService) owns the bounded-retry-with-backoff
 * policy. Per-token *permanent* rejections (NotRegistered / InvalidRegistration)
 * are NOT exceptions — they are returned in PushSendResult::$deadTokens so the
 * caller can prune the stale rows without retrying.
 *
 * Keeping retry/idempotency/rate-capping OUT of the transport is deliberate:
 * those guards must run even in environments where no real FCM transport is
 * installed (see NullPushTransport), and they must be unit-testable without a
 * live Firebase connection.
 */
interface PushTransport
{
    /**
     * Deliver one push payload to the given tokens (single attempt).
     *
     * @param  string[]  $tokens   Already de-duplicated, idempotency- and rate-filtered tokens.
     * @param  array{notification: array{title: string, body: string}, data: array<string, string>}  $payload
     * @return PushSendResult       sent count + tokens the provider rejected as permanently dead.
     *
     * @throws \Throwable on transport-level failure (the caller retries with backoff).
     */
    public function send(array $tokens, array $payload): PushSendResult;

    /**
     * Whether this transport can actually deliver. A no-op transport returns
     * false so the dispatcher can skip work (and tests can assert "not wired").
     */
    public function isOperational(): bool;
}

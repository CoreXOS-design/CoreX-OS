<?php

namespace App\Services\Push;

use App\Services\Push\Contracts\PushTransport;

/**
 * No-op push transport. Bound in environments where no real FCM credentials /
 * the kreait transport is unavailable (local dev, CI). Lets the whole dispatch
 * pipeline — idempotency, rate-capping, token-dedup, metrics — run and be
 * tested without a live Firebase connection, and guarantees the app never
 * crashes for lack of a push provider.
 */
class NullPushTransport implements PushTransport
{
    public function send(array $tokens, array $payload): PushSendResult
    {
        return PushSendResult::none();
    }

    public function isOperational(): bool
    {
        return false;
    }
}

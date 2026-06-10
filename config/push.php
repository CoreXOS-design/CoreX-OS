<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push dispatch guards
    |--------------------------------------------------------------------------
    |
    | These tune App\Services\Push\PushNotificationService — the single funnel
    | every device push flows through. They exist to make a notification storm
    | (the same buzz delivered to a handset repeatedly) structurally impossible.
    | See .ai/specs/push-notifications.md.
    |
    */

    // Idempotency window (seconds). The same logical push (same idempotency key)
    // will NOT be delivered to the same device twice inside this window, no
    // matter how many times its trigger fires. Long enough to absorb a re-firing
    // 5-minute poller; short enough that a genuinely re-raised alert hours later
    // still gets through.
    'idempotency_ttl' => (int) env('PUSH_IDEMPOTENCY_TTL', 300),

    // Hard per-device backstop: max pushes delivered to one device token per
    // minute, regardless of idempotency key. Even a distinct-key flood or a
    // legitimate burst of leads cannot exceed this. 0 disables the cap.
    'rate_per_minute' => (int) env('PUSH_RATE_PER_MINUTE', 5),

    // Bounded retry for transient transport failures (network / auth / 5xx).
    // Permanent per-token rejections (NotRegistered) are never retried — the
    // token is pruned instead.
    'max_attempts' => (int) env('PUSH_MAX_ATTEMPTS', 3),

    // Base backoff in milliseconds; the delay grows exponentially per attempt
    // (base * 2^(n-1)), capped at 5s. Set to 0 to disable sleeping (tests).
    'retry_base_ms' => (int) env('PUSH_RETRY_BASE_MS', 200),

];

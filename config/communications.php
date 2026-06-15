<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Communication Archive (AT-32 / AT-33)
    |--------------------------------------------------------------------------
    | Storage destination for raw .eml / .json payloads and attachments. The
    | content-addressed writer abstracts this — swap to a Storage Box / S3
    | bucket by changing this disk, no code change. Default 'local' resolves to
    | storage/app/private/communications/.
    */
    'disk' => env('COMMUNICATIONS_DISK', 'local'),

    /*
    | Inbound grace window (calendar days) before an unmatched inbound item
    | prunes. Clamped to a maximum of 5 by CommunicationPending::graceDays().
    */
    'pending_grace_days' => (int) env('COMMUNICATIONS_PENDING_GRACE_DAYS', 4),

    /*
    |--------------------------------------------------------------------------
    | IMAP polling timeouts (AT-40)
    |--------------------------------------------------------------------------
    | imap_timeout_seconds      — connect + per-read socket timeout. Applied to
    |   the webklex account config AND re-applied with stream_set_timeout() on
    |   the live (TLS-wrapped) stream after connect, because webklex sets the
    |   timeout on the raw socket BEFORE enabling crypto and fread() on a TLS
    |   stream otherwise ignores it.
    | imap_poll_budget_seconds  — hard overall watchdog (pcntl alarm) around one
    |   mailbox poll. A non-responsive folder read aborts here with a clean,
    |   logged error instead of blocking until the queue job timeout. MUST stay
    |   below the worker job timeout (default 60s) so the read fails first.
    */
    'imap_timeout_seconds'     => (int) env('COMMUNICATIONS_IMAP_TIMEOUT', 20),
    'imap_poll_budget_seconds' => (int) env('COMMUNICATIONS_IMAP_POLL_BUDGET', 50),

];

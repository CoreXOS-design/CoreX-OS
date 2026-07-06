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
    |--------------------------------------------------------------------------
    | WA dropped-payload debug probe (AT-133, TEMPORARY)
    |--------------------------------------------------------------------------
    | When ON, WaArchiveIngestor dumps the ENTIRE raw payload of every inbound
    | WhatsApp message dropped as no_contact_match (chat_id, sender, author, any
    | @-jid fields, the counterpart it tried, and what normalizePhone returned)
    | to Log::debug. OFF by default — normal operation logs nothing extra. Used
    | once on staging to decide AT-133 fix (1) extension vs (2) server. Safe to
    | leave flag-gated for future WA identifier debugging.
    */
    'debug_dropped_wa' => (bool) env('COMMUNICATIONS_DEBUG_DROPPED_WA', false),

    /*
    | AT-168 Part C — conversation-thread paging. The thread view loads the newest
    | page and lazy-loads older messages on scroll-up so a years-long thread never
    | renders at once. Configurable, not hardcoded.
    */
    'thread_page_size' => (int) env('COMMUNICATIONS_THREAD_PAGE_SIZE', 40),

    /*
    | Inbound grace window (calendar days) before an unmatched inbound item
    | prunes. Clamped to a maximum of 5 by CommunicationPending::graceDays().
    */
    'pending_grace_days' => (int) env('COMMUNICATIONS_PENDING_GRACE_DAYS', 4),

    /*
    |--------------------------------------------------------------------------
    | Provisional outbound reconciliation (AT-59)
    |--------------------------------------------------------------------------
    | DEFAULTS only — agencies override via the
    | communication_reconcile_window_minutes and
    | communication_provisional_prune_hours columns (Agency::reconcileWindowMinutes()
    | / Agency::provisionalPruneHours() merge the override over these).
    |
    | reconcile_window_minutes — ± window for the time-based fallback match
    |   between an ingested outbound message and a provisional click when their
    |   text hashes differ (the agent edited the message before sending). Exact
    |   text-hash matches ignore the window. Default 48h.
    | provisional_prune_hours  — age after which an unreconciled provisional row
    |   is soft-purged by communications:prune-provisional (orphan from an
    |   edited-before-send message that never matched). MUST exceed the reconcile
    |   window. Default 7 days.
    */
    'reconcile_window_minutes' => (int) env('COMMUNICATIONS_RECONCILE_WINDOW_MINUTES', 2880),
    'provisional_prune_hours'  => (int) env('COMMUNICATIONS_PROVISIONAL_PRUNE_HOURS', 168),

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

    /*
    | First-poll backfill window (days). The VERY FIRST poll of a mailbox (no
    | last_polled_at yet) reads mail received in the last N days. A large window
    | on a slow/large INBOX can exceed imap_poll_budget_seconds and trap the
    | mailbox in a never-completing full backfill, so the default is small;
    | incremental polls thereafter only read since last_polled_at (with a 1-day
    | overlap). Agency-overridable via agencies.communication_first_poll_days.
    */
    'first_poll_backfill_days' => (int) env('COMMUNICATIONS_FIRST_POLL_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Mailbox failure-alert threshold (AT-181)
    |--------------------------------------------------------------------------
    | Consecutive failed polls before the agency's admins are alerted (once per
    | failure episode; reset on recovery). Agency-overridable via
    | agencies.communication_failure_alert_threshold.
    */
    'failure_alert_threshold' => (int) env('COMMUNICATIONS_FAILURE_ALERT_THRESHOLD', 3),

    /*
    |--------------------------------------------------------------------------
    | Sent-folder candidates (AT-43)
    |--------------------------------------------------------------------------
    | Fallback paths tried (in order) when a server does not advertise the
    | RFC 6154 \Sent special-use flag. Special-use detection is preferred; this
    | only kicks in for older servers. Non-empty, selectable folders win.
    */
    'sent_folder_candidates' => [
        'INBOX.Sent', 'Sent', '[Gmail]/Sent Mail', 'Sent Items', 'Sent Mail', 'INBOX.Sent Items',
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic ingestion filter (AT-43, POPIA data-minimisation)
    |--------------------------------------------------------------------------
    | DEFAULTS only — agencies override via communication_ingest_drop_noreply
    | and communication_ingest_blocklist_domains columns (CommunicationIngestFilter
    | merges agency settings over these). A sender that matches a CoreX contact is
    | NEVER dropped — contact always wins; the filter only runs when no contact
    | matched. Dropped mail is not stored; the drop is logged for audit.
    |
    | ingest_drop_noreply        — drop machine senders (no-reply@, system@, …).
    | ingest_noreply_local_parts — local-part (before @) markers, matched as a
    |                              substring, case-insensitive.
    | ingest_blocklist_domains   — service/bank/portal-notification domains whose
    |                              mail is never a client communication. Matched on
    |                              the email domain (incl. subdomains).
    */
    'ingest_drop_noreply' => (bool) env('COMMUNICATIONS_INGEST_DROP_NOREPLY', true),

    'ingest_noreply_local_parts' => [
        'no-reply', 'noreply', 'no_reply', 'do-not-reply', 'donotreply', 'do_not_reply',
        'system', 'mailer-daemon', 'mailerdaemon', 'postmaster', 'bounce', 'bounces',
        'notification', 'notifications', 'auto-reply', 'autoreply', 'noreplay',
    ],

    'ingest_blocklist_domains' => [
        // Banks
        'fnb.co.za', 'absa.co.za', 'standardbank.co.za', 'nedbank.co.za', 'capitecbank.co.za',
        // Accounting / payroll / tax
        'sageone.co.za', 'accounting.sageone.co.za', 'xero.com', 'ktaxsa.co.za',
        // Property portals / industry notification senders
        'tpn.co.za', 'propcon.co.za', 'privateproperty.co.za', 'property24.com', 'reos.co.za',
        'thevirtualagent.co.za', 'consumercontactcentre.co.za',
        // Generic SaaS / analytics notifications
        'google.com', 'data-studio-noreply.google.com', 'handicaps.co.za',
    ],

    /*
    |--------------------------------------------------------------------------
    | WAHA server-side session — media download (AT-148, Relates AT-138/143)
    |--------------------------------------------------------------------------
    | The WAHA server session (GOWS engine, isolated Docker container on the box,
    | 127.0.0.1:3111, API-key ON) delivers media as a URL to fetch — WAHA holds
    | the DECRYPTED bytes and serves them at media.url behind its API key. CoreX
    | downloads voice notes from there and stores them on the mounted volume.
    |
    |   base_url  — WAHA API base (localhost-only; never publicly exposed).
    |   api_key   — WAHA API key (X-Api-Key header). Set in .env, never committed.
    |   download_timeout_seconds — connect+read cap for a single media fetch.
    |   max_media_bytes — hard ceiling on a downloaded media (defence against a
    |                     runaway file filling the volume). Voice notes are tiny
    |                     (Opus ~1KB/s); 50 MB is generous headroom.
    |   allowed_media_hosts — download is refused unless media.url's host is in
    |                     this allow-list (SSRF guard — we only ever fetch our own
    |                     WAHA). Defaults to base_url's host.
    */
    'waha' => [
        'base_url' => rtrim((string) env('WAHA_BASE_URL', 'http://127.0.0.1:3111'), '/'),
        'api_key'  => env('WAHA_API_KEY'),
        // AT-149 — inbound webhook authentication. The WAHA container is
        // configured with a webhook HMAC key (WAHA signs each POST body,
        // header `X-Webhook-Hmac`, algo `X-Webhook-Hmac-Algorithm` default
        // sha512) OR a custom header carrying this same secret. CoreX rejects
        // any webhook that does not verify. FAIL CLOSED: if this is unset, the
        // endpoint refuses every POST (never accept an unauthenticated webhook).
        'webhook_secret'    => env('WAHA_WEBHOOK_SECRET'),
        'webhook_hmac_algo' => env('WAHA_WEBHOOK_HMAC_ALGO', 'sha512'),
        // AT-158 (2026-07-06) — session-name environment marker. NEW WAHA session
        // names are prefixed with this so a fresh link on one environment can
        // never collide with another's, even when staging is a clone of the live
        // DB (agency wa_session_prefix is copied across, APP_ENV is NOT — .env is
        // per-environment). Defaults to APP_ENV; override explicitly if needed.
        'session_env' => env('WAHA_SESSION_ENV', env('APP_ENV', 'app')),
        'download_timeout_seconds' => (int) env('WAHA_MEDIA_DOWNLOAD_TIMEOUT', 30),
        'max_media_bytes' => (int) env('WAHA_MEDIA_MAX_BYTES', 50 * 1024 * 1024),
        'allowed_media_hosts' => array_values(array_filter(array_map('trim', explode(
            ',',
            (string) env('WAHA_ALLOWED_MEDIA_HOSTS', '127.0.0.1,localhost')
        )))),
        // AT-148 media retry — a download that fails at ingest is retried (the
        // GOWS /tmp media is short-lived, so recovery re-requests it from WAHA)
        // up to this many times with a growing backoff; after that the attachment
        // is marked terminally 'failed' with a visible Retry affordance. Never
        // sits on "processing" forever.
        'media_max_retries'           => (int) env('WAHA_MEDIA_MAX_RETRIES', 3),
        'media_retry_backoff_seconds' => (int) env('WAHA_MEDIA_RETRY_BACKOFF', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | AT-163 Stage 2 — on-box voice-note transcription (whisper.cpp)
    |--------------------------------------------------------------------------
    | Local, on-box transcription of Afrikaans/English/mixed voice notes so
    | client conversations never leave the box (POPIA). A thin PHP
    | TranscriptionService shells out to the worker CLI (mirrors WahaMediaClient):
    | ffmpeg decodes the .oga/.opus → 16 kHz mono WAV, whisper.cpp transcribes.
    |
    |   binary        — the worker CLI (wraps ffmpeg + whisper.cpp, emits JSON).
    |   model         — default whisper model (Johan-locked: medium multilingual;
    |                   escalate to large-v3 only if medium's Afrikaans fails the test).
    |   models_dir    — where the ggml-*.bin models live.
    |   threads       — CPU cap: whisper thread count. Default HALF the cores so
    |                   transcription never starves the app (nice'd in the worker).
    |   timeout_seconds — hard wall-clock cap per note (shell-out kill).
    |   max_retries   — terminal 'failed' after this many (mirrors AT-148).
    |   load_avg_ceiling — "Transcribe now" is refused/queued above this 1-min
    |                   load average (CPU guard, §2.3).
    */
    'transcription' => [
        'enabled'     => (bool) env('COREX_TRANSCRIBE_ENABLED', true),
        'binary'      => env('COREX_TRANSCRIBE_BIN', '/opt/corex-transcribe/transcribe.sh'),
        'model'       => env('COREX_TRANSCRIBE_MODEL', 'medium'),
        'models_dir'  => env('COREX_TRANSCRIBE_MODELS_DIR', '/opt/corex-transcribe/models'),
        'threads'     => (int) env('COREX_TRANSCRIBE_THREADS', 8), // half of the box's 16 cores
        'timeout_seconds' => (int) env('COREX_TRANSCRIBE_TIMEOUT', 900),
        'max_retries' => (int) env('COREX_TRANSCRIBE_MAX_RETRIES', 3),
        'load_avg_ceiling' => (float) env('COREX_TRANSCRIBE_LOAD_CEILING', 12.0),
    ],

];

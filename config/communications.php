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

];

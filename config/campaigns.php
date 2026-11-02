<?php

/*
 * AT-534 — Campaigns (.ai/specs/campaigns.md §4). Defaults for the owner-editable settings (Campaigns → Settings overrides them via
 * DevSetting) plus env-backed limits that are not on screen.
 */
return [
    'defaults' => [
        'daily_limit' => 60,
        'send_days' => [1, 2, 3, 4, 5],
        'send_from' => '08:00',
        'send_to' => '16:00',
        'opt_out_footer' => 'You are receiving this email from CoreX OS. Reply STOP or use the link below and you will not hear from us again.',
        'min_day_gap' => 2,
        'smtp_host' => env('CAMPAIGNS_DEFAULT_SMTP_HOST', 'mail.corexos.co.za'),
    ],

    'max_daily_limit' => 500,
    'max_import_rows' => (int) env('CAMPAIGNS_MAX_IMPORT_ROWS', 10000),
    'max_import_mb' => (int) env('CAMPAIGNS_MAX_IMPORT_MB', 10),
    'send_batch_size' => (int) env('CAMPAIGNS_SEND_BATCH_SIZE', 20),
    'max_attempts' => 3,
    'sending_stale_minutes' => 15,
    'reply_fallback_days' => 90,
    'imap_timeout_seconds' => (int) env('CAMPAIGNS_IMAP_TIMEOUT_SECONDS', 15),
];

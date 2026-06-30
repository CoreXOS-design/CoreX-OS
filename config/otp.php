<?php

/**
 * ══════════════════════════════════════════════════════════════════
 * CoreX OS — Canonical OTP engine configuration
 * ══════════════════════════════════════════════════════════════════
 *
 * Engine: App\Services\Otp\OtpService — the ONE one-time-code engine for
 * every CoreX consumer (client-portal login today; comms-gate break-glass
 * in AT-132 Wave 2; SMS later behind the same interface).
 *
 * These are the SERVICE-WIDE defaults, carried over verbatim from the proven
 * client-auth OTP pattern (config/clientauth.php `otp` block) so the migrated
 * client-login flow keeps identical limits. Any consumer may override per
 * issue/verify call — the service never hard-codes a limit, a destination,
 * or an audit sink.
 *
 * Audit: 2026-06-30 OTP engine sweep — .ai/audits/2026-06-30-at130-otp-engine-sweep.md
 */

return [

    // Code shape
    'length'          => 6,
    'expires_minutes' => 10,

    // Verify attempt cap (per code) before the code is dead
    'max_attempts'    => 5,

    // Issuance throttle (resend cooldown + hourly cap per destination+bucket)
    'resend_cooldown_secs' => 60,
    'hourly_limit'         => 5,
    'hourly_window_secs'   => 3600,

    // Delivery. The mailer is SENDER-ONLY — it fixes the From address
    // (Otp@corexos.co.za, see config/mail.php `otp`). The DESTINATION is
    // whatever address the consumer passes per issue — never a fixed mailbox.
    // (This is what makes the comms-gate "deliver to the requester's own
    // verified email" requirement satisfiable without touching the mailer.)
    'mailer'  => env('MAIL_OTP_MAILER', 'otp'),

    // Default channel. 'email' today; 'sms' later sits behind the same
    // OtpService interface — seam noted, not built (no SMS transport here).
    'channel' => 'email',
];

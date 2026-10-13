<?php

/**
 * ══════════════════════════════════════════════════════════════════
 * CoreX OS — Client Auth (mobile client portal) configuration
 * ══════════════════════════════════════════════════════════════════
 *
 * Spec: .ai/specs/client-auth.md
 */

return [

    // Domain used when an agent fabricates a login email for a contact
    // that has no real email on file. NOT a deliverable mailbox — purely
    // a login identifier.
    'fake_email_domain' => env('CLIENT_AUTH_FAKE_DOMAIN', 'corexclient.co.za'),

    // QA DEMO SIGN-IN (never on Staging/live) - .ai/specs/rental-portal-access.md "QA demo sign-in". One-click links that sign a browser
    // into the portal as a named demo contact with no emailed code. The route exists ONLY when APP_ENV is `qa` AND this flag is on; the
    // flag is false everywhere unless PORTAL_DEMO_SIGNIN=true is set in that environment's own .env (QA1 only).
    'demo_signin_enabled' => (bool) env('PORTAL_DEMO_SIGNIN', false),

    // Sanctum token settings for client tokens
    'token' => [
        'name_default'      => 'CoreX Client App',
        'ability'           => 'client',
        'expires_in_days'   => 30,
    ],

    // OTP rules
    'otp' => [
        'length'                => 6,
        'expires_minutes'       => 10,
        'max_attempts'          => 5,
        'resend_cooldown_secs'  => 60,
        'hourly_limit_per_email'=> 5,
    ],

    // Mailer name registered in config/mail.php for OTP delivery
    'mailer' => env('MAIL_OTP_MAILER', 'otp'),

    // Activation token TTL (short-lived token returned by /otp/verify so the
    // client can call /password/set without holding a long-lived session)
    'activation_token_minutes' => 15,
];

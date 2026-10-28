<?php

/**
 * Attorney portal (.ai/specs/attorney-portal.md). Platform-level sign-in rules (not agency settings): how long a
 * signed-in browser lasts and the cookie that carries it. What an agency chooses (on/off, grace days, link life)
 * lives in attorney_portal_settings.
 */
return [
    'cookie'        => 'cx_attorney',
    'session_hours' => (int) env('ATTORNEY_PORTAL_SESSION_HOURS', 12),
    'otp'           => [
        'expires_minutes' => 10,
        'cooldown_secs'   => 60,
        'hourly_limit'    => 10,   // codes per attorney contact per hour (each contact counts on their own)
    ],
];

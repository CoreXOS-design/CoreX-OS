<?php

/**
 * CoreX Desk - tunables (spec .ai/specs/corex-desk.md). Nothing here is agency-specific; the per-agency switch is
 * agencies.desk_enabled.
 */
return [
    // How long the 6-digit code stays valid, and how long a session may wait for support before it expires.
    'code_minutes' => (int) env('DESK_CODE_MINUTES', 10),

    // The agent's tab pings every `heartbeat_seconds`; a session with no ping for `stale_after_seconds` is closed (timeout).
    'heartbeat_seconds' => (int) env('DESK_HEARTBEAT_SECONDS', 30),
    'stale_after_seconds' => (int) env('DESK_STALE_AFTER_SECONDS', 75),

    // After the agent's tab says it is leaving (page change or close) the next page has this long to reconnect.
    'leaving_grace_seconds' => (int) env('DESK_LEAVING_GRACE_SECONDS', 30),

    // Wrong codes a support user may enter before being locked out for the window.
    'join_max_failures' => (int) env('DESK_JOIN_MAX_FAILURES', 8),
    'join_window_seconds' => (int) env('DESK_JOIN_WINDOW_SECONDS', 600),

    // Phase 3 - the sites whose Chrome tabs the CoreX extension may share into a Desk session (a host matches itself and its subdomains).
    // The agency's own website (agencies.website_url) is added per agency. Anything else is refused by the server, whatever the extension sends.
    'extension_hosts' => [
        'cmainfo.co.za',
        'thevirtualagent.co.za',
        'property24.com',
        'privateproperty.co.za',
        'lightstone.co.za',
        'lightstoneproperty.co.za',
        'loom.com',
    ],

    // Phase 2 (take-over): a request the agent does not answer lapses after this many seconds; a granted control that is not used
    // for the agency's idle limit (agencies.desk_control_idle_minutes, default below, 1-60) ends by itself.
    'control_request_seconds' => (int) env('DESK_CONTROL_REQUEST_SECONDS', 60),
    'control_idle_minutes_default' => 5,
    'control_idle_minutes_min' => 1,
    'control_idle_minutes_max' => 60,
    'control_actions_per_report' => 50,

    // Selector / note limits for a highlight.
    'selector_max' => 500,
    'note_max' => 200,
];

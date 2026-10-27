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

    // Selector / note limits for a highlight.
    'selector_max' => 500,
    'note_max' => 200,
];

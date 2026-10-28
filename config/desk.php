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

    // Inactivity: a LIVE session in which nobody did anything real (no input by the agent in the CoreX page or a shared tab, nothing recorded server-side)
    // for this many minutes is disconnected (ended_by 'inactive'). Heartbeat pings alone do not count. 0 = off. Platform tunable, not an agency setting.
    'inactive_minutes' => (int) env('DESK_INACTIVE_MINUTES', 30),

    // After the agent's tab says it is leaving (page change or close) the next page has this long to reconnect.
    'leaving_grace_seconds' => (int) env('DESK_LEAVING_GRACE_SECONDS', 30),

    // Hard cap: a session is closed (timeout) this many hours after it started, however alive it looks. 0 = no cap.
    'max_session_hours' => (int) env('DESK_MAX_SESSION_HOURS', 8),

    // The stale-session sweep (expireStale) runs from the `desk:expire` schedule every minute AND lazily on reads; a lazy run happens at most once per this
    // many seconds (a cache lock), so a heartbeat or a poll never repeats the sweep's table scans. 0 = every read.
    'expire_throttle_seconds' => (int) env('DESK_EXPIRE_THROTTLE_SECONDS', 5),

    // A joining support person's code is tested against at most this many waiting sessions (newest first), of their own scope.
    'join_candidate_cap' => (int) env('DESK_JOIN_CANDIDATE_CAP', 200),

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
    // The agent's browser reports what it replayed in batches (~1.5 s), and flushes the last one a moment AFTER control ended: a report for the tab control
    // just ended on is still recorded (marked late) for this many seconds.
    'control_report_grace_seconds' => (int) env('DESK_CONTROL_REPORT_GRACE_SECONDS', 10),

    // Rate limits (AppServiceProvider: desk-state / desk-live). Each counts per signed-in user AND per route, so one busy route can never use up another's
    // allowance (spec section 14a): state-changing calls (ask, Allow, take back, join, end) and the chatty ones (pointer, actions, heartbeat, polls).
    'state_per_minute' => (int) env('DESK_STATE_PER_MINUTE', 60),
    'live_per_minute' => (int) env('DESK_LIVE_PER_MINUTE', 600),

    // The agent's CoreX tabs (spec section 4f): a tab that says "leaving" has this long to come back (it is only loading its next page) before it counts
    // as closed; a tab that stops pinging for tab_silent_seconds (crashed) is dropped.
    'tab_leave_grace_seconds' => (int) env('DESK_TAB_LEAVE_GRACE_SECONDS', 3),
    // ...a tab that support is controlling gets this long (it may be loading the next page after a click support replayed; that must not end the take-over).
    'tab_leave_grace_controlled_seconds' => (int) env('DESK_TAB_LEAVE_GRACE_CONTROLLED_SECONDS', 15),
    'tab_silent_seconds' => (int) env('DESK_TAB_SILENT_SECONDS', 100),

    // Diagnostics (off by default; NOT an agency setting): the console shows a timing panel - agent-to-console and server-to-console times per event type,
    // snapshot size, the ten biggest mutation batches, relay round trip (spec section 4e).
    'timing_overlay' => (bool) env('DESK_TIMING_OVERLAY', false),

    // The session message strip (spec section 4h): the longest line either side may send.
    'message_max' => (int) env('DESK_MESSAGE_MAX', 300),

    // Selector / note limits for a highlight.
    'selector_max' => 500,
    'note_max' => 200,
];

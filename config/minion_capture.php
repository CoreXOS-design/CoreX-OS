<?php

// AT-284 — Chrome minion P24 area capture.
// Master schedule switch is OFF by default; every value here is an
// agency-overridable default (see minion_capture_settings). No hardcoded law.
return [
    'enabled'           => false,   // nightly schedule master switch (per-agency setting overrides)
    'targets_per_night' => 8,       // suburbs captured per nightly run (cadence is a setting)
    'cycle_days'        => 7,       // target to cycle the whole ticked universe (weekly)
    'run_at'            => '02:30',  // off-peak capture window start (rig local time)
    'run_days'          => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
    'pace_min_seconds'  => 20,      // polite gap between suburb page loads (min) — politeness, not evasion
    'pace_max_seconds'  => 55,      // polite gap between suburb page loads (max)
    'nav_timeout_ms'    => 45000,
    'alert_enabled'     => true,
    'source_site'       => 'property24',
    'p24_base'          => 'https://www.property24.com',
    'node_binary'       => env('MINION_NODE', 'node'),
    'chromium_path'     => env('MINION_CHROMIUM', '/usr/bin/chromium'),
    'node_script'       => 'resources/minion/p24-capture.cjs',
    // Auth to our OWN ingest endpoint (the extension's existing service-token path).
    // Token + service user live in .env only — never in code/db.
    'ingest_url'        => env('MINION_INGEST_URL'),
    'ingest_token'      => env('MINION_INGEST_TOKEN'),
];

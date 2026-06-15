<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | These are the machine endpoints hit by the browser extensions
    | (portal-capture, wa-capture). Their POSTs originate from a
    | chrome-extension:// origin, so the browser issues a CORS preflight
    | (OPTIONS) and requires valid Access-Control-Allow-* headers on the
    | response. Without this config Laravel's HandleCors middleware is inert
    | and the browser blocks the request before it reaches the controller —
    | which is exactly what bit wa-capture (AT-44): the request never landed,
    | nothing was stored. Auth is a per-device Bearer token (not cookies), so
    | credentials are NOT included and a wildcard origin is valid + safe.
    |
    */

    'paths' => [
        'communications/wa/*',   // wa-capture: ingest + contact-check (AT-44)
        'portal-captures/*',     // portal-capture ingest (parity)
        'api/*',
    ],

    'allowed_methods' => ['*'],

    // No cookies are sent (Bearer-token auth), so '*' is valid for these
    // machine endpoints. The pattern explicitly green-lights any extension id.
    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => ['#^chrome-extension://#'],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Bearer token only — never cookie/session auth on these endpoints.
    'supports_credentials' => false,

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            // Loopback by default: Reverb sits behind nginx (`location /app`), it is never meant to be reachable directly. Set REVERB_SERVER_HOST only if a
            // box really needs another interface.
            'host' => env('REVERB_SERVER_HOST', '127.0.0.1'),
            // 8080 is taken on this box (a node service); Reverb listens on loopback 8085 behind nginx `location /app`.
            'port' => env('REVERB_SERVER_PORT', 8085),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            // CoreX Desk: raised together with max_message_size below so a client frame always fits.
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 1_000_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                // Browser origins (host names) allowed to open a socket. Defaults to this app's own host (APP_URL) - NOT '*'. The CoreX Chrome extension's
                // socket comes from "chrome-extension://<id>", so a box that serves extension tabs lists that id as well (REVERB_ALLOWED_ORIGINS=app.example.com,<extension id>);
                // '*' is still possible but has to be asked for. Origin is not the security boundary (a channel needs authorisation), it is defence in depth.
                'allowed_origins' => array_values(array_filter(explode(',', (string) env('REVERB_ALLOWED_ORIGINS', (string) parse_url((string) env('APP_URL', ''), PHP_URL_HOST))))),
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 1_000_000),
                'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
                'rate_limiting' => [
                    // On by default. Per connection: Desk's busiest sender (a mirrored page: pointer ~25/s + batched frames) stays well under 4000 messages a
                    // minute; a connection flooding the channel has its excess messages refused (REVERB_APP_RATE_LIMIT_TERMINATE=true cuts it off instead). Lower or raise with the keys below.
                    'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', true),
                    'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 4000),
                    'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Browser connection (CoreX Desk)
    |--------------------------------------------------------------------------
    |
    | What a BROWSER connects to — the public nginx host on 443 (wss), unlike
    | REVERB_HOST / REVERB_PORT / REVERB_SCHEME above, which are where the app
    | publishes to Reverb on loopback. Rendered into the page by the Desk
    | partial, so a key change never needs a front-end rebuild.
    | Spec: .ai/specs/corex-desk.md §7.1
    |
    */

    'public' => [
        'host' => env('REVERB_PUBLIC_HOST'),
        'port' => (int) env('REVERB_PUBLIC_PORT', 443),
        'scheme' => env('REVERB_PUBLIC_SCHEME', 'https'),
    ],

];

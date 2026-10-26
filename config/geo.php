<?php

declare(strict_types=1);

/**
 * Phase 11a — geocoding config consolidation.
 *
 * Pulls into one file what was previously scattered across services.php
 * (services.google.geocoding_api_key, services.nominatim.*) plus the new
 * rate-limit caps + cache TTLs introduced by the GeocodeRateLimiter and
 * GeocodeCache wrappers. The legacy services.* entries remain for
 * backwards compatibility — AddressResolverService still reads
 * services.google.geocoding_api_key as the primary, with this file's
 * geocoding.google_api_key as a fallback alias.
 *
 * Per-environment defaults (set in .env):
 *   local   GEOCODING_ENV_DAILY_CAP=100  GEOCODING_USER_DAILY_CAP=30
 *   demo    GEOCODING_ENV_DAILY_CAP=50   GEOCODING_USER_DAILY_CAP=15
 *   staging GEOCODING_ENV_DAILY_CAP=200  GEOCODING_USER_DAILY_CAP=50
 *   live    GEOCODING_ENV_DAILY_CAP=500  GEOCODING_USER_DAILY_CAP=100
 */
return [
    'geocoding' => [
        'enabled'                => filter_var(env('GEOCODING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'environment_daily_cap'  => (int) env('GEOCODING_ENV_DAILY_CAP', 100),
        'user_daily_cap'         => (int) env('GEOCODING_USER_DAILY_CAP', 30),
        'admin_override_enabled' => filter_var(env('GEOCODING_ADMIN_OVERRIDE', false), FILTER_VALIDATE_BOOLEAN),
        'google_api_key'         => env('GOOGLE_GEOCODING_API_KEY'),

        // Cache TTLs (days).
        'cache_success_ttl_days' => (int) env('GEOCODING_CACHE_SUCCESS_TTL_DAYS', 90),
        'cache_failure_ttl_days' => (int) env('GEOCODING_CACHE_FAILURE_TTL_DAYS', 7),

        /*
         * Accepted area — which Google results the resolver may keep. Spec: .ai/specs/geocoding-spec.md
         * "Accepted area". An agency picks its country with agencies.geocode_country; null = default_country.
         *
         *   google_component  sent as components=country:<code> and compared with the country Google
         *                     returns. A result Google places in this country is ALWAYS kept.
         *   areas             rectangles [south, west, north, east] — the fallback test used only when
         *                     Google returned no country. They are a union (a single rectangle around
         *                     South Africa would also contain Windhoek and Gaborone).
         *   provinces         bias hints only (Google `bounds=`), keyed by the lower-case letters of the
         *                     province name. They never reject anything.
         */
        'default_country' => 'ZA',
        'countries' => [
            'ZA' => [
                'google_component' => 'ZA',
                'areas' => [
                    [-34.95, 16.30, -28.50, 33.00],
                    [-28.50, 20.00, -22.00, 33.00],
                ],
                'provinces' => [
                    'gauteng'       => [-26.90, 27.20, -25.10, 29.00],
                    'kwazulunatal'  => [-32.00, 28.50, -27.00, 33.00],
                    'westerncape'   => [-34.90, 17.80, -31.40, 24.10],
                    'easterncape'   => [-34.20, 22.70, -30.10, 30.30],
                    'northerncape'  => [-31.50, 16.40, -26.80, 25.30],
                    'freestate'     => [-30.70, 24.20, -26.60, 29.80],
                    'limpopo'       => [-25.20, 26.40, -22.10, 31.60],
                    'mpumalanga'    => [-27.70, 28.30, -24.50, 32.00],
                    'northwest'     => [-28.20, 22.50, -24.60, 28.20],
                ],
            ],
        ],
    ],
];

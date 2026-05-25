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
    ],
];

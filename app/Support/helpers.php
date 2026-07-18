<?php

/**
 * Global helper functions (autoloaded via composer.json "autoload.files").
 *
 * Keep this file tiny and dependency-light — it loads on every request.
 */

use App\Services\Features\AgencyFeatureService;

if (! function_exists('feature')) {
    /**
     * Is a per-agency FEATURE enabled for the current effective agency?
     *
     * Feature = "does this AGENCY use this module" — ORTHOGONAL to permission
     * ("may this USER touch it"). Spec: .ai/specs/corex-feature-registry.md §6.2.
     *
     * Mirrors the intent of the @permission/hasPermission pair for features:
     *   feature('rentals')  ->  AgencyFeatureService::enabled('rentals')
     */
    function feature(string $key): bool
    {
        return app(AgencyFeatureService::class)->enabled($key);
    }
}

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

if (! function_exists('asset_v')) {
    /**
     * asset() with an automatic cache-busting query string — the file's own
     * mtime, so a browser that cached an old copy fetches fresh the moment
     * the file changes on disk, with NO manual version number to remember to
     * bump on deploy. For public/ files not run through the Vite/Mix asset
     * pipeline (e.g. public/js/corex-ad-render.js), where a hand-written
     * "?v=1" is easy to ship a fix behind without ever changing.
     *
     * Falls back to a static "?v=1" only if the file genuinely can't be
     * stat'd (shouldn't happen for a real public asset) — never breaks the
     * page over a missing file.
     */
    function asset_v(string $path): string
    {
        $full  = public_path($path);
        $stamp = is_file($full) ? (string) filemtime($full) : '1';

        return asset($path) . '?v=' . $stamp;
    }
}

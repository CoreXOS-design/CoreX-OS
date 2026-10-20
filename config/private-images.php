<?php

/**
 * Abuse protection for GET /private-image/{token} (PrivateImageController) - rental-inspection audit H1.
 *
 * This route serves EVERY inspection, inventory and portal picture, so one screen (a move-out with a few hundred photos, or the In and Out
 * compared side by side) legitimately fires hundreds of requests inside a minute. The old flat `throttle:120,1` counted per IP address and
 * broke those galleries (and every agent behind one office address). The route is session-less by design, so the limits are, from the tightest:
 *
 *   per token    - the SAME picture link asked for over and over (a loop, a scraper). A real page asks for each link once, and the browser
 *                  caches it for up to PrivateImageService::MAX_BROWSER_CACHE_SECONDS.
 *   per browser  - one signed-in browser (hash of its session cookie; staff screens) or, with no cookie, the public viewer's address. A
 *                  gallery of several hundred photos fits well inside it.
 *   per address  - the ceiling for everyone behind one address (an office, a mobile network); far above any one gallery.
 *
 * Brute force is not what these protect against: the token is encrypted and the URL HMAC-signed, so a made-up link never resolves (and
 * always answers the same empty 404). They only stop one client from hammering the disk.
 */
return [
    'per_token_per_minute' => (int) env('PRIVATE_IMAGE_PER_TOKEN_PER_MINUTE', 60),
    'per_browser_per_minute' => (int) env('PRIVATE_IMAGE_PER_BROWSER_PER_MINUTE', 1500),
    'per_address_per_minute' => (int) env('PRIVATE_IMAGE_PER_ADDRESS_PER_MINUTE', 4000),
];

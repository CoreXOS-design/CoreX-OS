<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Website Listing Sync
    |--------------------------------------------------------------------------
    | One-way queue-based push from Nexus to the public website.
    | Set WEBSITE_SYNC_ENABLED=false to disable syncing without removing code.
    */
    'website_sync_url'     => env('WEBSITE_SYNC_URL', ''),
    'website_sync_token'   => env('WEBSITE_SYNC_TOKEN', ''),
    'website_sync_enabled' => (bool) env('WEBSITE_SYNC_ENABLED', false),

    // Public-facing URL for the website (used for "View on HFC Premium" link).
    // Listing detail pattern: {website_public_url}/listings/{external_id}
    'website_public_url'   => env('WEBSITE_PUBLIC_URL', env('WEBSITE_SYNC_URL', '')),

    // Base URL of the public CoreX listing website where individual properties
    // render. Used to compose each property's canonical public URL:
    //   {public_website_url}/property/{slug}-{id}
    // The website resolves a property by the trailing id, so the slug is purely
    // cosmetic/SEO — see Property::getPublicUrlAttribute().
    // Default is the live production domain (verified: it serves the
    // /property/{slug}-{id} path). Override with PUBLIC_WEBSITE_URL to point at
    // a local/staging custom-website dev server (e.g. http://91.99.130.85:1050).
    'public_website_url'   => env('PUBLIC_WEBSITE_URL', 'https://www.hfcoastal.co.za'),

    // Base URL of the CoreX MARKETING site (corexweb.co.za) — a different site
    // from public_website_url above, which is an agency's own listing website.
    // Used to compose each webinar's public registration link:
    //   {corex_website_url}/webinars/{slug}
    // CoreX has no other way to learn that hostname, and the link is the thing
    // the admin console exists to hand out — built from app.url it would point
    // at this API instead of at the registration page.
    // Spec: .ai/specs/webinar-registration.md §4.3
    'corex_website_url'    => env('COREX_WEBSITE_URL', 'https://corexweb.co.za'),

    /*
    |--------------------------------------------------------------------------
    | Agency Public API — auth debug
    |--------------------------------------------------------------------------
    | When true, AgencyApiKeyResolver logs the exact reason a website-API bearer
    | token is rejected (prefix + reason; never the secret). Temporary diagnostic
    | for 401s — turn off after use. Spec: .ai/specs/agency-public-api.md §3.4.
    */
    'website_api_auth_debug' => (bool) env('COREX_WEBSITE_API_AUTH_DEBUG', false),
];

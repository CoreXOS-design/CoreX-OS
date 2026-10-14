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

    // LAST-RESORT base URL for a property's public link when the property has NO owning agency. The link a client is sent is
    // per agency - the agency's own website (Company Settings), else CoreX's public property page for that agency -
    // see Property::getPublicUrlAttribute(). This value is never an agency's site: it defaults to this install's own URL
    // (the demo instance keeps its demo domain). Override with PUBLIC_WEBSITE_URL for a local/staging site.
    'public_website_url'   => env('PUBLIC_WEBSITE_URL', strtolower((string) env('COREX_INSTANCE_ROLE')) === 'demo'
        ? 'https://www.corexdemo.co.za'
        : env('APP_URL', 'http://localhost')),

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

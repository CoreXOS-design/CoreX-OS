<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Wishlist migration
    |--------------------------------------------------------------------------
    |
    | Settings for the unified buyer wishlist migration (spec
    | .ai/specs/unified-buyer-wishlist-spec.md). The system_user_email is
    | the account whose id is stamped onto migrated ContactMatch rows when
    | the source buyer_preferences row has no updated_by_user_id. Prompt 08
    | creates this user before the live migration runs; until then the
    | dry-run logs it as a placeholder.
    |
    */
    'wishlist_migration' => [
        'system_user_email' => env('COREX_SYSTEM_USER_EMAIL', 'system@corexos.co.za'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Instance role — primary vs demo
    |--------------------------------------------------------------------------
    |
    | Spec: .ai/specs/demo-access-control.md §3
    |
    | One codebase, two roles. `primary` is the real install (live/staging);
    | `demo` is demo1.corexos.co.za, whose database is destroyed every 3 days.
    |
    | This flag exists because there is NO usable "am I demo?" predicate today:
    |   - config('app.env_label') is COSMETIC — it only colours the banner.
    |   - DemoLoginController::isEnabled() requires !environment('production'),
    |     but the demo host runs APP_ENV=production, so it is false there.
    | Never gate security on a display string.
    |
    | The durable records (grants, T&C, sessions, page views) live in the
    | PRIMARY database. A demo instance reaches primary over the Agency Public
    | API using an AgencyApiKey bearer token with the demo:gate +
    | demo:telemetry scopes.
    |
    */
    'instance' => [
        'role'          => env('COREX_INSTANCE_ROLE', 'primary'),
        'control_url'   => env('COREX_DEMO_CONTROL_URL'),
        'control_token' => env('COREX_DEMO_CONTROL_TOKEN'),

        // Where the invitation email sends the prospect. Set on PRIMARY (it is
        // primary that mails the grant), not on the demo host.
        'demo_url'      => env('COREX_DEMO_URL', 'https://demo1.corexos.co.za'),

        // How long a demo host caches primary's verdict on a session. This IS the
        // revoke latency: a revoked grant keeps working for up to this many
        // seconds. The admin UI's revoke dialog quotes it. Raising it trades
        // safety for fewer round trips — don't, without saying so on that dialog.
        'gate_cache_ttl' => (int) env('COREX_DEMO_GATE_CACHE_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Domain events
    |--------------------------------------------------------------------------
    |
    | Spec: .ai/specs/corex-domain-events-spec.md Section 6, E6 + Section 9
    | rollback plan. The audit_enabled flag is an emergency-disable switch
    | for the wildcard RecordDomainEvent listener — events still fire, but
    | the audit-log write is skipped. Default: true.
    |
    */
    'domain_events' => [
        'audit_enabled' => env('COREX_DOMAIN_EVENTS_AUDIT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime deprecation listeners
    |--------------------------------------------------------------------------
    |
    | Spec: .ai/specs/unified-buyer-wishlist-spec.md Section 10 (D11 Phase 1).
    | The buyer_preferences listener logs a WARNING to the `deprecation` log
    | channel whenever any query touches the deprecated buyer_preferences
    | table. Default ON. Disable in production if it generates noise without
    | value.
    |
    */
    'deprecation' => [
        'buyer_preferences_listener' => env('COREX_DEPRECATION_BUYER_PREFERENCES_LISTENER', true),
    ],

];

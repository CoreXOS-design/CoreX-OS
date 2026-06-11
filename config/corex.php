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

    /*
    |--------------------------------------------------------------------------
    | Privacy policy (POPIA)
    |--------------------------------------------------------------------------
    |
    | Phase 9c (AT-16) — controls for the canonical, always-available public
    | privacy policy at /privacy-policy[/{agencySlug}].
    |
    | draft_banner_enabled: while true, the page renders a visible "Draft —
    | pending legal review" banner. The generated POPIA content is a sound
    | baseline but has NOT been signed off by a lawyer. Once legal review is
    | complete, flip this to false (env COREX_PRIVACY_POLICY_DRAFT_BANNER=false)
    | and the banner disappears for the public. Default: true (unreviewed).
    |
    */
    'privacy_policy' => [
        'draft_banner_enabled' => env('COREX_PRIVACY_POLICY_DRAFT_BANNER', true),
    ],

];

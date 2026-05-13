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

];

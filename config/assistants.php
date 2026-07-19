<?php

/*
|--------------------------------------------------------------------------
| Assistants (AT-267)
|--------------------------------------------------------------------------
|
| Spec: .ai/specs/assistants-feature-spec.md
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | The property-upload locked set
    |--------------------------------------------------------------------------
    |
    | An assistant may NEVER create a listing. Johan's rule, and it is absolute:
    | it is not a default, not a matrix option, and not grantable by anyone —
    | including the agent, including an admin, including a super-admin.
    |
    | ONE LIST, FOUR LAYERS. A slug list on its own is provably not enough, because
    | several property-creation paths in CoreX carry NO permission key at all (the
    | classic store endpoint, the wizard mutation routes, the mobile API create +
    | image upload, portal pull, and prospecting import — spec §2.3). So this list
    | is consumed by:
    |
    |   1. AssistantPermissionResolver::allows() — denies these keys outright,
    |      BEFORE consulting the matrix. (app/Services/Assistants/)
    |   2. DenyAssistantPropertyWrite middleware — covers the keyless routes the
    |      resolver cannot see. (Prompt E)
    |   3. The matrix editor UI — renders these rows disabled + tooltipped. (Prompt H)
    |   4. AssistantAssignmentPermission::saving() — forces granted = false when
    |      is_locked, so a hand-crafted POST cannot persist a granted lock.
    |
    | DEAD KEYS ARE LOCKED TOO. `create_properties`, `publish_properties` and
    | `listings.create|edit` are defined in config/corex-permissions.php and checked
    | NOWHERE in the codebase today. They are listed here anyway, so that the day
    | someone wires one of them up to a real gate, it is already closed to assistants
    | rather than quietly open.
    |
    | DELIBERATELY NOT LOCKED (Johan, D3): `mic.upload_reports` and `mic.edit_address`.
    | Uploading a CMA / market report does create tracked-property records via
    | match-or-create, but that is intelligence capture, not putting a listing on the
    | agency's books — and it is precisely the drudge work an assistant exists to
    | absorb. Only paths that create AGENCY STOCK are locked.
    |
    */
    'property_upload_locked_set' => [
        // ── Live gates ──
        'properties.create',      // PropertyWizardController:34, :163 — the real create gate
        'import_listings',        // routes/web.php:945 — CSV bulk listing import
        'access_import_listings', // the sidebar entry for the above
        'manage_p24',             // routes/web.php:661 — P24 listing import
        'mic.merge_duplicates',   // merging tracked properties

        // ── Dead keys — locked now so they can never be revived into a hole ──
        'create_properties',
        'publish_properties',
        'listings.create',
        'listings.edit',
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin-access permissions default OFF (Johan's rule, 2026-07-19)
    |--------------------------------------------------------------------------
    |
    | UNLIKE the hard lock above, this is a soft DEFAULT, not a ban. When an
    | assistant is created for an agent who holds admin-access permissions (most
    | acutely, an admin), those permissions land in the fresh matrix switched
    | OFF (granted = false, is_locked = false). The agent/admin can still turn any
    | of them on deliberately — but a brand-new assistant never starts able to
    | reach the soft-delete register, backups, payroll, agency settings, the role
    | manager, the finance engine or trust-interest just because their agent can.
    |
    | Everything else the agent holds still defaults ON (the "immediately useful"
    | copy). Only these management/money/owner sections invert to opt-in.
    |
    | Driven by the permission catalogue's `section` (nexus_permissions.section),
    | so the set auto-tracks new permissions added to these areas instead of a
    | hand-list that rots. Adjust the section list here — never hardcode keys.
    |
    | Consumed by AssistantMatrixSnapshotService::seed() on a FRESH snapshot only
    | (drift rows already arrive OFF).
    */
    'admin_default_off_sections' => [
        'admin',           // soft-delete register, backups, server health, API keys, testimonials
        'role-manager',    // viewing/editing roles + permissions
        'settings',        // agency settings
        'agencies',        // agency management
        'franchise-admin', // cross-agency / franchise administration
        'finance-engine',  // the commission/revenue engine
        'trust-interest',  // trust account interest — money + compliance
        'payroll',         // payroll run + reports
        'remote-access',   // remote/developer access
        'supervision',     // BM/admin oversight surfaces
    ],

];

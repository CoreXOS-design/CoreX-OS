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

];

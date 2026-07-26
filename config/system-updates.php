<?php

/**
 * System Updates — fixed vocabularies.
 *
 * Spec: .ai/specs/system-updates.md §5, §6.
 *
 * These sets are HARDCODED by decision (spec §18 decision 10, Johan 2026-07-26).
 * There is no settings table, no admin UI to edit them, and no per-agency knob.
 *
 * On SYSTEM.md §3 (No Hardcoding): §3 exists so that AGENCIES can configure THEIR
 * OWN terminology (property types, deal stages, contact types). This vocabulary is
 * not agency terminology — it describes what CoreX the product did in a release, it
 * is authored only by the System Owner, and it is identical for every tenant by
 * definition. A settings table would hand agencies a knob over a vocabulary they do
 * not own and cannot meaningfully change.
 *
 * Every surface reads from here — the modal chip, the admin form dropdown, the
 * archive filter, AND the validation allow-list — so the vocabulary can never drift
 * apart across surfaces.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Update types (spec §5)
    |--------------------------------------------------------------------------
    | The chip a user reads in half a second to triage: "New Feature" means go
    | learn something, "Fixed" means note it and carry on.
    |
    | 'token' is a CoreX design-system colour token (resources/css/corex.css).
    | 'fallback' is that token's documented value, used in the var(--token, #hex)
    | pattern required by STANDARDS "Design System Compliance".
    */
    'types' => [
        'feature' => [
            'label'    => 'New Feature',
            'token'    => '--ds-cyan',
            'fallback' => '#00b4d8',
            'sort'     => 1,
        ],
        'improvement' => [
            'label'    => 'Improvement',
            'token'    => '--ds-amber',
            'fallback' => '#f59e0b',
            'sort'     => 2,
        ],
        'fix' => [
            'label'    => 'Fixed',
            'token'    => '--ds-emerald',
            'fallback' => '#10b981',
            'sort'     => 3,
        ],
    ],

    /** Fallback chip for a stored type no longer in the list (spec §9.4). */
    'unknown_type' => [
        'label'    => 'Update',
        'token'    => '--text-secondary',
        'fallback' => '#6b7280',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audiences (spec §6)
    |--------------------------------------------------------------------------
    | "admins" is resolved by CAPABILITY, never by role name — the roles table is
    | per-agency and agency-editable, so a hardcoded role-name list would silently
    | deliver an admin-only update to nobody on any agency that renamed its roles.
    | See App\Services\SystemUpdateService::userIsAdminAudience().
    */
    'audiences' => [
        'all'    => ['label' => 'Everyone',    'hint' => 'Every CoreX user, in every agency.'],
        'admins' => ['label' => 'Admins only', 'hint' => 'Users who can see the Admin section of the sidebar, plus System Owners.'],
    ],

    /** The permission key that defines "admin" for audience purposes (spec §6.1). */
    'admin_permission' => 'sidebar.section.admin',

    /** Max cards in one sitting; the rest go to the archive (spec §8.3). */
    'modal_cap' => 5,

    /** Cache key for the published list (spec §9.6). */
    'cache_key' => 'system_updates.published',
];

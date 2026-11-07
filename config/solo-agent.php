<?php

/**
 * Solo agent accounts - .ai/specs/solo-agent-accounts.md.
 *
 * A solo agent is an agency (agencies.account_kind = 'solo') with exactly one user. Everything an agency
 * would reasonably want different lives here, not in code constants (project rule 9).
 */
return [

    /** The flat price of a single-agent account, rand per month (spec section 5a; decision 2). */
    'monthly_fee_zar' => 450,

    /** AI Template Builder creations per calendar month (spec section 5a; decision 6). A normal agency's default is 20. */
    'ai_template_monthly_limit' => 5,

    /**
     * Features that are OFF for a solo agency and cannot be switched on by anyone inside it. Registry keys from
     * config/corex-features.php. Applied inside AgencyFeatureService, so the sidebar, every feature-gated route,
     * the Features page and the permissions owned by these features all follow one decision.
     *
     * Deliberately NOT here: 'company-settings' (its sections are merged into Settings in build step 5, not removed),
     * and 'commission-management' (unverified - confirm what it owns before locking; the spec says no commission
     * splits, which needs a team to split with).
     */
    'locked_features' => [
        'syndication-p24',   // portal syndication - the one thing a solo agent can never do
        'syndication-pp',
        'public-website',
        'auctions',
        'multi-branch',
        'assistants',
        'payroll',
        'leave',
        'staff-take-on',
        'agent-onboarding',
        'role-manager',
    ],

    /**
     * Permission keys a solo agent is refused no matter what any role grants. Enforced in PermissionService, not by
     * editing role rows: the deploy-time sync re-adds missing grants to every agency's roles, so a removed grant
     * would come back; a refusal at the check cannot.
     */
    'denied_permissions' => [
        // Role Manager
        'access_role_manager', 'edit_permissions', 'change_user_roles', 'roles.view', 'roles.edit',
        // Users / team
        'manage_users', 'users.view', 'users.create', 'users.edit', 'users.archive', 'users.login_history.view',
        // Features and rollout
        'agency_features.manage', 'manage_feature_rollout',
        // Branches
        'manage_branches', 'manage_branch_settings',
        'branches.view_all', 'branches.switch', 'branches.edit_all', 'branches.self_assign_managed',
        // Portal administration
        'manage_p24', 'p24.manage',
        // The CoreX team's own Solo Agents section - never held by any agency user, solo or not.
        'solo_agents.view', 'solo_agents.create', 'solo_agents.manage', 'solo_agents.archive',
    ],
];

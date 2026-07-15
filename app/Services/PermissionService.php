<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

class PermissionService
{
    /** @var array<string, string[]> Cached permissions keyed by "agency:role" */
    protected static array $cache = [];

    /** @var array<string, array<string, ?string>> Cached scopes keyed by "agency:role" */
    protected static array $scopeCache = [];

    /** @var bool|null Whether the role_permissions table has ANY rows (fresh-DB guard) */
    protected static ?bool $seeded = null;

    /**
     * AT-265 — the unseeded POSTURE. NULL = derive from the environment.
     *
     * An empty `role_permissions` table used to mean ALLOW EVERYONE EVERYTHING. On a server that
     * is not a graceful default, it is a total, silent removal of permission enforcement: any
     * deploy, seed, migration or reconcile accident that empties or soft-deletes that table
     * (RolePermission uses SoftDeletes, and `exists()` does not see trashed rows — so a reconcile
     * that soft-deleted every grant would read as "unseeded") disabled the entire permission
     * system platform-wide, with nothing logged and nothing to notice it by.
     *
     * It now DENIES. The historic allow-all survives in exactly one place — the test suite, whose
     * standing convention is an unseeded table (see tests/TestCase.php, and the ~40 test files
     * that document "unseeded → allow-all" as their premise). That bypass keys off the testing
     * environment and is therefore unreachable on any server: there is no config, no env var, and
     * no .env line that can switch a production box back to failing open.
     *
     * A test proves the production posture by calling forceProductionPosture() — that is the
     * "control isolated" the ticket asks for. clearCache() resets it, so it cannot leak between
     * tests.
     */
    protected static ?bool $allowAllWhenUnseeded = null;

    /** @var array<string, bool> Whether a given agency owns any grants (provisioned?) */
    protected static array $agencyHasGrants = [];

    /**
     * Resolve which agency_id the grants for this context live under
     * (.ai/specs/roles-permissions.md §4.1).
     *
     * - No agency context (owners, fresh/test DBs) → NULL (global templates).
     * - Agency that owns its own grants → that agency id.
     * - Agency not yet provisioned → NULL fallback to the global templates,
     *   so behaviour matches pre-migration until the agency is provisioned.
     */
    protected static function grantsAgencyId(?int $agencyId): ?int
    {
        if ($agencyId === null) {
            return null;
        }

        $k = (string) $agencyId;
        if (!isset(static::$agencyHasGrants[$k])) {
            static::$agencyHasGrants[$k] = RolePermission::where('agency_id', $agencyId)->exists();
        }

        return static::$agencyHasGrants[$k] ? $agencyId : null;
    }

    /**
     * AT-265 — an owner used the bypass. Record it ONLY when it is genuinely break-glass, i.e.
     * when the grants table is empty and the bypass is the only thing letting anyone in.
     *
     * An owner on a healthy system is just an owner; logging that on every request would bury the
     * one line that matters under millions that don't.
     */
    protected static function auditBreakGlass(User $user, string $context): void
    {
        if (static::grantsExist() || static::allowAllWhenUnseeded()) {
            return;
        }

        \App\Services\Security\PermissionLockdownAlarm::recordBreakGlass($user, $context);
    }

    /**
     * Force the PRODUCTION posture (deny on an empty grants table) inside the test suite, so the
     * AT-265 regression can prove the control instead of inheriting the suite's allow-all premise.
     */
    public static function forceProductionPosture(): void
    {
        static::$allowAllWhenUnseeded = false;
    }

    /**
     * Does an empty `role_permissions` table grant access?
     *
     * On a server: NEVER. In the test suite: yes, preserving the convention the suite was written
     * against (an unseeded table). See the $allowAllWhenUnseeded docblock.
     */
    protected static function allowAllWhenUnseeded(): bool
    {
        if (static::$allowAllWhenUnseeded !== null) {
            return static::$allowAllWhenUnseeded;
        }

        return app()->runningUnitTests();
    }

    /**
     * Are there ANY grants at all? Memoised per process (reset by clearCache()).
     *
     * Deliberately unscoped by agency: this asks whether the permission SYSTEM is provisioned,
     * not whether one agency is. `grantsAgencyId()` answers the per-agency question separately.
     */
    protected static function grantsExist(): bool
    {
        if (static::$seeded === null) {
            static::$seeded = RolePermission::exists();
        }

        return static::$seeded;
    }

    /**
     * The single decision point for "the grants table is empty — now what?"
     *
     * Returns TRUE when the caller may fall back to allow-all (test suite only). Returns FALSE
     * when the caller MUST deny — and raises the alarm on the way out, so a locked-down platform
     * is never a silent one. Fixing the class in one place, not at each of the two call sites
     * (BUILD_STANDARD §6) — the original defect was duplicated across both.
     */
    protected static function unseededGrantsAccess(string $context): bool
    {
        if (static::allowAllWhenUnseeded()) {
            return true;
        }

        \App\Services\Security\PermissionLockdownAlarm::raise($context);

        return false;
    }

    /**
     * Get all permission_keys for a given role within an agency context
     * (cached per-request).
     *
     * @return string[]
     */
    public static function getPermissionsForRole(string $role, ?int $agencyId = null): array
    {
        $resolved = static::grantsAgencyId($agencyId);
        $key      = ($resolved ?? 'null') . ':' . $role;

        if (!isset(static::$cache[$key])) {
            static::$cache[$key] = static::scopedGrantQuery($role, $resolved)
                ->pluck('permission_key')
                ->all();
        }

        return static::$cache[$key];
    }

    /**
     * Get scope values for a role within an agency context (cached per-request).
     * Returns array: permission_key => scope ('own'|'branch'|'all'|null)
     */
    protected static function getScopesForRole(string $role, ?int $agencyId = null): array
    {
        $resolved = static::grantsAgencyId($agencyId);
        $key      = ($resolved ?? 'null') . ':' . $role;

        if (!isset(static::$scopeCache[$key])) {
            static::$scopeCache[$key] = static::scopedGrantQuery($role, $resolved)
                ->whereNotNull('scope')
                ->pluck('scope', 'permission_key')
                ->all();
        }

        return static::$scopeCache[$key];
    }

    /**
     * Base query for a role's grants, filtered to the resolved agency
     * (NULL = global template rows).
     */
    protected static function scopedGrantQuery(string $role, ?int $resolvedAgencyId)
    {
        $q = RolePermission::where('role', $role);

        return $resolvedAgencyId === null
            ? $q->whereNull('agency_id')
            : $q->where('agency_id', $resolvedAgencyId);
    }

    /**
     * Get the data scope for a user on a specific module.
     *
     * Looks up role_permissions where permission_key = '{module}.view'
     * Returns: 'own', 'branch', 'all', or null (no access)
     * Owner role always returns 'all'.
     */
    public static function getDataScope(User $user, string $module): ?string
    {
        // Owner's REAL role always gets full scope — even when using View As
        if ($user->isOwnerRole()) {
            static::auditBreakGlass($user, "getDataScope({$module})");

            return 'all';
        }

        $role     = $user->effectiveRole();
        $agencyId = $user->effectiveAgencyId();

        // Owner role always gets full scope (covers edge cases)
        $roleModel = Role::allRoles($agencyId)->firstWhere('name', $role);
        if ($roleModel && $roleModel->is_owner) {
            static::auditBreakGlass($user, "getDataScope({$module})");

            return 'all';
        }

        // AT-265 — THE SECOND FAIL-OPEN. The ticket names userHasPermission(); this one sat right
        // beside it and was never mentioned. On an empty grants table it handed 'all' to every
        // admin and 'branch' to every branch_manager — a data-visibility grant nobody had made.
        // It now returns NULL, which every scopeVisibleTo() reads as "no rows" (whereRaw 1=0).
        if (! static::grantsExist()) {
            if (! static::unseededGrantsAccess("getDataScope({$module})")) {
                return null; // every scopeVisibleTo() reads this as whereRaw('1 = 0')
            }

            // Test-suite posture only: the historic role-shaped defaults.
            return match ($role) {
                'super_admin', 'admin' => 'all',
                // AT-118 reversal (Johan 2026-07-15): no branch tier for communications.
                'branch_manager', 'office_admin' => $module === 'communications' ? 'own' : 'branch',
                default => 'own', // agent, viewer, etc.
            };
        }

        $scopes  = static::getScopesForRole($role, $agencyId);
        $viewKey = $module . '.view';
        $stored  = $scopes[$viewKey] ?? null;

        // Properties + Contacts use a simple on/off toggle in Role Manager.
        // OFF stores scope='own'; ON stores scope='all'. The *effective* breadth
        // when ON is then dictated at request time by the agency's Data Isolation
        // setting (agencies.split_branches_enabled): branch-only when enabled,
        // agency-wide when disabled.
        if (in_array($module, ['properties', 'contacts'], true) && $stored !== null && $stored !== 'own') {
            $agencyId = $user->effectiveAgencyId();
            if ($agencyId) {
                $split = (bool) \App\Models\Agency::where('id', $agencyId)->value('split_branches_enabled');
                return $split ? 'branch' : 'all';
            }
            return 'all';
        }

        // AT-118 REVERSAL — CODE CEILING (Johan's ruling, 2026-07-15): "bm do not see
        // threads by default. admin and users sees it. bm goes to request access."
        // Communication THREADS are private to their owner + admins. There is NO
        // branch tier for communications — a Branch Manager does not see an agent's
        // messages, regardless of any stored role_permissions row that says 'branch'
        // (the original AT-118 own/branch/all design, now reversed). Admins/owners
        // already returned 'all' above via the break-glass. This ceiling forces any
        // resolved 'branch' comms scope down to 'own'; the BM's path to a specific
        // thread is the AT-118/132 request-access valve (unchanged). This also
        // collapses the BM's compliance-archive body view to 'own', by design.
        if ($module === 'communications' && $stored === 'branch') {
            return 'own';
        }

        return $stored;
    }

    /**
     * Calendar data-visibility scope for a user (own | branch | all).
     * Reads command_center.calendar.view's scope; defaults to 'own' so a
     * user who reaches the page never accidentally sees the whole agency.
     */
    public static function calendarScope(User $user): string
    {
        return static::getDataScope($user, 'command_center.calendar') ?? 'own';
    }

    /**
     * Task data-visibility scope for a user (own | branch | all).
     * Reads command_center.tasks.view's scope; defaults to 'own'.
     */
    public static function taskScope(User $user): string
    {
        return static::getDataScope($user, 'command_center.tasks') ?? 'own';
    }

    /**
     * Clamp a user-requested scope to a role-granted ceiling.
     * Breadth order: own (0) < branch (1) < all (2). A request wider than
     * the ceiling is pulled back to the ceiling, so the page's My/Branch/All
     * toggle can never exceed what Role Manager allows.
     */
    public static function clampScope(?string $requested, string $ceiling): string
    {
        $rank = ['own' => 0, 'branch' => 1, 'all' => 2];
        $ceilRank = $rank[$ceiling] ?? 0;
        $reqRank  = $rank[$requested] ?? $ceilRank;

        return $reqRank <= $ceilRank ? ($requested ?? $ceiling) : $ceiling;
    }

    /**
     * Check if a user has a specific permission via their role.
     * Owner role bypasses all permission checks (the AT-265 break-glass — audited when the grants
     * table is empty).
     * If role_permissions is empty, DENY (AT-265 — this used to allow all; see $allowAllWhenUnseeded).
     * For {module}.view keys, having any scope value = has permission.
     *
     * A role with 0 permissions = 0 access (no silent fallback).
     * New roles are seeded with agent defaults on creation.
     */
    public static function userHasPermission(User $user, string $permissionKey): bool
    {
        // Owner's REAL role always bypasses — even when using View As
        if ($user->isOwnerRole()) {
            static::auditBreakGlass($user, "userHasPermission({$permissionKey})");

            return true;
        }

        $role     = $user->effectiveRole();
        $agencyId = $user->effectiveAgencyId();

        // Owner role bypasses all permission checks (covers edge cases)
        $roleModel = Role::allRoles($agencyId)->firstWhere('name', $role);
        if ($roleModel && $roleModel->is_owner) {
            static::auditBreakGlass($user, "userHasPermission({$permissionKey})");

            return true;
        }

        // AT-265 — an empty grants table is a catastrophe, not a default. Deny, loudly.
        //
        // This RETURNS the verdict rather than falling through: with no grants at all, the lookup
        // below can only ever come back empty, so falling through would deny even in the test
        // suite — which is the whole point of the posture, and would redline ~40 test files.
        if (! static::grantsExist()) {
            return static::unseededGrantsAccess("userHasPermission({$permissionKey})");
        }

        $permissions = static::getPermissionsForRole($role, $agencyId);
        $scopes      = static::getScopesForRole($role, $agencyId);

        // For {module}.view keys, check scope instead of simple presence
        if (str_ends_with($permissionKey, '.view')) {
            if (isset($scopes[$permissionKey])) {
                return true;
            }
        }

        return in_array($permissionKey, $permissions, true);
    }

    /**
     * Check if a user has ANY of the listed permissions.
     */
    public static function userHasAnyPermission(User $user, array $permissionKeys): bool
    {
        foreach ($permissionKeys as $key) {
            if (static::userHasPermission($user, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Clear the static cache (useful for testing or after permission changes).
     *
     * AT-265: this also resets the unseeded POSTURE back to environment-derived. tests/TestCase.php
     * calls it in setUp(), so a test that forced the production posture cannot leak that posture
     * into the next test — the control is isolated to the test that asks for it.
     */
    public static function clearCache(): void
    {
        static::$cache = [];
        static::$scopeCache = [];
        static::$seeded = null;
        static::$agencyHasGrants = [];
        static::$allowAllWhenUnseeded = null;
    }
}

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
            return 'all';
        }

        $role     = $user->effectiveRole();
        $agencyId = $user->effectiveAgencyId();

        // Owner role always gets full scope (covers edge cases)
        $roleModel = Role::allRoles($agencyId)->firstWhere('name', $role);
        if ($roleModel && $roleModel->is_owner) {
            return 'all';
        }

        // If unseeded, use role-based defaults (graceful for tests / fresh DBs)
        if (static::$seeded === null) {
            static::$seeded = RolePermission::exists();
        }
        if (!static::$seeded) {
            return match ($role) {
                'super_admin', 'admin' => 'all',
                'branch_manager', 'office_admin' => 'branch',
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
     * Owner role bypasses all permission checks.
     * If role_permissions table is empty (unseeded DB / tests), allow all.
     * For {module}.view keys, having any scope value = has permission.
     *
     * A role with 0 permissions = 0 access (no silent fallback).
     * New roles are seeded with agent defaults on creation.
     */
    public static function userHasPermission(User $user, string $permissionKey): bool
    {
        // Owner's REAL role always bypasses — even when using View As
        if ($user->isOwnerRole()) {
            return true;
        }

        $role     = $user->effectiveRole();
        $agencyId = $user->effectiveAgencyId();

        // Owner role bypasses all permission checks (covers edge cases)
        $roleModel = Role::allRoles($agencyId)->firstWhere('name', $role);
        if ($roleModel && $roleModel->is_owner) {
            return true;
        }

        // If the table hasn't been seeded, allow access (graceful for tests / fresh DBs)
        if (static::$seeded === null) {
            static::$seeded = RolePermission::exists();
        }
        if (!static::$seeded) {
            return true;
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
     */
    public static function clearCache(): void
    {
        static::$cache = [];
        static::$scopeCache = [];
        static::$seeded = null;
        static::$agencyHasGrants = [];
    }
}

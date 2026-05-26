<?php

namespace App\Services;

use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;

class PermissionService
{
    /** @var array<string, string[]> Cached permissions keyed by role */
    protected static array $cache = [];

    /** @var array<string, array<string, ?string>> Cached scopes: role -> permKey -> scope */
    protected static array $scopeCache = [];

    /** @var bool|null Whether the role_permissions table has been seeded */
    protected static ?bool $seeded = null;

    /**
     * Get all permission_keys for a given role (cached per-request).
     *
     * @return string[]
     */
    public static function getPermissionsForRole(string $role): array
    {
        if (!isset(static::$cache[$role])) {
            static::$cache[$role] = RolePermission::where('role', $role)
                ->pluck('permission_key')
                ->all();
        }

        return static::$cache[$role];
    }

    /**
     * Get scope values for a role (cached per-request).
     * Returns array: permission_key => scope ('own'|'branch'|'all'|null)
     */
    protected static function getScopesForRole(string $role): array
    {
        if (!isset(static::$scopeCache[$role])) {
            static::$scopeCache[$role] = RolePermission::where('role', $role)
                ->whereNotNull('scope')
                ->pluck('scope', 'permission_key')
                ->all();
        }

        return static::$scopeCache[$role];
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

        $role = $user->effectiveRole();

        // Owner role always gets full scope (covers edge cases)
        $roleModel = Role::allRoles()->firstWhere('name', $role);
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

        $scopes  = static::getScopesForRole($role);
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

        $role = $user->effectiveRole();

        // Owner role bypasses all permission checks (covers edge cases)
        $roleModel = Role::allRoles()->firstWhere('name', $role);
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

        $permissions = static::getPermissionsForRole($role);
        $scopes      = static::getScopesForRole($role);

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
    }
}

<?php

namespace App\Services;

use App\Models\RolePermission;
use App\Models\User;

class PermissionService
{
    /** @var array<string, string[]> Cached permissions keyed by role */
    protected static array $cache = [];

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
     * Check if a user has a specific permission via their role.
     * super_admin bypasses all permission checks.
     * If role_permissions table is empty (unseeded DB / tests), allow all.
     */
    public static function userHasPermission(User $user, string $permissionKey): bool
    {
        $role = $user->effectiveRole();

        if ($role === 'super_admin') {
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

        return in_array($permissionKey, $permissions, true);
    }

    /**
     * Check if a user has ANY of the listed permissions.
     */
    public static function userHasAnyPermission(User $user, array $permissionKeys): bool
    {
        $role = $user->effectiveRole();
        $permissions = static::getPermissionsForRole($role);

        foreach ($permissionKeys as $key) {
            if (in_array($key, $permissions, true)) {
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
        static::$seeded = null;
    }
}

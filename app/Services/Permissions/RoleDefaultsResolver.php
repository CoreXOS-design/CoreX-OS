<?php

namespace App\Services\Permissions;

/**
 * Single source of truth for expanding a `config/corex-permissions.php`
 * `role_defaults` entry into its concrete permission-key set.
 *
 * Both `corex:sync-permissions` (seed/merge) and `corex:reconcile-role-grants`
 * (drift cleanup) resolve a role's config-intended keys through this class, so
 * the "what SHOULD this role have" answer can never drift between the command
 * that GRANTS defaults and the command that PRUNES over-grants. If these two
 * diverged, the reconciler could delete a key the sync would immediately
 * re-add (or vice-versa) — an infinite tug-of-war. One resolver forbids that.
 */
class RoleDefaultsResolver
{
    /**
     * Resolve the full default permission-key set for a role_defaults entry.
     *
     *  - '*'                    → every key (owner roles)
     *  - ['exclude' => [...]]   → all keys MINUS the exclude list (all-minus, e.g. admin)
     *  - ['include' => [...]]   → exactly the include list (closed set, e.g. agent/viewer)
     *  - anything else          → [] (unknown / custom role — no config defaults)
     *
     * @param  string|array  $def
     * @param  string[]      $allKeys  Every catalogued permission key (for '*'/exclude expansion).
     * @return string[]
     */
    public static function keysForDef($def, array $allKeys): array
    {
        if ($def === '*') {
            return $allKeys;
        }
        if (is_array($def) && isset($def['exclude'])) {
            return array_values(array_filter($allKeys, fn ($k) => !in_array($k, $def['exclude'], true)));
        }
        if (is_array($def) && isset($def['include'])) {
            return $def['include'];
        }

        return [];
    }

    /**
     * True only when the role's config default is a CLOSED set — an explicit
     * `include` list with no `exclude`. These are the ONLY roles whose DB grants
     * can be safely reconciled down to config: the include list is an exhaustive
     * statement of intent, so any DB grant outside it is provably drift.
     *
     * An all-minus ('exclude') role (admin) or a wildcard ('*') owner role is
     * NOT closed — its intended set is "everything except a few", so a DB grant
     * outside a hand-written include cannot be assumed to be drift. Reconciling
     * those would strip legitimately-broad access; the reconciler must refuse.
     *
     * @param  string|array  $def
     */
    public static function isClosedInclude($def): bool
    {
        return is_array($def) && isset($def['include']) && !isset($def['exclude']);
    }
}

<?php

namespace App\Models\Scopes;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Phase-2 branch-isolation scope. Mirrors AgencyScope but operates
 * one level lower (within an agency, partition by branch_id).
 *
 * Enforcement gates (all must be true to apply the filter):
 *   1. A user is authenticated.
 *   2. The user's effective agency has `split_branches_enabled = true`.
 *   3. The user does NOT hold `branches.view_all` (principal / admin bypass).
 *
 * Edge behaviour:
 *   - Authed user with NULL branch_id while Split = ON → sees nothing
 *     (whereRaw('1 = 0')). UI gates this via RequiresBranchAssignment
 *     middleware with a friendly banner, but the scope stays strict so
 *     no row can leak via an un-middlewared route.
 *   - When applied to the User model, the authenticated user's own row
 *     is always visible — without this, a stale session branch can knock
 *     the user out of their own user provider and force-logout them.
 */
class BranchScope implements Scope
{
    /**
     * Per-model re-entry guard — Auth::user() resolution can recurse
     * through User queries that themselves trigger this scope.
     *
     * @var array<class-string, bool>
     */
    private static array $applying = [];

    /**
     * Per-request cache of `split_branches_enabled` per agency.
     * Keyed by agency_id. Populated lazily on first scope apply.
     *
     * @var array<int, bool>
     */
    private static array $agencyToggleCache = [];

    public function apply(Builder $builder, Model $model): void
    {
        $class = get_class($model);
        if (!empty(self::$applying[$class])) {
            return;
        }

        self::$applying[$class] = true;
        try {
            $this->applyInner($builder, $model);
        } finally {
            unset(self::$applying[$class]);
        }
    }

    private function applyInner(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (!$user) {
            return;
        }

        // Owner-role bypass: if AgencyScope is letting this through
        // unscoped (owner not switched into an agency), BranchScope
        // must also be off — otherwise we'd scope a query that has no
        // agency context and the result set would be meaningless.
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            $hasAgencyOverride = session('active_agency_id') !== null
                && session('active_agency_id') !== '';
            if (!$hasAgencyOverride) {
                return;
            }
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if (!$agencyId) {
            return;
        }

        if (!$this->splitBranchesEnabled((int) $agencyId)) {
            return;
        }

        // `branches.view_all` grants a cross-branch read. Principals and
        // agency admins get this by default (seeded in Prompt F); anyone
        // without it gets scoped to their own branch.
        if (method_exists($user, 'hasPermission') && $user->hasPermission('branches.view_all')) {
            return;
        }

        $effectiveBranch = method_exists($user, 'effectiveBranchId')
            ? $user->effectiveBranchId()
            : ($user->branch_id ?? null);

        if (!$effectiveBranch) {
            // Unassigned user under Split = ON: see nothing. The
            // RequiresBranchAssignment middleware should catch them
            // before they hit any normal route, but scope-level strict
            // NULL handling is the safety net.
            $builder->whereRaw('1 = 0');
            return;
        }

        $table   = $model->getTable();
        $column  = $table . '.branch_id';
        $keyName = $table . '.' . $model->getKeyName();
        $authId  = $user->getKey();
        $isUserModel = $model instanceof \App\Models\User;

        $builder->where(function (Builder $q) use ($column, $effectiveBranch, $keyName, $authId, $isUserModel) {
            $q->where($column, $effectiveBranch);

            if ($isUserModel && $authId) {
                $q->orWhere($keyName, $authId);
            }
        });
    }

    private function splitBranchesEnabled(int $agencyId): bool
    {
        if (array_key_exists($agencyId, self::$agencyToggleCache)) {
            return self::$agencyToggleCache[$agencyId];
        }

        // Query the column directly via the base query builder to sidestep
        // AgencyScope entirely and avoid loading the full Agency model.
        $enabled = (bool) Agency::withoutGlobalScope(AgencyScope::class)
            ->whereKey($agencyId)
            ->value('split_branches_enabled');

        return self::$agencyToggleCache[$agencyId] = $enabled;
    }

    /**
     * Clear the per-request agency-toggle cache. Primarily useful in
     * tests that flip the toggle mid-scenario.
     */
    public static function flushCache(): void
    {
        self::$agencyToggleCache = [];
    }
}

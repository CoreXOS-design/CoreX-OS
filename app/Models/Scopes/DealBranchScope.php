<?php

namespace App\Models\Scopes;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Phase-2 branch-isolation scope for models that use the `deal_branches`
 * pivot rather than a direct `branch_id` column. Per spec §5 + §11, a
 * deal can belong to multiple branches (originator + co-branches) so
 * visibility follows the pivot.
 *
 * Same activation gates as the vanilla BranchScope:
 *   - authed user
 *   - user's agency has split_branches_enabled = true
 *   - user does not hold `branches.view_all`
 *
 * Difference: instead of WHERE branch_id = ?, uses
 * WHERE EXISTS (SELECT 1 FROM deal_branches WHERE deal_id = deals.id AND branch_id = ?).
 */
class DealBranchScope implements Scope
{
    private static array $applying = [];
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

        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            if (!session('active_agency_id')) {
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

        if (method_exists($user, 'hasPermission') && $user->hasPermission('branches.view_all')) {
            return;
        }

        $effectiveBranch = method_exists($user, 'effectiveBranchId')
            ? $user->effectiveBranchId()
            : ($user->branch_id ?? null);

        if (!$effectiveBranch) {
            $builder->whereRaw('1 = 0');
            return;
        }

        $table = $model->getTable();
        $key   = $table . '.' . $model->getKeyName();

        // Visible when at least one deal_branches row matches the effective branch.
        $builder->whereIn($key, function ($q) use ($effectiveBranch) {
            $q->select('deal_id')
              ->from('deal_branches')
              ->where('branch_id', $effectiveBranch);
        });
    }

    private function splitBranchesEnabled(int $agencyId): bool
    {
        if (array_key_exists($agencyId, self::$agencyToggleCache)) {
            return self::$agencyToggleCache[$agencyId];
        }

        $enabled = (bool) Agency::withoutGlobalScope(AgencyScope::class)
            ->whereKey($agencyId)
            ->value('split_branches_enabled');

        return self::$agencyToggleCache[$agencyId] = $enabled;
    }

    public static function flushCache(): void
    {
        self::$agencyToggleCache = [];
    }
}

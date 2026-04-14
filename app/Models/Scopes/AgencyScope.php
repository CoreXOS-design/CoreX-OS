<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Global scope that constrains queries on agency-owned models to the
 * authenticated user's effective agency. See the BelongsToAgency trait
 * for the full rationale.
 *
 * Skipped when:
 *   - there is no authenticated user (console, migrations, jobs without context)
 *   - the user is an owner-role account with no active agency switcher override
 *     (they intentionally see all agencies until they switch into one)
 *
 * Records with NULL agency_id are considered shared/global and are always
 * included, so shared config, system records, and not-yet-migrated rows do
 * not vanish when the scope is active.
 */
class AgencyScope implements Scope
{
    /**
     * Per-model re-entry guard. The scope calls Auth::user(), and when the
     * User model itself is scoped that call would recurse through the user
     * provider. We skip the scope for any model class that is already being
     * applied further up the stack.
     *
     * @var array<class-string, bool>
     */
    private static array $applying = [];

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

        // Super-admin / owner roles see every agency by default. They opt
        // INTO a specific agency via the agency switcher — until they do,
        // we do not scope their queries at all (even if a stale override
        // is sitting in the session from a previous login, the login event
        // listener wipes it).
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            $hasOverride = session('active_agency_id') !== null
                && session('active_agency_id') !== '';
            if (!$hasOverride) {
                return;
            }
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if (!$agencyId) {
            return;
        }

        $table = $model->getTable();
        $column = $table . '.agency_id';
        $keyName = $table . '.' . $model->getKeyName();
        $authId = $user->getKey();
        $isUserModel = $model instanceof \App\Models\User;

        $builder->where(function (Builder $q) use ($column, $agencyId, $keyName, $authId, $isUserModel) {
            $q->where($column, $agencyId)
              ->orWhereNull($column);

            // The authenticated user must always be able to see their own
            // record, even if a switcher override would otherwise exclude
            // them. Without this, a stale session agency causes the user
            // provider to lose the logged-in row and immediately log them
            // out on the next request.
            if ($isUserModel && $authId) {
                $q->orWhere($keyName, $authId);
            }
        });
    }
}

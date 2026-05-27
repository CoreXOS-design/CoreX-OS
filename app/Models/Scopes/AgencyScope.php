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
 * Records with NULL agency_id are treated as ORPHAN and filtered out
 * (strict match on the resolved agency_id). This was changed from the
 * earlier "NULL = shared" behaviour because the loose semantic was leaking
 * cross-agency data via not-yet-migrated rows. Code paths that legitimately
 * need shared rows must opt in via withoutGlobalScope(AgencyScope::class)
 * + whereNull('agency_id'); see .ai/specs/multi-tenancy.md §2 and §2a.
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
            // Only consult the session when one is actually bound to the
            // request. Bearer-token API requests (mobile app) have no session;
            // calling session() in that context can stall when StartSession
            // middleware is active with a database/file driver and two
            // concurrent requests share a session row (row-lock deadlock).
            $request = request();
            $hasSession = $request && $request->hasSession() && $request->session()->isStarted();
            $hasOverride = $hasSession
                && session('active_agency_id') !== null
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
            // Strict tenancy: rows must carry the current agency_id.
            // Previously we also allowed `agency_id IS NULL` as "shared",
            // but NULL on a tenant table is always an orphan (e.g. a
            // pre-migration row) and treating it as shared made those
            // orphans leak into every agency.
            $q->where($column, $agencyId);

            // The authenticated user must always be able to see their own
            // record. Without this, a stale session agency causes the user
            // provider to lose the logged-in row and immediately log them
            // out on the next request. System Owners legitimately have
            // NULL agency_id — the bypass above already covers them before
            // we reach this clause.
            if ($isUserModel && $authId) {
                $q->orWhere($keyName, $authId);
            }
        });
    }
}

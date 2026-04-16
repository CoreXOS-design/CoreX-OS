<?php

namespace App\Models\Concerns;

use App\Models\Agency;
use App\Models\Scopes\AgencyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Multi-tenant isolation for agency-owned records.
 *
 * Applies a global scope that constrains every query to the authenticated
 * user's effective agency, and auto-fills `agency_id` on creation from the
 * same source. Owner-role users bypass the read scope when they have no
 * active agency switcher session, so they can see everything; once they
 * switch into a specific agency, they are scoped to it just like any
 * other user.
 *
 * Records with NULL agency_id are treated as shared/global and remain
 * visible across agencies.
 */
trait BelongsToAgency
{
    protected static function bootBelongsToAgency(): void
    {
        static::addGlobalScope(new AgencyScope());

        static::creating(function ($model) {
            if (!empty($model->agency_id)) {
                return;
            }

            $user = Auth::user();
            if (!$user) {
                return;
            }

            $agencyId = method_exists($user, 'effectiveAgencyId')
                ? $user->effectiveAgencyId()
                : ($user->agency_id ?? null);

            if ($agencyId) {
                $model->agency_id = $agencyId;
            }
        });
    }

    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Escape hatch for legitimate cross-agency work (console commands,
     * scheduled jobs, system imports). Callers must be able to justify why
     * they need to bypass tenancy isolation.
     */
    public function newQueryWithoutAgencyScope()
    {
        return $this->newQuery()->withoutGlobalScope(AgencyScope::class);
    }

    public static function queryWithoutAgencyScope()
    {
        return (new static)->newQueryWithoutAgencyScope();
    }
}

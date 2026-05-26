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
 * Records with NULL agency_id are treated as ORPHAN (not shared) and are
 * filtered out by AgencyScope. Models that need genuinely shared rows must
 * either skip this trait or expose an explicit scopeShared() helper that
 * calls withoutGlobalScope(AgencyScope::class)->whereNull('agency_id').
 * See .ai/specs/multi-tenancy.md §2 and §2a.
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
            if ($user) {
                $agencyId = method_exists($user, 'effectiveAgencyId')
                    ? $user->effectiveAgencyId()
                    : ($user->agency_id ?? null);

                if ($agencyId) {
                    $model->agency_id = $agencyId;
                    return;
                }
            }

            // Console/seeder/test fallback: if exactly one agency exists in the
            // DB (single-tenant install or fresh dev/test DB), stamp it. This
            // matches the wave3b backfill semantics and prevents seeders from
            // crashing on NOT NULL agency_id. Cached per-request.
            static $singleAgencyId = null;
            if ($singleAgencyId === null) {
                try {
                    $rows = \Illuminate\Support\Facades\DB::table('agencies')->limit(2)->pluck('id');
                    $singleAgencyId = ($rows->count() === 1) ? (int) $rows->first() : 0;
                } catch (\Throwable $e) {
                    $singleAgencyId = 0;
                }
            }
            if ($singleAgencyId > 0) {
                $model->agency_id = $singleAgencyId;
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

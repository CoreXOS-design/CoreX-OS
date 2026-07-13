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
            $user = Auth::user();
            if ($user && !static::isUnscopedOwner($user)) {
                $agencyId = method_exists($user, 'effectiveAgencyId')
                    ? $user->effectiveAgencyId()
                    : ($user->agency_id ?? null);

                if ($agencyId) {
                    // AUTHORITATIVE for ordinary authenticated users: force agency_id
                    // to the user's effective agency, OVERRIDING any value that arrived
                    // via mass-assignment. A scoped user must never create a record in
                    // another agency, so a request-supplied agency_id cannot spoof the
                    // tenant. (Trusted non-auth ingress — jobs, webhooks, imports,
                    // console — has no Auth::user() and keeps its explicit value below.)
                    $model->agency_id = $agencyId;
                    return;
                }
            }

            // Reached when: no auth user (job/webhook/import/console), OR an
            // owner-role account with no active agency switcher override (they are
            // intentionally cross-agency and may create records in a CHOSEN agency
            // — e.g. the P24 admin importer — mirroring AgencyScope's read bypass).
            // In all these cases an explicitly-provided agency_id is trusted.
            if (!empty($model->agency_id)) {
                return;
            }

            // An EXPLICIT null agency_id is a deliberate GLOBAL row (reference data
            // owned by CoreX, not by a tenant — e.g. CalendarEventClassSeeder's class
            // defaults). Honour it, and do NOT let the single-agency fallback below
            // stamp a tenant onto it: on a single-agency install that silently turned
            // every global row into an agency-1 row, so the seeder's next run could
            // never find its own global rows, re-inserted them, and died on the
            // (agency_id, event_class) unique key — which is exactly how
            // `deploy:sync-reference-data` broke on demo (one agency) while passing on
            // live (two). Distinguished from "caller never mentioned agency_id" by the
            // attribute's PRESENCE, so the NOT-NULL fallback below still covers seeders
            // that simply omit it.
            $attributes = $model->getAttributes();
            if (array_key_exists('agency_id', $attributes) && $attributes['agency_id'] === null) {
                return;
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

    /**
     * Mirror of AgencyScope's read-side bypass: an owner-role account is
     * intentionally cross-agency UNTIL it switches into a specific agency via
     * the switcher (session active_agency_id). Keeping the write-side stamping
     * symmetric with the read-side scoping is what preserves legitimate
     * cross-agency admin flows (e.g. the P24 importer, where an owner picks the
     * target agency from a dropdown) while still force-scoping ordinary agents.
     */
    protected static function isUnscopedOwner($user): bool
    {
        if (!method_exists($user, 'isOwnerRole') || !$user->isOwnerRole()) {
            return false;
        }

        // Only consult the session when one is actually bound and started —
        // bearer-token API requests have no session (see AgencyScope for the
        // row-lock rationale).
        $request = request();
        $hasSession = $request && $request->hasSession() && $request->session()->isStarted();
        $hasOverride = $hasSession
            && session('active_agency_id') !== null
            && session('active_agency_id') !== '';

        // Owner WITH an active switcher override is scoped to that agency (so we
        // force-stamp it); owner WITHOUT an override is unscoped (honour explicit).
        return !$hasOverride;
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

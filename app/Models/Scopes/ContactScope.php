<?php

namespace App\Models\Scopes;

use App\Models\AgencyContactSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Contact Governance scope — enforces sharing_mode visibility on Contact queries.
 *
 * Three modes (configured per agency in agency_contact_settings):
 *   - 'open'   : all contacts in agency visible to everyone (current HFC default)
 *   - 'branch' : contacts visible only within the owner's branch (BM+admin bypass)
 *   - 'closed' : contacts visible only to their owner (BM sees branch team, admin sees all)
 *
 * Manager chain ALWAYS bypasses (agency admin + super_admin see everything).
 * Cross-agency isolation is handled by AgencyScope (applied separately) — this
 * scope ONLY adds further restriction within the agency.
 *
 * Bypass: Contact::withoutGlobalScope(ContactScope::class) for admin oversight queries.
 */
class ContactScope implements Scope
{
    /**
     * Re-entry guard to prevent infinite recursion when loading
     * AgencyContactSettings triggers a query that itself needs scoping.
     */
    private static bool $applying = false;

    /**
     * Per-request cache of sharing_mode per agency.
     */
    private static array $modeCache = [];

    public function apply(Builder $builder, Model $model): void
    {
        if (self::$applying) {
            return;
        }

        self::$applying = true;
        try {
            $this->applyInner($builder, $model);
        } finally {
            self::$applying = false;
        }
    }

    private function applyInner(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (!$user) {
            return; // Console, seeders, queue — no restriction
        }

        // Super_admin / owner without switched agency — sees everything
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            $hasAgencyOverride = session('active_agency_id') !== null
                && session('active_agency_id') !== '';
            if (!$hasAgencyOverride) {
                return; // Owner browsing globally — no contact scope
            }
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if (!$agencyId) {
            return; // No agency context — AgencyScope handles visibility
        }

        $mode = $this->getSharingMode((int) $agencyId);

        // Admin role bypass — admin/super_admin sees all in agency
        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? 'agent');
        if (in_array($role, ['admin', 'super_admin'])) {
            return; // Agency-level admin sees everything (AgencyScope already constrains to agency)
        }

        // 'open' mode — everyone in agency sees all contacts
        if ($mode === 'open') {
            return;
        }

        $table = $model->getTable();
        $userId = $user->getKey();
        $branchId = method_exists($user, 'effectiveBranchId')
            ? $user->effectiveBranchId()
            : ($user->branch_id ?? null);

        if ($mode === 'branch') {
            // BM sees their branch (same as agent in branch mode)
            if ($branchId) {
                $builder->where($table . '.branch_id', $branchId);
            } else {
                // User with no branch assigned — see nothing in branch mode
                $builder->whereRaw('1 = 0');
            }
        } elseif ($mode === 'closed') {
            if (in_array($role, ['bm', 'branch_manager'])) {
                // BM sees contacts owned by anyone in their branch
                if ($branchId) {
                    $builder->where(function (Builder $q) use ($table, $userId, $branchId) {
                        $q->where($table . '.created_by_user_id', $userId)
                          ->orWhereIn($table . '.created_by_user_id', function ($sub) use ($branchId) {
                              $sub->select('id')
                                  ->from('users')
                                  ->where('branch_id', $branchId)
                                  ->whereNull('deleted_at');
                          });
                    });
                } else {
                    $builder->where($table . '.created_by_user_id', $userId);
                }
            } else {
                // Agent: only sees contacts they own
                $builder->where($table . '.created_by_user_id', $userId);
            }
        }
    }

    private function getSharingMode(int $agencyId): string
    {
        if (array_key_exists($agencyId, self::$modeCache)) {
            return self::$modeCache[$agencyId];
        }

        // Direct DB query to avoid triggering model scopes on AgencyContactSettings
        $mode = \Illuminate\Support\Facades\DB::table('agency_contact_settings')
            ->where('agency_id', $agencyId)
            ->value('sharing_mode');

        return self::$modeCache[$agencyId] = $mode ?? 'open';
    }

    /**
     * Clear the per-request cache. Useful in tests or after mode changes.
     */
    public static function flushCache(): void
    {
        self::$modeCache = [];
    }
}

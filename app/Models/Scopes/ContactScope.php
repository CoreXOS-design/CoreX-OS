<?php

namespace App\Models\Scopes;

use App\Services\PermissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Contact visibility scope — enforces role-based data access on Contact queries.
 *
 * THREE-LAYER ARCHITECTURE:
 *
 *   Layer 1: AgencyScope       — agency isolation (applied separately via BelongsToAgency)
 *   Layer 2: ContactScope      — role-based read visibility (THIS SCOPE)
 *   Layer 3: Pipeline filters  — personal workspace defaults (applied in controllers)
 *
 * Layer 2 reads the user's role scope from role_permissions (managed via Role Manager):
 *   - 'all'    : see all contacts in agency (AgencyScope handles cross-agency isolation)
 *   - 'branch' : see contacts in own branch only (BM sees branch team's contacts)
 *   - 'own'    : see only contacts user created (BM sees branch team, admin sees all)
 *
 * Scope is configured per-role in Role Manager → Contacts → Scope dropdown.
 * Previously this read agency_contact_settings.sharing_mode; that column is now
 * deprecated in favour of role-based scope from role_permissions.
 *
 * Manager chain ALWAYS bypasses: admin/super_admin/owner see everything in agency.
 * Cross-agency isolation handled by AgencyScope (applied separately).
 *
 * Bypass: Contact::withoutGlobalScope(ContactScope::class) for admin oversight queries.
 */
class ContactScope implements Scope
{
    private static bool $applying = false;

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
                return;
            }
        }

        $agencyId = method_exists($user, 'effectiveAgencyId')
            ? $user->effectiveAgencyId()
            : ($user->agency_id ?? null);

        if (!$agencyId) {
            return; // No agency context — AgencyScope handles visibility
        }

        $table = $model->getTable();

        // AT-267 — an ASSISTANT is resolved here and returns; they never reach the role logic
        // below. Two reasons, both of which would be live escalations otherwise:
        //
        //   1. The role bypass. The next block hands unrestricted agency-wide access to
        //      anyone whose role reads 'admin'. `users.role` is NOT NULL DEFAULT 'agent' and
        //      is freely editable — an assistant must never be able to reach a bypass keyed
        //      on a column that is not the source of truth for what they can do.
        //   2. The null fail-OPEN. Below, a null scope means "no restriction". For an
        //      assistant a null scope means the OPPOSITE — no assignment, a suspended one, a
        //      deactivated agent, or a module their agent chose not to hand over. Every one of
        //      those must show them NOTHING, not everything. An assistant who could out-see
        //      their own agent is the one outcome this feature may never produce.
        //
        // (The null fail-open for non-assistant roles is pre-existing behaviour and is left
        // exactly as it was — changing it is a separate decision with its own blast radius.)
        if ($user->is_assistant) {
            $this->applyAssistant($builder, $user, $table);

            return;
        }

        // Admin/super_admin bypass — sees all in agency
        $role = method_exists($user, 'effectiveRole') ? $user->effectiveRole() : ($user->role ?? 'agent');
        if (in_array($role, ['admin', 'super_admin'], true)) {
            return;
        }

        // Read role-based scope from role_permissions via PermissionService
        $scope = PermissionService::getDataScope($user, 'contacts');

        // 'all' or null (no restriction beyond agency) — everyone in agency sees all
        if ($scope === 'all' || $scope === null) {
            return;
        }

        $userId = $user->getKey();
        $branchId = method_exists($user, 'effectiveBranchId')
            ? $user->effectiveBranchId()
            : ($user->branch_id ?? null);

        if ($scope === 'branch') {
            if (in_array($role, ['bm', 'branch_manager'], true) && $branchId) {
                // BM in branch mode: sees contacts in own branch
                $builder->where($table . '.branch_id', $branchId);
            } elseif ($branchId) {
                // Agent in branch mode: sees contacts in own branch
                $builder->where($table . '.branch_id', $branchId);
            } else {
                $builder->whereRaw('1 = 0'); // No branch assigned — see nothing
            }
        } elseif ($scope === 'own') {
            if (in_array($role, ['bm', 'branch_manager'], true)) {
                // BM in 'own' mode: sees contacts owned by self + anyone in branch
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
                // Agent in 'own' mode: only contacts they created
                $builder->where($table . '.created_by_user_id', $userId);
            }
        }
    }

    /**
     * AT-267 — contact visibility for an Assistant.
     *
     * Their breadth is whatever AssistantPermissionResolver resolved: the narrower of what
     * their Assigned Agent granted them in the matrix and what the agent themselves has. Their
     * IDENTITY is the agent's — an assistant granted contacts at scope 'own' sees the AGENT's
     * contacts, because working the agent's book is the entire job.
     *
     * Fails CLOSED. A null scope here is never "unrestricted" — it means no assignment, a
     * suspended one, a deactivated agent, or a module the agent withheld, and every one of
     * those shows nothing.
     */
    private function applyAssistant(Builder $builder, $user, string $table): void
    {
        $scope = PermissionService::getDataScope($user, 'contacts');

        if ($scope === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        if ($scope === 'all') {
            return; // the agent sees the whole agency, so their assistant may too
        }

        if ($scope === 'branch') {
            // An assistant's branch IS their agent's branch (it follows on transfer).
            $branchId = $user->effectiveBranchId();

            $branchId
                ? $builder->where($table . '.branch_id', $branchId)
                : $builder->whereRaw('1 = 0');

            return;
        }

        // 'own' — the AGENT's own.
        $builder->whereIn($table . '.created_by_user_id', $user->dataIdentityIds());
    }
}

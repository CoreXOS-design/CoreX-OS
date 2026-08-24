<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Support\Collection;

/**
 * Shared data-visibility resolution for the mobile API.
 *
 * Mirrors the web behaviour (CoreX\ContactController / PropertyController):
 * the Role Manager "Scope" setting (role_permissions.scope) decides whether an
 * agent sees only their own records ('own'), their branch ('branch'), or the
 * whole agency ('all'). The agency Data-Isolation toggle
 * (agencies.split_branches_enabled) collapses 'all' → 'branch' at request time
 * — that resolution already lives in PermissionService::getDataScope().
 *
 * The mobile app uses this to decide which filter chips to render:
 *   - own            → no chips at all (just "My contacts"/"My properties")
 *   - branch / all   → "Mine" + "All" + per-agent picker
 */
trait ResolvesMobileDataScope
{
    /**
     * The visibility descriptor for one module ('contacts' | 'properties').
     *
     * @return array{scope:string,can_pick_agent:bool,agents:array}
     */
    protected function moduleVisibility(User $user, string $module): array
    {
        $scope = PermissionService::getDataScope($user, $module) ?? 'own';
        $canPick = in_array($scope, ['branch', 'all'], true);

        return [
            // 'own' | 'branch' | 'all' — already collapsed for split-branches
            'scope'          => $scope,
            // when false the mobile app must NOT render the My/All/agent chips
            'can_pick_agent' => $canPick,
            'agents'         => $canPick
                ? $this->allowedAgents($user, $module)
                    ->map(fn (User $u) => [
                        'id'    => $u->id,
                        'name'  => $u->name,
                        'email' => $u->email,
                    ])->values()->all()
                : [],
        ];
    }

    /**
     * The agents this user is permitted to filter by, given their scope.
     * Branch scope = branch team; all scope = whole agency; own = just self.
     */
    protected function allowedAgents(User $user, string $module): Collection
    {
        $scope = PermissionService::getDataScope($user, $module) ?? 'own';

        $query = User::agencyMembers()
            ->where('is_active', 1)
            ->orderBy('name');

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            $query->where('branch_id', $branchId ?: -1);
        } elseif ($scope !== 'all') {
            // AT-267 — for an ASSISTANT this is their Assigned Agent (dataIdentityIds()), so the
            // agent picker on mobile offers the agent whose book they actually work. For everyone
            // else it is exactly [$user->id] and nothing changes.
            $query->whereIn('id', $user->dataIdentityIds());
        }

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * Resolve the `agent_id` request param into the user_id to filter by.
     *
     * Returns:
     *   - int   → filter to exactly this agent (validated to be in scope)
     *   - null  → no agent filter; show everything the scope allows
     *
     * Rules (mirror the web):
     *   - scope 'own' (or no pick rights): ALWAYS forced to the user themselves,
     *     the param is ignored.
     *   - param absent           → default to "mine" (own id)
     *   - param '' / 'all'       → null (everything in scope)
     *   - param numeric          → that agent, but 403 if outside the allowed set
     */
    protected function resolveAgentFilter(User $user, string $module, $param): ?int
    {
        $scope = PermissionService::getDataScope($user, $module) ?? 'own';

        // AT-267 — the records an assistant works are OWNED by their Assigned Agent
        // (User::ownershipUserId()), so an own-scope filter has to resolve to the AGENT's id.
        // Filtering to the assistant's own id would return an empty list on mobile — the
        // assistant owns nothing, by design. For every other user ownershipUserId() is their
        // own id and this is a no-op.
        if (! in_array($scope, ['branch', 'all'], true)) {
            return $user->ownershipUserId(); // own-only — never wider than the agent's book
        }

        // Param not sent at all → default to the user's own records.
        if ($param === null) {
            return $user->ownershipUserId();
        }

        // Explicit "all in scope".
        if ($param === '' || $param === 'all') {
            return null;
        }

        $agentId = (int) $param;

        $allowed = $this->allowedAgents($user, $module)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();

        abort_unless(
            in_array($agentId, $allowed, true),
            403,
            'That agent is outside your visibility scope.'
        );

        return $agentId;
    }

    /**
     * Single-record gate: may this user touch this property?
     *
     * Mirrors the web (CoreX\PropertyController) and the long-standing private
     * MobilePropertyController::authorizeProperty — own listing always allowed,
     * otherwise allowed per the role data scope (branch manager / agency-wide
     * roles can open team listings). Aborts 403 when out of scope.
     */
    protected function authorizePropertyAccess(User $user, Property $property): void
    {
        // Own listing — always allowed, whether the user is the PRIMARY
        // (agent_id) or the SECONDARY co-listing agent (pp_second_agent_id).
        // A co-listed property is "theirs" for both agents (mirrors the web).
        // dataIdentityIds() is [$user->id] for a normal user and [agent, self] for an
        // ASSISTANT, so an assistant's "own" here is the assigned agent's listings.
        if (in_array((int) $property->agent_id, $user->dataIdentityIds(), true)
            || in_array((int) $property->pp_second_agent_id, $user->dataIdentityIds(), true)) {
            return;
        }

        // MUTATION scope: an assistant is capped at 'own' (handled above), so a branch/all
        // agent's assistant cannot edit a colleague's listing on mobile either.
        $scope = PermissionService::mutationScope($user, 'properties') ?? 'own';

        if ($scope === 'all') {
            return;
        }

        if ($scope === 'branch'
            && $property->branch_id
            && (int) $property->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }

        abort(403, 'This property is outside your visibility scope.');
    }
}

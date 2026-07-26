<?php

namespace App\Services\Assistants;

use App\Models\User;
use App\Services\PermissionService;

/**
 * AT-267 — what an Assistant may do.
 *
 * THE RULE, in one line:
 *
 *     assistant_can(key) = matrix[key] AND assigned_agent_can(key) AND key ∉ LOCKED_SET
 *
 * The matrix can only ever SUBTRACT. On assignment it is seeded as a COPY of the agent's
 * permissions (all on, minus the locked set) and the agent switches things off. But the
 * grant is re-intersected against the agent's LIVE permissions on every single check — so
 * the moment an agent loses a permission, their assistant loses it too, with no re-snapshot,
 * no nightly job, and no window in which the assistant outranks the agent.
 *
 * FAIL CLOSED, EVERYWHERE. Every unclear state resolves to "no". No assignment, a suspended
 * assignment, a deactivated agent, a missing matrix row, the agency toggle off — all of them
 * return false, never "fall back to the role". This matters because the fallback would be
 * catastrophic: `users.role` defaults to 'agent', so "fall back to the role" on a
 * misconfigured assistant would mean full agent permissions.
 *
 * WHY THE RECURSION IS SAFE. allows() calls PermissionService::userHasPermission() on the
 * AGENT, which could in principle re-enter this resolver. It cannot, because:
 *   - an agent may not themselves be an assistant (blocked at assignment — spec E5), and
 *   - an agent may not hold an owner role (blocked at assignment — spec E6; an owner
 *     bypasses all permission checks, so an owner agent would make the matrix the ONLY
 *     limit and one mis-ticked box would hand an assistant super-admin powers).
 * Both are enforced at write time (Prompt F). The `$resolving` guard below is the belt to
 * that pair of braces — if a bad row ever gets in, we deny rather than blow the stack.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §7.1, §9
 */
class AssistantPermissionResolver
{
    /** Re-entry guard. A cycle in the assignment graph must deny, never recurse forever. */
    private static array $resolving = [];

    /**
     * May this assistant use this permission key?
     *
     * Only ever called for users where `is_assistant` is true — PermissionService routes
     * them here instead of doing role resolution, so an assistant's `users.role` value is
     * never consulted for grants at all.
     */
    public static function allows(User $assistant, string $permissionKey): bool
    {
        // The hard lock comes FIRST — before the matrix, before the agent, before anything.
        // No assistant creates a listing, regardless of what any row anywhere says.
        if (static::isLocked($permissionKey)) {
            return false;
        }

        // activeAssistantAssignment() already folds in the agency kill switch
        // (agencies.assistants_enabled) and the status check, and is memoised per request.
        $assignment = $assistant->activeAssistantAssignment();

        if (!$assignment) {
            return false;
        }

        $agent = $assignment->assignedAgent;

        // A deactivated agent freezes their assistant (spec E1). The assistant keeps their
        // login and loses every capability — they cannot keep acting for someone whose own
        // access has been withdrawn.
        if (!$agent || !$agent->is_active) {
            return false;
        }

        // The matrix: what the agent has chosen to hand over. Fails closed on a missing row.
        if (!$assignment->grants($permissionKey)) {
            return false;
        }

        // The ceiling: what the agent can actually do, right now. This is the half that makes
        // the whole thing safe — the matrix is only ever a subset of a live truth.
        if (isset(static::$resolving[$assistant->id])) {
            return false; // cycle — deny
        }

        static::$resolving[$assistant->id] = true;

        try {
            return PermissionService::userHasPermission($agent, $permissionKey);
        } finally {
            unset(static::$resolving[$assistant->id]);
        }
    }

    /**
     * The data scope for an assistant on a module: the narrower of what the agent granted
     * in the matrix and what the agent themselves actually has.
     *
     * Returns null (= no access at all; every scopeVisibleTo reads this as "no rows") when
     * the assistant has no live assignment, or the agent has no access to the module.
     *
     * NOTE this answers "how WIDE", not "whose". The 'own' scope for an assistant means the
     * AGENT's own — that is User::dataIdentityIds(), wired into scopeVisibleTo in Prompt D.
     *
     * This is the VIEW breadth: an assistant SEES exactly what their agent sees (if the agent
     * is a branch manager, the assistant sees the branch). MUTATION is separately pinned to the
     * agent's OWN records — an assistant may never EDIT another agent's item even when they can
     * see it. That pin lives in the per-record authorize traits (AuthorizesPropertyAccess,
     * AuthorizesDealAccess, AuthorizesContactAccess), not here. Spec §7.2.
     */
    public static function dataScope(User $assistant, string $module): ?string
    {
        $assignment = $assistant->activeAssistantAssignment();

        if (!$assignment) {
            return null;
        }

        $agent = $assignment->assignedAgent;

        if (!$agent || !$agent->is_active) {
            return null;
        }

        // The agent's own breadth is the ceiling. If the agent cannot see the module at all,
        // neither can their assistant — whatever the matrix says.
        $agentScope = PermissionService::getDataScope($agent, $module);

        if ($agentScope === null) {
            return null;
        }

        $matrixScope = $assignment->scopeFor($module . '.view');

        if ($matrixScope === null) {
            return null; // the agent did not hand this module over
        }

        // clampScope() already implements exactly this ceiling semantic (own < branch < all).
        // Reuse it — do not write a second one. The assistant's VIEW breadth is the agent's
        // (never wider); the narrower MUTATION pin is enforced per-record, not here.
        return PermissionService::clampScope($matrixScope, $agentScope);
    }

    /** Is this key in the property-upload locked set? */
    public static function isLocked(string $permissionKey): bool
    {
        return in_array($permissionKey, static::lockedSet(), true);
    }

    /** @return string[] */
    public static function lockedSet(): array
    {
        return (array) config('assistants.property_upload_locked_set', []);
    }
}

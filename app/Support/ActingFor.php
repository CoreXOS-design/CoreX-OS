<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * AT-267 §11 — who is the authed user acting FOR?
 *
 * When an assistant performs an action, the audit trail must record BOTH facts: the
 * assistant did it (the existing actor column), and they did it on the Assigned Agent's
 * behalf (this). Without the second, an assistant's work is indistinguishable from the
 * agent's own — the exact FICA/POPIA/PPRA hole the Assistants feature exists to close.
 *
 * Mirrors App\Support\Impersonation::actingAdminId() (AT-118): a single, session/console-safe
 * resolver every audit writer calls, rather than each writer re-deriving the relationship.
 *
 * Returns the Assigned Agent's id when the authed user is an active assistant; null in every
 * other case — a normal user, an unassigned/suspended assistant, or any console/queue/webhook
 * context with no authenticated user.
 *
 * Multi-agent addendum (assistants-multi-agent-spec.md §6.3) — an assistant may now support a
 * Main Agent AND linked Sub-Agents, so "on behalf of" is no longer a single fixed answer:
 *
 *   - EDITING an existing record: pass that record's OWN current owner column value
 *     (e.g. $property->agent_id). That is the correct "on behalf of" regardless of which
 *     agent the assistant is currently "Acting for" — editing a Sub-Agent's property must
 *     audit as that Sub-Agent, never the Main Agent, or the audit trail misattributes exactly
 *     the FICA/POPIA fact this feature exists to get right.
 *   - CREATING a new record: omit the argument. It falls through to ownershipUserId(), which
 *     resolves the explicit "Acting for" choice (session or an explicit per-call value) the
 *     SAME WAY the record's ownership itself was just resolved — audit and ownership always
 *     agree on a create.
 *
 * Every existing call site that passes nothing reproduces exactly today's behaviour for an
 * assistant with no linked Sub-Agents (the entire population before this addendum).
 */
class ActingFor
{
    public static function onBehalfOfUserId(?int $recordOwnerUserId = null): ?int
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return null;
        }

        // isAssistant() is true only when the flag AND a live, agency-enabled assignment agree.
        if (!$user->isAssistant()) {
            return null;
        }

        // A record's actual current owner (Main Agent or an active linked Sub-Agent) takes
        // precedence on an EDIT — this is what makes editing a Sub-Agent's property audit
        // correctly, regardless of the assistant's currently-selected "Acting for" choice.
        if ($recordOwnerUserId !== null && in_array($recordOwnerUserId, $user->dataIdentityIds(), true)) {
            return $recordOwnerUserId;
        }

        // No record to defer to (a CREATE path) — resolve the same way ownership itself does,
        // so the audit trail and the record's owner always agree.
        return $user->ownershipUserId();
    }
}

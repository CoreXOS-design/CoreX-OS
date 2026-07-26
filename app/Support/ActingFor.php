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
 */
class ActingFor
{
    public static function onBehalfOfUserId(): ?int
    {
        $user = Auth::user();

        if (!$user instanceof User) {
            return null;
        }

        // isAssistant() is true only when the flag AND a live, agency-enabled assignment agree.
        if (!$user->isAssistant()) {
            return null;
        }

        return $user->assignedAgent()?->id;
    }
}

<?php

namespace App\Services\Buyers;

use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchReassignment;
use App\Models\User;
use App\Support\Audit\AuditContext;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 2026-10-07 — the ONE place a buyer is moved to another agent.
 *
 *  A. The FIRST agent to receive a buyer's lead is the primary agent
 *     (contacts.agent_id, set once at creation). A later lead to another agent
 *     never changes it — nothing here is called by lead intake.
 *  B. A reassignment happens only when a user does it by hand, and is logged:
 *     one `agent_assigned` row in the contact history (who / when / from / to,
 *     written by ContactObserver) plus one contact_match_reassignments row per
 *     search moved (with the reason).
 *  C. The primary agent is the one who works the buyer's saved searches, so a
 *     manual move — from Core Matches, the Buyer Pipeline, or the primary-agent
 *     field on the contact edit screen — moves the primary agent AND every saved
 *     search of that buyer, in ONE transaction.
 *
 * Permission is the CALLER's job (core_matches.reassign on the board / pipeline
 * routes and ContactMatch::reassignTo(); contacts.reassign_agent on the contact
 * edit screen): the two entry points deliberately have different gates.
 */
class BuyerReassignmentService
{
    public const REASON_CONTACT_SCREEN = 'Primary agent changed on the contact record.';

    /**
     * Move the buyer: primary agent + every saved search, atomically.
     *
     * @param ContactMatch|null $via the search the manager acted on (its
     *        reassignment record is always written, even if its owner already
     *        matched, and the instance is updated in memory for the caller).
     */
    public function reassignBuyer(Contact $contact, User $toAgent, User $movedBy, string $reason, ?ContactMatch $via = null): ?ContactMatchReassignment
    {
        return DB::transaction(function () use ($contact, $toAgent, $movedBy, $reason, $via) {
            $this->movePrimaryAgent($contact, $toAgent, $movedBy);

            return $this->moveSearches($contact, (int) $toAgent->id, $movedBy, $reason, $via);
        });
    }

    /**
     * Move every saved search of the buyer (any status, not archived) to the
     * agent. Writes one reassignment record per search that actually changes
     * owner (plus $via's, always). Call inside the caller's transaction when
     * the primary agent is saved separately (the contact edit screen).
     */
    public function moveSearches(Contact $contact, int $toAgentId, User $movedBy, string $reason, ?ContactMatch $via = null): ?ContactMatchReassignment
    {
        $viaRecord = null;

        $searches = ContactMatch::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->where('contact_id', $contact->id)
            ->get();

        foreach ($searches as $search) {
            $isVia = $via && (int) $via->id === (int) $search->id;
            if ($isVia) {
                $search = $via;
            }
            if (! $isVia && (int) $search->agent_id === $toAgentId) {
                continue;
            }

            $record = ContactMatchReassignment::record(
                $search,
                $search->agent_id !== null ? (int) $search->agent_id : null,
                $toAgentId,
                (int) $movedBy->id,
                $reason,
            );

            $search->agent_id = $toAgentId;
            $search->updated_by_user_id = $movedBy->id;
            $search->save();

            if ($isVia) {
                $viaRecord = $record;
            }
        }

        return $viaRecord;
    }

    /**
     * Set contacts.agent_id. Saved through the model so ContactObserver writes
     * the `agent_assigned` history row; AuditContext is stamped with the
     * manager so that row names who did it even with no one logged in. Resolved
     * without the contact scopes (a manager acting across branches must not
     * silently skip the move) with the agency pinned explicitly.
     */
    private function movePrimaryAgent(Contact $contact, User $toAgent, User $movedBy): void
    {
        $fresh = Contact::withoutGlobalScopes()
            ->where('agency_id', $contact->agency_id)
            ->find($contact->id);

        if (! $fresh || (int) $fresh->agent_id === (int) $toAgent->id) {
            return;
        }

        $fresh->agent_id = $toAgent->id;
        // A co-agent equal to the new primary is meaningless — collapse it.
        if ((int) $fresh->second_agent_id === (int) $toAgent->id) {
            $fresh->second_agent_id = null;
        }

        AuditContext::push();
        try {
            AuditContext::setUser($movedBy);
            $fresh->save();
        } finally {
            AuditContext::pop();
        }

        // Keep the caller's instance coherent (the board / tests read it after).
        $contact->agent_id = $fresh->agent_id;
        $contact->second_agent_id = $fresh->second_agent_id;
        $contact->syncOriginal();
    }
}

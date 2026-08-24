<?php

declare(strict_types=1);

namespace App\Listeners\Agent;

use App\Events\Agent\AgentDeactivated;
use App\Models\User;

/**
 * 2026-08-24 (Johan) — public-link resilience audit finding: the agent
 * business-card link (/corex/agents/{nameSlug}/{tag}) already has a working
 * takeover mechanism — User::resolveByQrSlug() follows a qr_reroute_user_id
 * chain to an active successor — but that pointer was ONLY ever set on hard
 * DELETE (UserManagementController::delete(), mandatory per spec
 * agent-qr-onboarding.md), never on plain deactivation. Deactivation is the
 * far more common departure event, and it left the chain unset, which is
 * exactly why the card 404'd: "confirmed by 3 real dead-end business-card
 * links on live" (see the audit's Part 1 table).
 *
 * This listens for the SAME AgentDeactivated domain event
 * UserManagementController::toggle() already fires — per non-negotiable #9
 * (corex-domain-events-spec.md), a new cross-pillar reaction to an existing
 * state change belongs in a listener, not bolted onto the controller.
 *
 * Deliberately narrow:
 *   - only acts if the agent actually has a qr_code_slug (no business card,
 *     nothing to reroute)
 *   - never overwrites an already-set qr_reroute_user_id (an admin — or a
 *     prior run of this listener — may have chosen a specific successor;
 *     this only fills the gap, it never second-guesses a real choice)
 *   - only sets a reroute when a fallback target actually resolves; if
 *     nothing does, the card stays a dead 404 exactly as before rather than
 *     rerouting to nothing — same "don't invent a false positive" standard
 *     applied everywhere else in this audit
 */
class SetQrRerouteOnDeactivation
{
    public function handle(AgentDeactivated $event): void
    {
        $user = $event->user;

        if (!$user->qr_code_slug || $user->qr_reroute_user_id) {
            return;
        }

        $agencyId = $user->agency_id;
        if (!$agencyId) {
            return;
        }

        $successor = User::resolveBranchManagerOrAdminFallback($agencyId, $user->branch_id);
        if (!$successor || $successor->id === $user->id) {
            return;
        }

        $user->forceFill(['qr_reroute_user_id' => $successor->id])->save();
    }
}

<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\CoreMatches\ReassignmentNotAuthorizedException;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

/**
 * AT-Core-Matches, Johan's ruling 1 + Task 3 — a single, server-enforced
 * action. Deliberately its own controller rather than a new method on
 * ContactMatchController, which is being built in parallel by the lane
 * that owns the Core Matches screen.
 */
class ContactMatchReassignmentController extends Controller
{
    public function reassign(Request $request, ContactMatch $match): RedirectResponse
    {
        $validated = $request->validate([
            // ExistsInScope, not a raw exists: rule — a raw exists: bypasses
            // AgencyScope and would let a cross-agency user id validate,
            // exactly the class of bug the rentals audit flagged elsewhere
            // (BUILD_STANDARD §6, fix the class not the instance).
            'to_agent_id' => ['required', 'integer', new \App\Rules\ExistsInScope(User::class)],
            'reason'      => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $toAgent = User::findOrFail($validated['to_agent_id']);

        try {
            $match->reassignTo($toAgent, $request->user(), $validated['reason']);
        } catch (ReassignmentNotAuthorizedException $e) {
            abort(403, $e->getMessage());
        }

        return back()->with('success', 'Buyer reassigned to ' . $toAgent->name . '.');
    }

    /**
     * Johan, 2026-10-07 — move a BUYER (not one search) to another agent: the primary
     * agent and every saved search move together, in one transaction, logged in the
     * contact history (who / when / from / to) with the reason on each search's record.
     */
    public function reassignBuyer(Request $request, Contact $contact): RedirectResponse
    {
        // Defence in depth — the route middleware is the first gate.
        abort_unless($request->user()->hasPermission('core_matches.reassign'), 403,
            'Only a branch manager or admin can move a buyer between agents.');

        $validated = $request->validate([
            'to_agent_id' => ['required', 'integer', new \App\Rules\ExistsInScope(User::class)],
            'reason'      => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $toAgent = User::findOrFail($validated['to_agent_id']);
        if (! $toAgent->is_active) {
            return back()->withErrors(['to_agent_id' => 'That agent is not active.']);
        }
        if ((int) $contact->agent_id === (int) $toAgent->id) {
            return back()->with('success', $contact->full_name . ' is already with ' . $toAgent->name . '.');
        }

        app(\App\Services\Buyers\BuyerReassignmentService::class)
            ->reassignBuyer($contact, $toAgent, $request->user(), trim($validated['reason']));

        return back()->with('success', $contact->full_name . ' moved to ' . $toAgent->name . '.');
    }
}

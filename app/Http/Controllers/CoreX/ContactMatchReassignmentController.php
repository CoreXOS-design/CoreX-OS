<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\CoreMatches\ReassignmentNotAuthorizedException;
use App\Http\Controllers\Controller;
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
}

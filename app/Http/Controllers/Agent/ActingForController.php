<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * AT-267 multi-agent addendum — the "Acting for" session switcher.
 *
 * Mirrors App\Http\Controllers\Admin\BranchSwitcherController exactly: writes a session value
 * that the relevant resolvers already honour (User::ownershipUserId(), spec §6.2), rather than
 * requiring every create surface to grow its own selector. Only meaningful for an assistant with
 * at least one linked Sub-Agent — for everyone else there is nothing to switch.
 *
 * Spec: .ai/specs/assistants-multi-agent-spec.md §6.2
 */
class ActingForController extends Controller
{
    public function update(Request $request)
    {
        $user = Auth::user();

        if (!$user || !$user->isAssistant()) {
            abort(403, 'Only an assistant can set who they are acting for.');
        }

        $data = $request->validate([
            'acting_for_user_id' => ['required', 'integer'],
        ]);

        $targetId = (int) $data['acting_for_user_id'];

        // Fail closed — only a currently valid choice (the Main Agent or an active linked
        // Sub-Agent) is honoured, never trusted blindly from the request (spec E7e).
        if (!in_array($targetId, $user->dataIdentityIds(), true) || $targetId === $user->id) {
            abort(422, 'That is not a valid choice for who you are acting for.');
        }

        session(['acting_for_user_id' => $targetId]);

        $target = User::find($targetId);

        return back()->with('status', 'Now acting for: ' . ($target?->name ?? 'Unknown'));
    }

    public function clear(Request $request)
    {
        session()->forget('acting_for_user_id');

        return back()->with('status', 'Acting-for choice cleared — back to your Main Agent.');
    }
}

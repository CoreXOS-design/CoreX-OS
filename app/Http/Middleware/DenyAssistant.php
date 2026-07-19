<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-267 — hard block on AGENT-PERSONAL surfaces an assistant must never reach, whatever their
 * matrix says.
 *
 * Some routes are personal to the practitioner and carry NO permission of their own — the
 * clearest is `/my-earnings` (commission.dashboard), which is ungated and would otherwise show
 * an assistant a commission dashboard (spec §10: an assistant has no commission and must never
 * see it). A permission-key lock cannot close these because there is no key to lock; a nav
 * `@unless` alone is cosmetic (the URL is still reachable). This middleware is the route-level
 * half — the nav gate and this deny the same surface, so there is no gap between what an
 * assistant is shown and what they can reach.
 *
 * Blocks on the raw `is_assistant` flag (not isAssistant()) on purpose: an assistant must not
 * see commission even if the agency kill switch is off and they have degraded to a zero-grant
 * account — being flagged an assistant is enough.
 *
 * Apply with the `deny_assistant` alias to any route that is the practitioner's own and never
 * an assistant's to see.
 */
class DenyAssistant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_assistant) {
            return $next($request);
        }

        Log::channel('security')->warning('AT-267 assistant blocked from an agent-personal route', [
            'assistant_user_id' => $user->id,
            'agent_user_id'     => method_exists($user, 'assignedAgent') ? $user->assignedAgent()?->id : null,
            'route'             => optional($request->route())->getName(),
            'uri'               => $request->path(),
            'ip'                => $request->ip(),
        ]);

        $message = 'That page belongs to the agent you assist and is not available to assistants.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('agent.portal')->with('assistant_blocked', $message);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-267 / AUDIT 2026-07-26 (F1) — layer 2 of the "can edit & delete my records" toggle.
 *
 * The agent's control page offers: "{Assistant} can edit & delete my records, not just add them."
 * Layer 1 is semantic and precise — `PermissionService::mutationScope()` returns null, and
 * `AuthorizesContactAccess` / the task + calendar guards read `User::canMutateRecords()` directly.
 * That covers every surface that has a per-record guard today: properties, contacts, deals,
 * deals-v2, documents, the e-sign pipeline, tasks, calendar, mobile properties.
 *
 * This is the layer for everything else. CoreX has ~1,878 authenticated routes and only a
 * fraction of the mutating ones pass through a per-record guard; enumerating the rest is exactly
 * the hand-maintained list that DenyAssistantPropertyWrite exists to warn about. So this inverts
 * the same way: for an assistant whose agent has switched the toggle OFF, ANY PUT / PATCH / DELETE
 * is denied unless the route is on a short, explicit ALLOW list of things that are the assistant's
 * OWN account rather than the agent's records.
 *
 * WHY THE VERBS. "Add" is POST; "edit" is PUT/PATCH; "delete" is DELETE. Blocking the three
 * mutating verbs and leaving POST alone is the toggle's own sentence expressed in HTTP, needs no
 * list to stay current, and fails CLOSED — a new PUT route ships denied to a restricted assistant
 * and someone tells us, rather than shipping open and nobody ever knowing.
 *
 * WHAT IT DELIBERATELY DOES NOT CATCH. CoreX updates plenty of things over POST (e.g.
 * `docuperfect.documents.rename`). Those are layer 1's job, and layer 1 has them. The two layers
 * are complementary by design — neither is asked to be complete on its own.
 *
 * INERT for everyone else, including an assistant whose toggle is ON: one boolean per request.
 */
class DenyAssistantRecordMutation
{
    /**
     * Mutations that are the assistant's OWN account, not the agent's records.
     *
     * An assistant restricted to add-and-view must still be able to run their own login: change
     * their password, fix their profile, set their theme, choose their notification preferences.
     * None of these touches a record belonging to the agent.
     *
     * `profile.destroy` is absent on purpose — it already carries `deny_assistant` (deleting an
     * assistant is an admin action, spec §10) and must not be re-permitted here.
     */
    private const ASSISTANT_MAY = [
        'profile.update',
        'profile.theme',
        'password.update',
        'agent.portal.profile.update',
        'v1.me.theme.update',
        'v1.notification-preferences.update',
        'legacy.notification-preferences.update',
        'my-portal.comm-capture.update',
        'my-portal.comm-capture.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // The overwhelmingly common path: not an assistant at all.
        if (! $user || ! $user->is_assistant) {
            return $next($request);
        }

        // Reads, and adds (POST), are untouched — the toggle restricts edit and delete only.
        if (! in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        // canMutateRecords() is the same helper layer 1 and the view layer read, so middleware,
        // guard and UI can never drift. Only an assistant whose agent switched the toggle OFF
        // reaches the block below.
        if ($user->canMutateRecords()) {
            return $next($request);
        }

        $routeName = optional($request->route())->getName();

        if ($routeName && in_array($routeName, self::ASSISTANT_MAY, true)) {
            return $next($request);
        }

        // Audited like the sibling denials: either the UI is showing an edit control it should
        // have greyed out (a bug worth fixing), or someone is probing. Both are worth knowing.
        Log::channel('security')->warning('AT-267 assistant record mutation blocked (can_manage_my_records off)', [
            'assistant_user_id' => $user->id,
            'agent_user_id'     => $user->assignedAgent()?->id,
            'route'             => $routeName,
            'uri'               => $request->path(),
            'method'            => $request->method(),
            'ip'                => $request->ip(),
        ]);

        $agentName = $user->assignedAgent()?->name ?? 'the agent you assist';
        $message   = 'Editing and deleting records is switched off for your assistant account. '
            . 'You can still add new ones — ask ' . $agentName . ' to enable changes if you need them.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}

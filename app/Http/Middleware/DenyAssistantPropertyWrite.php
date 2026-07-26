<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-267 — layer 2 of the property-upload lock: the routes the resolver cannot see.
 *
 * An assistant may NEVER create a listing. The resolver (AssistantPermissionResolver) already
 * denies every permission key in config('assistants.property_upload_locked_set') — but a
 * permission-key lock is provably not enough here, because SEVERAL property-creation paths in
 * CoreX carry NO permission key at all (spec §2.3):
 *
 *   - POST /corex/properties            — the classic store; the group only checks access_properties
 *   - the wizard mutation routes        — photos / step / finalize; data-scope checked, no key
 *   - POST /api/v1/mobile/properties    — mobile create + image upload; NO permission middleware
 *   - POST /api/v1/properties/pull-from-portal — portal pull; NO permission middleware
 *   - POST /api/v1/prospecting/import   — tracked-property create; NO permission middleware
 *
 * The wizard's `start` and `createDraft` DO check properties.create, so the resolver already
 * closes those. Everything else in that list would have been wide open to an assistant with
 * nothing more than access_properties — which their agent can hand them quite reasonably, since
 * an assistant absolutely should be able to VIEW and EDIT the agent's listings. They just may
 * not create one.
 *
 * WHY THIS BLOCKS BY DEFAULT. The obvious implementation is an explicit list of route names to
 * block. That list is a thing someone has to remember to update, and the day they forget, a new
 * property-write endpoint ships open. So the check is inverted: for an assistant, ANY non-GET
 * request into a property-write surface is denied unless the route is on a short, explicit
 * ALLOW list of things an assistant legitimately does (edit, photos on an existing listing the
 * agent owns, geocode, etc.). Forgetting to update the allow list fails CLOSED — an assistant
 * gets a clear 403 and someone tells us — rather than failing open and nobody ever knowing.
 *
 * Spec: .ai/specs/assistants-feature-spec.md §9 layer 2
 */
class DenyAssistantPropertyWrite
{
    /**
     * Property-surface writes an assistant IS allowed to perform, by route name.
     *
     * These are all operations on a listing that ALREADY EXISTS and belongs to their Assigned
     * Agent (the data scope still confines them to the agent's book). None of them can bring a
     * new property onto the agency's books.
     *
     * Adding a name here is a deliberate decision. If in doubt, leave it out — the assistant
     * gets a clear "ask your agent" message, which is a far better failure than a listing
     * nobody meant to create.
     */
    private const ASSISTANT_MAY = [
        'corex.properties.update',          // edit an existing listing
        'corex.properties.geocode',         // resolve an address pin
        'v1.properties.geocode',
        'corex.properties.restore',         // un-archive (reversible, no new stock)
        'v1.mobile.properties.update',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->is_assistant) {
            return $next($request);
        }

        // Reads are always fine — an assistant is supposed to work the agent's listings.
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $routeName = optional($request->route())->getName();

        if ($routeName && in_array($routeName, self::ASSISTANT_MAY, true)) {
            return $next($request);
        }

        // Audited: a blocked attempt is a signal, not noise. Either an assistant is trying to
        // do something they were told they could (a UI bug — we are showing them a button we
        // should not), or someone is probing. Both are worth knowing about.
        Log::channel('security')->warning('AT-267 assistant property-write blocked', [
            'assistant_user_id' => $user->id,
            'agent_user_id'     => $user->assignedAgent()?->id,
            'route'             => $routeName,
            'uri'               => $request->path(),
            'method'            => $request->method(),
            'ip'                => $request->ip(),
        ]);

        $message = 'Assistants cannot create or import listings. Ask ' .
            ($user->assignedAgent()?->name ?? 'your agent') . ' to create the listing — you can work on it once it exists.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}

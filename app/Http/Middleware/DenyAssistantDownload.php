<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-267 — the assistant "download documents" gate.
 *
 * A per-assignment toggle the AGENT controls on their assistant's control page
 * (assistant_assignments.can_download_documents). When ON, the assistant may download document
 * files anywhere they can already reach them; when OFF, every document-download endpoint returns
 * 403. The assistant can still OPEN and VIEW a document in the browser — this blocks only the act
 * of pulling the file down.
 *
 * WHY A DEDICATED MIDDLEWARE. Downloads are scattered across ~13 authenticated endpoints (contacts,
 * deals, deals-v2, document library, shared drive, every DocuPerfect surface) that each build their
 * own response()->download(); there is no single helper they all pass through. Gating one helper
 * would miss most of them. Applying this alias to each download route is the smallest reliable
 * chokepoint, and mirrors the established `deny_assistant` / `deny_assistant_property_write` pattern.
 *
 * Gates on isAssistant() (not raw is_assistant): a live assignment is required to read the toggle,
 * and a degraded/zero-grant assistant (kill switch off) is already denied everything upstream by
 * the permission layer, so there is nothing to add here. Fails CLOSED — a missing assignment or a
 * false/absent toggle both deny.
 *
 * Apply with the `deny_assistant_download` alias to any route that streams a stored document file.
 */
class DenyAssistantDownload
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Not an assistant → unaffected. A non-assistant download behaves exactly as before.
        if (!$user || !$user->is_assistant) {
            return $next($request);
        }

        $assignment = $user->activeAssistantAssignment();

        // Toggle ON with a live assignment → allowed. Everything else (no assignment, toggle off)
        // fails closed to a denial.
        if ($assignment && $assignment->can_download_documents === true) {
            return $next($request);
        }

        Log::channel('security')->warning('AT-267 assistant document download blocked', [
            'assistant_user_id' => $user->id,
            'agent_user_id'     => $user->assignedAgent()?->id,
            'route'             => optional($request->route())->getName(),
            'uri'               => $request->path(),
            'method'            => $request->method(),
            'ip'                => $request->ip(),
        ]);

        $agentName = $user->assignedAgent()?->name ?? 'the agent you assist';
        $message   = 'Downloading documents is switched off for your assistant account. Ask ' .
            $agentName . ' to enable it if you need to download files.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
        }

        abort(Response::HTTP_FORBIDDEN, $message);
    }
}

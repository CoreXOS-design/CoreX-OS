<?php

namespace App\Http\Middleware;

use App\Support\Audit\PropertyAuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AT-321 — stamp the authenticated user into the property-audit context (and the
 * DB session vars the unbypassable trigger reads) for the duration of the request.
 * App-layer rows already resolve auth()->user() at write time; this additionally
 * attributes any RAW property write made during the request (e.g. an admin bulk
 * reassign via DB::table) to the acting user via the trigger.
 */
class SetPropertyAuditActor
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if ($user = $request->user()) {
                PropertyAuditContext::setUser($user);
            }
        } catch (\Throwable) {
            // never block a request over audit plumbing
        }

        return $next($request);
    }
}

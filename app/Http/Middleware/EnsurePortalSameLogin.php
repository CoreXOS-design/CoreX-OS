<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * .ai/specs/rental-portal-access.md section 28 - a portal page only ever acts as the person it is showing.
 *
 * One browser holds ONE portal session however many tabs are open. Open the owner's page in one tab, then sign in as the tenant in
 * another (or sign the owner out there): the first tab still says "Siyabonga - Owner view", but its next click would reach the server
 * with the TENANT's cookie. The page therefore sends which login it believes it is (`X-Portal-Expect: <client id>`, learned from
 * /client/me), and a request whose session is now somebody else's is refused with 409 `session_changed` before anything runs - the
 * page replaces itself with "someone else signed in on this browser, reload". The mobile app and any client that sends no header
 * are unaffected.
 */
class EnsurePortalSameLogin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expect = $request->header('X-Portal-Expect');
        if ($expect !== null && ctype_digit((string) $expect) && (int) $expect !== (int) ($request->user()?->getAuthIdentifier() ?? 0)) {
            return response()->json([
                'message' => 'Someone else has signed in on this browser. Reload this page to continue as that person.',
                'session_changed' => true,
            ], 409);
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Models\Agency;
use Closure;
use Illuminate\Http\Request;

/**
 * Refuses a single-agent account (agencies.account_kind = 'solo', solo-agent-accounts.md) on a team / portal-intake page it
 * does not have. The CoreX owner is never refused. Pair it with hiding the link; this is the half that stops a typed address.
 */
class NotSoloAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        abort_if(
            $user && ! $user->isOwnerRole() && Agency::idIsSolo((int) $user->effectiveAgencyId()),
            403,
            'This is not part of a single-agent account.'
        );

        return $next($request);
    }
}

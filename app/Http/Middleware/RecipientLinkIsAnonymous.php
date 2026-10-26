<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Recipient signing links (/sign/{token}) are for people who are not CoreX users, so the request runs as an anonymous
 * visitor: the link itself is the credential. Without this, a staff member who opens a link in a browser that also holds
 * their staff session is treated as that user - and AgencyScope / BelongsToAgency then apply THEIR active agency to the
 * link's template, document and markers. A staff session switched into another agency made the envelope's own template
 * invisible ($signingRequest->template === null -> 500), and any row the signing wrote would have been stamped with the
 * staff user's agency. SigningController never reads the logged-in user, so nothing there depends on it.
 */
class RecipientLinkIsAnonymous
{
    public function handle(Request $request, Closure $next)
    {
        $previous = Auth::getDefaultDriver();
        Auth::shouldUse('recipient-link');

        try {
            return $next($request);
        } finally {
            Auth::shouldUse($previous);
        }
    }
}

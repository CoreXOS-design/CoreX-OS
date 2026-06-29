<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventAuthPageCaching
{
    /**
     * Force unauthenticated auth pages (login, register, forgot/reset password)
     * to never be cached or restored from the browser's back/forward cache
     * (bfcache).
     *
     * Root cause of intermittent login 419s: a browser may re-display a stale
     * login page — via bfcache, the Back button, or a tab left open past
     * SESSION_LIFETIME — whose embedded CSRF token no longer matches the
     * session. Submitting it raises a TokenMismatchException ("419 Page
     * Expired"), and the user has to reload several times before a fresh token
     * renders. The `no-store` directive opts the page out of bfcache and HTTP
     * caching, so every visit renders a live token that matches the session.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}

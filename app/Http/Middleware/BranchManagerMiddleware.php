<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BranchManagerMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Auth::check()) {
        $u = auth()->user();
        abort_unless($u && ($u->isEffectiveAdmin() || $u->isEffectiveBranchManager()), 403);
        return $next($request);

        }

        $user = Auth::user();

        if ($user->isEffectiveAdmin() || $user->isEffectiveBranchManager()) {
            return $next($request);
        }

        abort(403);
    }
}

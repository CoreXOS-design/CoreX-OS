<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminOrBranchManager
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        if (!$user) {
            abort(403);
        }

        if ($user->isEffectiveAdmin() || $user->isEffectiveBranchManager()) {
            return $next($request);
        }

        abort(403);
    }
}

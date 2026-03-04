<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permissionKey): Response
    {
        if (!auth()->check() || !auth()->user()->hasPermission($permissionKey)) {
            abort(403, 'You don\'t have access to this resource.');
        }

        return $next($request);
    }
}

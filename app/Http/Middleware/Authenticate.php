<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    protected function redirectTo($request): ?string
    {
        if (!$request->expectsJson()) {
            return route('login');
        }

        return null;
    }

    protected function authenticate($request, array $guards)
    {
        parent::authenticate($request, $guards);

        if (auth()->check() && !auth()->user()->is_active) {
            auth()->logout();
            abort(403, 'Account inactive');
        }
    }
}

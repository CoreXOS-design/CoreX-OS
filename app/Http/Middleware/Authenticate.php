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

        $user = auth()->user();
        if (!$user) return;

        if (!$user->is_active) {
            auth()->logout();
            abort(403, 'Account inactive');
        }

        if ($user->agency_id) {
            $agency = \App\Models\Agency::find($user->agency_id);
            if (!$agency || !$agency->is_active) {
                auth()->logout();
                abort(403, 'Your agency has been disabled. Contact your administrator.');
            }
        }
    }
}

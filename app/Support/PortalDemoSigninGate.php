<?php

namespace App\Support;

use Illuminate\Support\Facades\Route;

/**
 * QA DEMO SIGN-IN gate (never on Staging/live). The ONE test for "may the demo sign-in exist here": the application environment is `qa`
 * AND the flag is on. The flag defaults to false and is only ever set in the QA1 .env. The route is registered only when the gate is open;
 * the controller and the artisan command check it again, so a route registered by mistake still answers 404.
 * Spec: .ai/specs/rental-portal-access.md "QA demo sign-in".
 */
final class PortalDemoSigninGate
{
    public const ENVIRONMENT = 'qa';

    public static function open(): bool
    {
        return app()->environment(self::ENVIRONMENT) && (bool) config('clientauth.demo_signin_enabled', false);
    }

    /** Called from routes/web.php. Registers nothing at all unless the gate is open. */
    public static function registerRoutes(): void
    {
        if (! self::open()) {
            return;
        }
        Route::middleware(['web', 'throttle:30,1'])->get('/demo-signin/{token}', [\App\Http\Controllers\PortalDemoSigninController::class, 'open'])
            ->where('token', '[A-Za-z0-9]{20,128}')->name('rentals.portal.demo-signin');

        // Every emailed portal link signs its tab straight in on QA (no code, no password), for any contact on any lease - including ones made live
        // during a demo. Same gate: this route does not exist anywhere else.
        //
        // NO `web` group on this one (registerRoutes() is called from routes/web.php, which wraps it in `web`): EnsureFrontendRequestsAreStateful
        // runs its own session stack, and a second one on top used to read the browser's session cookie a second time as if it were an
        // unknown session, start a NEW empty session and overwrite the cookie - signing a STAFF user in the same browser out the moment
        // an owner/tenant link was opened. Every other /api/v1/client-auth route is in the `api` group, which has no session stack.
        Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, 'throttle:30,1'])
            ->withoutMiddleware('web')
            ->post('/api/v1/client-auth/qa-link-signin', [\App\Http\Controllers\Api\V1\ClientAuthController::class, 'qaLinkSignin'])
            ->name('client-auth.qa-link-signin');
    }
}

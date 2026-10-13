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
    }
}

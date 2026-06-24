<?php

namespace App\Http\Middleware;

use App\Services\MaintenanceMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * System-wide maintenance gate (AT-93).
 *
 * Appended to the `web` middleware group, so it runs AFTER StartSession
 * (the authenticated user is resolvable) and BEFORE app route handlers.
 *
 * When maintenance is ON:
 *   - System Owners (User::isOwnerRole(), the same gate as `owner_only`)
 *     pass through to the normal app — they run final go-live checks.
 *   - Everyone else (agents, BMs, guests) gets the branded 503 down-page.
 *   - Login / logout / password-reset / the toggle routes are ALWAYS
 *     exempt, so an owner can sign in and lift maintenance, and so a
 *     non-owner is bounced back to the down-page after logging in.
 *
 * This never locks an owner out. See also the `corex:maintenance` artisan
 * escape hatch.
 *
 * Spec: .ai/specs/maintenance-mode.md
 */
class MaintenanceGate
{
    public function __construct(private readonly MaintenanceMode $maintenance)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Fast path: maintenance OFF → behave exactly as before (one stat).
        if (!$this->maintenance->isActive()) {
            return $next($request);
        }

        // Routes that must NEVER be blocked, or an owner could not log in
        // and lift maintenance.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        // System Owners retain full normal access.
        $user = $request->user();
        if ($user && $user->isOwnerRole()) {
            return $next($request);
        }

        // Everyone else → branded down-page. JSON callers get JSON.
        $meta = $this->maintenance->meta();

        if ($request->expectsJson()) {
            return response()->json([
                'ok'      => false,
                'error'   => 'CoreX is temporarily down for maintenance. Please try again shortly.',
                'status'  => 'maintenance',
            ], 503);
        }

        return response()->view('errors.maintenance', ['meta' => $meta], 503);
    }

    /**
     * Auth + toggle routes that are always reachable during maintenance.
     * Matched by route name first, with a path-prefix safety net for the
     * unnamed POST /login route and framework/health endpoints.
     */
    private function isExempt(Request $request): bool
    {
        $name = optional($request->route())->getName();

        $exemptNames = [
            'login', 'logout',
            'password.request', 'password.email',
            'password.reset', 'password.store',
            'password.confirm', 'password.update',
            'admin.maintenance.enable', 'admin.maintenance.disable',
        ];

        if ($name !== null && in_array($name, $exemptNames, true)) {
            return true;
        }

        // Path safety net: the POST /login route is unnamed, and we never
        // want to block auth or health/asset endpoints regardless of naming.
        $path = $request->path(); // no leading slash, '/' becomes ''
        $exemptPaths = [
            'login', 'logout', 'forgot-password', 'reset-password',
            'admin/maintenance', 'up',
        ];

        foreach ($exemptPaths as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}

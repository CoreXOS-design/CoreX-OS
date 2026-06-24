<?php

namespace App\Http\Middleware;

use App\Models\Agency;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-agency maintenance gate (AT-93, re-scoped).
 *
 * Maintenance is a TENANT-level state, not platform-level. This gate runs in
 * the web group AFTER the session has booted, so the authenticated user — and
 * therefore their agency — is resolvable. It only ever acts on a logged-in,
 * non-owner user whose resolved agency is flagged `maintenance_mode`:
 *
 *   - No authenticated user (guests, the login/auth pages)  → pass. The CoreX
 *     login is ALWAYS reachable; a tenant in maintenance never takes it down.
 *   - System Owner (User::isOwnerRole())                    → pass (bypass), so
 *     the agency under maintenance stays reachable for the work being done.
 *   - Authenticated user whose agency is in maintenance     → branded 503
 *     splash (errors/maintenance.blade.php) with that agency's message.
 *   - Everyone else (other agencies)                        → pass, normal app.
 *
 * Login + logout stay exempt so a maintenance-agency user can still sign out.
 *
 * Spec: .ai/specs/maintenance-mode.md
 */
class AgencyMaintenanceGate
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Guests and the auth pages always pass — login never goes down.
        if (!$user) {
            return $next($request);
        }

        // System Owners bypass entirely.
        if (method_exists($user, 'isOwnerRole') && $user->isOwnerRole()) {
            return $next($request);
        }

        // Always let a maintenance-agency user reach login/logout/password.
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $agencyId = $user->effectiveAgencyId();
        if (!$agencyId) {
            return $next($request);
        }

        // Resolve the agency directly (Agency has no AgencyScope), so the
        // lookup is unaffected by tenant scoping.
        $agency = Agency::find($agencyId);
        if (!$agency || !$agency->isInMaintenance()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok'     => false,
                'error'  => $agency->maintenance_message
                    ?: 'This agency is temporarily down for maintenance. Please try again shortly.',
                'status' => 'maintenance',
            ], 503);
        }

        return response()->view('errors.maintenance', [
            'meta'   => [
                'message'    => $agency->maintenance_message,
                'enabled_at' => optional($agency->maintenance_started_at)->toIso8601String(),
            ],
            'agency' => $agency,
        ], 503);
    }

    /**
     * Auth routes that must stay reachable so a maintenance-agency user can
     * sign out / re-authenticate.
     */
    private function isExempt(Request $request): bool
    {
        $name = optional($request->route())->getName();

        if ($name !== null && in_array($name, [
            'login', 'logout',
            'password.request', 'password.email',
            'password.reset', 'password.store',
            'password.confirm', 'password.update',
        ], true)) {
            return true;
        }

        $path = $request->path();
        foreach (['login', 'logout', 'forgot-password', 'reset-password'] as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }
}

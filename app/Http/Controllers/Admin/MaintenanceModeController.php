<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\MaintenanceMode;
use Illuminate\Http\Request;

/**
 * Owner-only toggle for system-wide maintenance mode (AT-93).
 *
 * Routes are already wrapped in the `owner_only` middleware; the explicit
 * isOwnerRole() guard here is defence-in-depth (non-negotiable #5 —
 * controller checks). The toggle is a state change, not a delete.
 *
 * Spec: .ai/specs/maintenance-mode.md
 */
class MaintenanceModeController extends Controller
{
    public function __construct(private readonly MaintenanceMode $maintenance)
    {
    }

    public function enable(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->isOwnerRole(), 403, 'This control is restricted to System Owners.');

        $this->maintenance->enable(
            by: $user->name ?: ($user->email ?: 'user#'.$user->id),
        );

        return redirect()->route('admin.dev-settings.index')
            ->with('success', 'Maintenance mode is ON — only System Owners can access CoreX. Everyone else sees the maintenance page.');
    }

    public function disable(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->isOwnerRole(), 403, 'This control is restricted to System Owners.');

        $this->maintenance->disable();

        return redirect()->route('admin.dev-settings.index')
            ->with('success', 'Maintenance mode is OFF — CoreX is live for all users.');
    }
}

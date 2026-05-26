<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * System Owner / Developer user administration.
 *
 * Lists every user whose role has is_owner=true. These users are platform
 * identities with NULL agency_id by design and are excluded from
 * User::agencyMembers(), so they never appear in normal user management.
 *
 * Per R1 of .ai/specs/developer-users.md the list is shown regardless of
 * which agency the viewer is currently switched into — this is the
 * documented withoutGlobalScope opt-in for cross-agency owner views.
 *
 * Owner-only — gated by `owner_only` middleware at the route level.
 */
class DeveloperUserController extends Controller
{
    public function index(): View
    {
        $ownerRoleNames = User::ownerRoleNames();

        $users = User::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->whereIn('role', $ownerRoleNames)
            ->orderBy('name')
            ->get();

        $roleLabels = Role::allRoles()
            ->whereIn('name', $ownerRoleNames)
            ->pluck('label', 'name');

        return view('admin.developer-users.index', compact('users', 'roleLabels'));
    }

    public function toggleActive(Request $request, int $userId): RedirectResponse
    {
        $user = User::withoutGlobalScope(AgencyScope::class)->findOrFail($userId);
        abort_unless($user->isOwnerRole(), 404);

        $user->is_active = ! ($user->is_active ?? true);
        $user->save();

        return redirect()
            ->route('admin.developer-users.index')
            ->with('status', $user->name . ' is now ' . ($user->is_active ? 'active' : 'disabled') . '.');
    }
}

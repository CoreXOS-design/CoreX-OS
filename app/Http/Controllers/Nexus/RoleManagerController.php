<?php

namespace App\Http\Controllers\Nexus;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\NexusPermission;
use App\Models\RolePermission;
use App\Models\User;
use Illuminate\Http\Request;

class RoleManagerController extends Controller
{
    private const ROLES = ['super_admin', 'admin', 'branch_manager', 'agent', 'viewer'];

    public function index()
    {
        $permissions = NexusPermission::orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        $sections = $permissions->pluck('section')->unique()->values();

        // Build a lookup: role_permissions[permission_key][role] = true
        $granted = RolePermission::all()
            ->groupBy('permission_key')
            ->map(fn($group) => $group->pluck('role')->flip()->map(fn() => true));

        $users    = User::where('is_active', 1)->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'branch_id', 'agency_id', 'designation']);
        $branches = Branch::orderBy('name')->get(['id', 'name']);
        $agencies = Agency::orderBy('name')->get(['id', 'name']);

        return view('nexus.role-manager', [
            'permissions' => $permissions,
            'sections'    => $sections,
            'granted'     => $granted,
            'roles'       => self::ROLES,
            'users'       => $users,
            'branches'    => $branches,
            'agencies'    => $agencies,
        ]);
    }

    public function savePermissions(Request $request)
    {
        $request->validate([
            'permissions'   => 'nullable|array',
            'permissions.*' => 'array',
        ]);

        // Wipe existing and rebuild
        RolePermission::truncate();

        $matrix = $request->input('permissions', []);
        $rows = [];
        $now = now();

        foreach ($matrix as $permKey => $roles) {
            foreach ($roles as $role => $on) {
                if ($on && in_array($role, self::ROLES)) {
                    $rows[] = [
                        'role'           => $role,
                        'permission_key' => $permKey,
                        'created_at'     => $now,
                        'updated_at'     => $now,
                    ];
                }
            }
        }

        if (count($rows)) {
            RolePermission::insert($rows);
        }

        return back()->with('success', 'Permissions saved successfully.');
    }

    public function updateUserRole(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => 'required|in:super_admin,admin,branch_manager,agent,viewer',
        ]);

        $user = User::findOrFail($request->user_id);
        $user->role = $request->role;
        $user->save();

        return back()->with('success', "Role updated for {$user->name}.");
    }
}

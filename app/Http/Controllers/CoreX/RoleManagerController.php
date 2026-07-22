<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\CoreXPermission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleManagerController extends Controller
{
    public function index()
    {
        $permissions = CoreXPermission::orderBy('section')
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get();

        $sections = $permissions->pluck('section')->unique()->values();

        $agencyId = auth()->user()?->effectiveAgencyId();

        // Roles + grants are agency-scoped (.ai/specs/roles-permissions.md).
        // Show only THIS agency's grants; owners without an active agency
        // context fall back to the global templates (agency_id IS NULL).
        $grantQuery = fn () => RolePermission::query()
            ->when(
                $agencyId,
                fn ($q) => $q->where('agency_id', $agencyId),
                fn ($q) => $q->whereNull('agency_id')
            );

        // Build a lookup: role_permissions[permission_key][role] = true
        $granted = $grantQuery()->get()
            ->groupBy('permission_key')
            ->map(fn($group) => $group->pluck('role')->flip()->map(fn() => true));

        // Build scope lookup: scopeGranted[permission_key][role] = 'own'|'branch'|'all'|null
        $scopeGranted = $grantQuery()->whereNotNull('scope')
            ->get()
            ->groupBy('permission_key')
            ->map(fn($group) => $group->pluck('scope', 'role'));

        $roles = Role::withCount(['users' => function ($q) use ($agencyId) {
            $q->where('is_active', 1);
            if ($agencyId) {
                $q->where(fn ($q2) => $q2->where('agency_id', $agencyId)->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId)));
            }
        }])
            // Hide owner roles from the Role Manager UI — they're platform
            // identities and bypass permission checks. They still exist in the
            // DB and continue to function; just not editable from this screen.
            ->where('is_owner', false)
            // Only this agency's own roles (templates stay invisible here).
            ->when(
                $agencyId,
                fn ($q) => $q->where('agency_id', $agencyId),
                fn ($q) => $q->whereNull('agency_id')
            )
            ->orderBy('sort_order')->get();

        $users = User::agencyMembers()
            ->where('is_active', 1)
            ->when($agencyId, fn ($q) => $q->where(fn ($q2) => $q2->where('agency_id', $agencyId)->orWhereHas('branch', fn ($b) => $b->where('agency_id', $agencyId))))
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'branch_id', 'agency_id', 'designation']);
        $branches = Branch::when($agencyId, fn ($q) => $q->where('agency_id', $agencyId))
            ->orderBy('name')->get(['id', 'name']);
        $agencies = Agency::orderBy('name')->get(['id', 'name']);

        // ── Build grouped matrix for action-level UI ──
        $moduleLabels = [
            'dashboard'        => 'Dashboard',
            'command_center'          => 'Command Center',
            'command_center_calendar' => 'Calendar',
            'command_center_tasks'    => 'Tasks',
            'agency_tracker'   => 'Agency Tracker',
            'deals'            => 'Deals',
            'deals_v2'         => 'Deal Register V2',
            'listings'         => 'Listings',
            'rentals'          => 'Rentals',
            'daily_activity'   => 'Daily Activity',
            'tv_messages'      => 'TV Messages',
            'compliance'       => 'Compliance',
            'supervision'      => 'Supervision',
            'training'         => 'Training',
            'communication'    => 'Communication',
            'client_portal'    => 'Client Portal',
            'franchise_admin'  => 'Franchise Admin',
            'users'            => 'Users',
            'docuperfect'      => 'DocuPerfect',
            'documents'        => 'Documents',
            'templates'        => 'Templates',
            'clauses'          => 'Clauses',
            'packs'            => 'Document Packs',
            'document_library' => 'Document Library',
            'presentations'    => 'Presentations',
            'filing'           => 'Filing Register',
            'commercial_evals' => 'Commercial Evaluations',
            'sales_docs'       => 'Sales Documents',
            'properties'       => 'Properties',
            'contacts'         => 'Contacts',
            'core_matches'     => 'Core Matches',
            'calculators'      => 'Calculators & Tools',
            'ad_manager'       => 'Ad Manager',
            'ellie'            => 'Ellie AI',
            'p24'              => 'P24 Market Intel',
            'prospecting'      => 'Prospecting',
            'evaluation'       => 'Evaluation',
            'pdf_splitter'     => 'PDF Splitter',
            'knowledge'        => 'Knowledge Base',
            'finance'          => 'Finance Engine',
            'agencies'         => 'Agencies',
            'settings'         => 'Settings',
            'billing'          => 'Billing',
            'roles'            => 'Role Manager',
            'data_scope'       => 'Data Scope',
            'sidebar'          => 'Sidebar',
            'assistants'       => 'Assistants',
        ];

        $sectionLabels = [
            'dashboard'              => 'Dashboard',
            'agency-tracker'         => 'Agency Tracker',
            'deals-v2'               => 'Deal Register V2',
            'compliance'             => 'Compliance & Supervision',
            'supervision'            => 'Compliance & Supervision',
            'training'               => 'Training & Communication',
            'communication'          => 'Training & Communication',
            'client-portal'          => 'Client Portal',
            'franchise-admin'        => 'Franchise Admin',
            'docuperfect'            => 'DocuPerfect',
            'document-library'       => 'Document Library',
            'presentations'          => 'Presentations',
            'filing-register'        => 'Filing Register',
            'commercial-evaluations' => 'Commercial Evaluations',
            'sales-documents'        => 'Sales Documents',
            'properties'             => 'Properties',
            'contacts'               => 'Contacts',
            'core-matches'           => 'Core Matches',
            'calculators'            => 'Tools',
            'ellie'                  => 'Tools',
            'prospecting'            => 'Tools',
            'evaluation'             => 'Tools',
            'pdf-splitter'           => 'Tools',
            'p24'                    => 'P24 Market Intel',
            'knowledge-base'         => 'Knowledge Base',
            'finance-engine'         => 'Finance Engine',
            'agencies'               => 'Agencies',
            'settings'               => 'Settings & Roles',
            'billing'                => 'Billing',
            'role-manager'           => 'Settings & Roles',
            'data-scope'             => 'Data Scope',
            'sidebar'                => 'Sidebar',
            'assistants'             => 'Assistants',
        ];

        $matrixSections = [];
        foreach ($permissions as $perm) {
            $displaySection = $sectionLabels[$perm->section] ?? Str::title(str_replace('-', ' ', $perm->section));
            $module = $perm->module ?? $perm->section;

            if (!isset($matrixSections[$displaySection])) {
                $matrixSections[$displaySection] = [];
            }
            if (!isset($matrixSections[$displaySection][$module])) {
                $matrixSections[$displaySection][$module] = [
                    'label'   => $moduleLabels[$module] ?? Str::title(str_replace('_', ' ', $module)),
                    'access'  => [],
                    'actions' => [],
                ];
            }

            if ($perm->type === 'action') {
                $matrixSections[$displaySection][$module]['actions'][] = $perm;
            } else {
                $matrixSections[$displaySection][$module]['access'][] = $perm;
            }
        }

        // Shared modules — scope radios not shown, always visible to all
        $sharedModules = ['p24', 'knowledge', 'calculators', 'ellie', 'pdf_splitter', 'prospecting', 'evaluation'];

        // Pre-compute JSON-safe values (no closures in Blade @json)
        $rolesJson = $roles->map(fn($r) => [
            'name'     => $r->name,
            'label'    => $r->label,
            'is_owner' => $r->is_owner,
            'color'    => $r->color,
        ]);

        $allPermKeys = $permissions->pluck('key');

        $moduleActionsMap = collect($matrixSections)->flatMap(function ($modules) {
            return collect($modules)->mapWithKeys(function ($data, $moduleKey) {
                $map = [];
                foreach ($data['actions'] as $ap) {
                    $parts = explode('.', $ap->key);
                    $suffix = end($parts);
                    $map[$suffix] = $ap->key;
                }
                return [$moduleKey => $map];
            });
        });

        return view('corex.role-manager', [
            'permissions'      => $permissions,
            'sections'         => $sections,
            'granted'          => $granted,
            'scopeGranted'     => $scopeGranted,
            'roles'            => $roles,
            'users'            => $users,
            'branches'         => $branches,
            'agencies'         => $agencies,
            'matrixSections'   => $matrixSections,
            'moduleLabels'     => $moduleLabels,
            'sharedModules'    => $sharedModules,
            'rolesJson'        => $rolesJson,
            'allPermKeys'      => $allPermKeys,
            'moduleActionsMap' => $moduleActionsMap,
        ]);
    }

    public function savePermissions(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        // Only this agency's own non-owner roles may be edited here.
        $editableRoles = Role::where('is_owner', false)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
            ->pluck('name');

        $request->validate([
            'role'          => ['required', Rule::in($editableRoles)],
            'permissions'   => 'nullable|array',
            'permissions.*' => 'string',
            'scopes'        => 'nullable|array',
            'scopes.*'      => 'string',
        ]);

        $role = $request->input('role');

        // Only accept permission keys that actually exist in the DB
        $validKeys = CoreXPermission::pluck('key')->flip();

        $matrix = $request->input('permissions', []);
        $scopes  = $request->input('scopes', []);

        // Branch isolation: `branches.edit_all` implies `branches.view_all`.
        // Enforced server-side so no request (even a crafted one) can land
        // edit-without-view and silently leak a cross-branch edit path.
        if (!empty($matrix['branches.edit_all']) && $matrix['branches.edit_all'] !== '0') {
            $matrix['branches.view_all'] = '1';
        }

        $rows    = [];
        $now     = now();

        foreach ($matrix as $permKey => $on) {
            if ($on && $on !== '0' && $validKeys->has($permKey)) {
                $scope = null;
                if (str_ends_with($permKey, '.view') && isset($scopes[$permKey])) {
                    $scopeVal = $scopes[$permKey];
                    if (in_array($scopeVal, ['own', 'branch', 'all'])) {
                        $scope = $scopeVal;
                    }
                }

                $rows[] = [
                    'role'           => $role,
                    'permission_key' => $permKey,
                    'scope'          => $scope,
                    'agency_id'      => $agencyId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ];
            }
        }

        // Wrap delete+insert in a transaction so permissions are never lost.
        // Scoped to this agency so editing one agency's role never touches another's.
        DB::transaction(function () use ($role, $rows, $agencyId) {
            RolePermission::where('role', $role)
                ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
                ->forceDelete();

            if (count($rows)) {
                // Insert in chunks to stay within DB limits
                foreach (array_chunk($rows, 500) as $chunk) {
                    RolePermission::insert($chunk);
                }
            }
        });

        PermissionService::clearCache();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'role'    => $role,
                'count'   => count($rows),
                'message' => 'Permissions saved.',
            ]);
        }

        return redirect()->route('corex.role-manager', ['role' => $role])->with('success', 'Permissions saved successfully.');
    }

    public function updateUserRole(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'role'    => ['required', Rule::in(Role::roleNames($agencyId))],
        ]);

        // Only owner-role users may assign the owner role
        $targetRole = Role::allRoles($agencyId)->firstWhere('name', $request->role);
        if ($targetRole && $targetRole->is_owner && !auth()->user()->isOwnerRole()) {
            abort(403, 'Only the System Owner can assign the owner role.');
        }

        $user = User::findOrFail($request->user_id);
        $user->role = $request->role;
        $user->save();

        PermissionService::clearCache();

        if ($request->expectsJson() || $request->ajax()) {
            $roleModel = Role::allRoles($agencyId)->firstWhere('name', $user->role);
            return response()->json([
                'success'    => true,
                'user_id'    => $user->id,
                'role'       => $user->role,
                'role_label' => $roleModel?->label ?? $user->role,
                'role_color' => $roleModel?->color ?? '#64748b',
                'message'    => "Role updated for {$user->name}.",
            ]);
        }

        return redirect()->route('corex.role-manager', ['role' => $request->role])->with('success', "Role updated for {$user->name}.");
    }

    /**
     * Copy all permissions (and scopes) from one role to one or more target roles.
     */
    public function copyPermissions(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $agencyRoles = fn ($q) => $q->when($agencyId, fn ($qq) => $qq->where('agency_id', $agencyId), fn ($qq) => $qq->whereNull('agency_id'));

        $nonOwnerRoles = Role::where('is_owner', false)->tap($agencyRoles)->pluck('name')->all();

        $request->validate([
            'source_role'   => ['required', Rule::in($nonOwnerRoles)],
            'target_roles'  => 'required|array|min:1',
            'target_roles.*'=> [Rule::in($nonOwnerRoles)],
        ]);

        $source  = $request->input('source_role');
        $targets = collect($request->input('target_roles'))->reject(fn($r) => $r === $source)->unique()->values();

        if ($targets->isEmpty()) {
            return redirect()->route('corex.role-manager', ['role' => $source])->withErrors(['target_roles' => 'Select at least one target role that is different from the source.']);
        }

        $sourcePerms = RolePermission::where('role', $source)
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
            ->get(['permission_key', 'scope']);

        $now   = now();
        $count = 0;

        DB::transaction(function () use ($targets, $sourcePerms, $now, $agencyId, &$count) {
            foreach ($targets as $target) {
                RolePermission::where('role', $target)
                    ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
                    ->forceDelete();

                $rows = $sourcePerms->map(fn($p) => [
                    'role'           => $target,
                    'permission_key' => $p->permission_key,
                    'scope'          => $p->scope,
                    'agency_id'      => $agencyId,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ])->all();

                if (count($rows)) {
                    foreach (array_chunk($rows, 500) as $chunk) {
                        RolePermission::insert($chunk);
                    }
                }

                $count++;
            }
        });

        PermissionService::clearCache();

        $targetLabels = Role::whereIn('name', $targets)->tap($agencyRoles)->pluck('label')->implode(', ');
        $sourceLabel  = Role::where('name', $source)->tap($agencyRoles)->value('label');

        return redirect()->route('corex.role-manager', ['role' => $source])->with('success', "Copied {$sourcePerms->count()} permissions from {$sourceLabel} to {$targetLabels}.");
    }

    // ── Role CRUD ──

    public function storeRole(Request $request)
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        $request->validate([
            'label'       => 'required|string|max:100',
            // Role names are unique WITHIN an agency, not globally — another
            // agency may legitimately have a role of the same name.
            'name'        => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('roles', 'name')->where(fn ($q) => $q->where('agency_id', $agencyId))],
            'description' => 'nullable|string|max:500',
            'color'       => 'nullable|string|max:20',
            'sort_order'  => 'nullable|integer',
        ]);

        $name = $request->name ?: Str::slug($request->label, '_');

        // Ensure uniqueness of auto-generated slug within this agency (incl. trashed).
        if (Role::withTrashed()->where('name', $name)->where('agency_id', $agencyId)->exists()) {
            return redirect()->route('corex.role-manager')->withErrors(['name' => "The role slug '{$name}' is already taken in this agency."]);
        }

        $role = Role::create([
            'name'        => $name,
            'label'       => $request->label,
            'description' => $request->description,
            'color'       => $request->color ?? '#0d9488',
            'sort_order'  => $request->sort_order ?? (Role::where('agency_id', $agencyId)->max('sort_order') + 1),
            'is_owner'    => false,
            'can_be_deleted' => true,
            'agency_id'   => $agencyId,
        ]);

        // Seed new role with this agency's agent permissions as a starting
        // point (fall back to the global template if the agency has none yet).
        $agentPerms = RolePermission::where('role', 'agent')
            ->when($agencyId, fn ($q) => $q->where('agency_id', $agencyId), fn ($q) => $q->whereNull('agency_id'))
            ->get();
        if ($agentPerms->isEmpty() && $agencyId) {
            $agentPerms = RolePermission::where('role', 'agent')->whereNull('agency_id')->get();
        }

        if ($agentPerms->isNotEmpty()) {
            $now  = now();
            $rows = $agentPerms->map(fn($p) => [
                'role'           => $role->name,
                'permission_key' => $p->permission_key,
                'scope'          => $p->scope,
                'agency_id'      => $agencyId,
                'created_at'     => $now,
                'updated_at'     => $now,
            ])->all();
            RolePermission::insert($rows);
        }

        Role::clearCache();
        PermissionService::clearCache();

        return redirect()->route('corex.role-manager', ['role' => $role->name])->with('success', "Role '{$role->label}' created with agent permissions as default.");
    }

    public function updateRole(Request $request, Role $role)
    {
        $this->authorizeAgencyRole($role);

        $request->validate([
            'label'           => 'required|string|max:100',
            'description'     => 'nullable|string|max:500',
            'color'           => 'nullable|string|max:20',
            'sort_order'      => 'nullable|integer',
            'oversight_scope' => 'nullable|in:branch,agency',
        ]);

        $fields = ['label', 'description', 'color', 'sort_order', 'oversight_scope'];
        $role->update($request->only($fields));

        Role::clearCache();

        return redirect()->route('corex.role-manager', ['role' => $role->name])->with('success', "Role '{$role->label}' updated.");
    }

    public function destroyRole(Request $request, Role $role)
    {
        $this->authorizeAgencyRole($role);

        if ($role->is_owner) {
            abort(403, 'The System Owner role cannot be deleted.');
        }

        if (!$role->can_be_deleted) {
            abort(403, 'This role cannot be deleted.');
        }

        $agencyId = auth()->user()?->effectiveAgencyId();

        // User queries are agency-scoped by the global AgencyScope, so this
        // counts/updates only THIS agency's users even though role names repeat.
        $activeUserCount = User::where('role', $role->name)->where('is_active', 1)->count();

        if ($activeUserCount > 0) {
            $request->validate([
                'reassign_to' => ['required', Rule::in(Role::roleNames($agencyId))],
            ]);

            $reassignRole = $request->reassign_to;

            if ($reassignRole === $role->name) {
                return redirect()->route('corex.role-manager', ['role' => $role->name])->withErrors(['reassign_to' => 'Cannot reassign users to the role being deleted.']);
            }

            User::where('role', $role->name)->update(['role' => $reassignRole]);
        }

        $role->delete(); // soft delete

        Role::clearCache();
        PermissionService::clearCache();

        return redirect()->route('corex.role-manager')->with('success', "Role '{$role->label}' deleted." . ($activeUserCount > 0 ? " {$activeUserCount} user(s) moved to '{$request->reassign_to}'." : ''));
    }

    /**
     * Guard: a Role bound by route-model binding must belong to the acting
     * agency. Roles are agency-scoped but the `Role` model has no global
     * AgencyScope, so the binder would otherwise resolve another agency's
     * (or a global template) role by id. Reject with 404.
     */
    protected function authorizeAgencyRole(Role $role): void
    {
        $agencyId = auth()->user()?->effectiveAgencyId();

        if ($role->agency_id !== $agencyId) {
            abort(404);
        }
    }
}

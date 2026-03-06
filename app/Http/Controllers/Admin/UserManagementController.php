<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function index()
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $users = User::orderBy('name')->get();
        $branches = Branch::orderBy('name')->get(['id','name']);
        $designations = DB::table('designations')
            ->where('is_enabled', 1)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id','name']);

        return view('admin.users.index', compact('users','branches','designations'));}

    public function updateDefaults(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $data = $request->validate([
            'agent_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'paye_method' => ['nullable', 'in:percentage,fixed'],
            'paye_value' => ['nullable', 'numeric', 'min:0'],

            // Sliding scale (per agent, optional)
            'sliding_enabled' => ['nullable', 'in:0,1'],
            'sliding_tier1_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier2_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sliding_tier3_cut_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // Normalize blanks
        $agentCut = $data['agent_cut_percent'] ?? null;
        $payeMethod = $data['paye_method'] ?? null;
        $payeValue = $data['paye_value'] ?? null;

        if ($payeMethod === null) {
            // If method blank, also blank value (keeps data consistent)
            $payeValue = null;
        }

        $slidingEnabled = isset($data['sliding_enabled']) && (string)$data['sliding_enabled'] === '1';

        $tier1 = $data['sliding_tier1_cut_percent'] ?? null;
        $tier2 = $data['sliding_tier2_cut_percent'] ?? null;
        $tier3 = $data['sliding_tier3_cut_percent'] ?? null;

        // If sliding enabled, tiers must be provided (no placeholders; concrete rule)
        if ($slidingEnabled) {
            if ($tier1 === null || $tier1 === '' || $tier2 === null || $tier2 === '' || $tier3 === null || $tier3 === '') {
                return back()->withErrors("Sliding is enabled for {$user->name}. Tier 1, Tier 2, and Tier 3 cut % are required.")
                    ->withInput();
            }
        } else {
            // If sliding disabled, keep tiers nullable (do not force blanks to 0)
            if ($tier1 === '') $tier1 = null;
            if ($tier2 === '') $tier2 = null;
            if ($tier3 === '') $tier3 = null;
        }

        $user->update([
            'agent_cut_percent' => ($agentCut === null || $agentCut === '') ? null : (float)$agentCut,
            'paye_method' => $payeMethod,
            'paye_value' => ($payeValue === null || $payeValue === '') ? null : (float)$payeValue,

            'sliding_enabled' => $slidingEnabled ? 1 : 0,
            'sliding_tier1_cut_percent' => ($tier1 === null || $tier1 === '') ? null : (float)$tier1,
            'sliding_tier2_cut_percent' => ($tier2 === null || $tier2 === '') ? null : (float)$tier2,
            'sliding_tier3_cut_percent' => ($tier3 === null || $tier3 === '') ? null : (float)$tier3,
        ]);

        return back()->with('status', "Defaults updated for {$user->name}.");
    }

    
    public function updateRole(Request $request, User $user)
    {
        // ---- ALSO SAVE DEFAULTS FROM SAME FORM ----
        $defaults = $request->validate([
            'agent_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'paye_method' => ['nullable','in:percentage,fixed'],
            'paye_value' => ['nullable','numeric','min:0'],
            'sliding_enabled' => ['nullable','in:0,1'],
            'sliding_tier1_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'sliding_tier2_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'sliding_tier3_cut_percent' => ['nullable','numeric','min:0','max:100'],
            'can_capture_rentals' => ['nullable','in:0,1'],
              'counts_for_branch_split' => ['nullable','in:0,1'],
        ]);

        $user->agent_cut_percent = $defaults['agent_cut_percent'] ?? $user->agent_cut_percent;
        $user->paye_method = $defaults['paye_method'] ?? $user->paye_method;
        $user->paye_value = $defaults['paye_value'] ?? $user->paye_value;
        $user->sliding_enabled = isset($defaults['sliding_enabled']) && $defaults['sliding_enabled'] == '1' ? 1 : 0;
        $user->sliding_tier1_cut_percent = $defaults['sliding_tier1_cut_percent'] ?? $user->sliding_tier1_cut_percent;
        $user->sliding_tier2_cut_percent = $defaults['sliding_tier2_cut_percent'] ?? $user->sliding_tier2_cut_percent;
        $user->sliding_tier3_cut_percent = $defaults['sliding_tier3_cut_percent'] ?? $user->sliding_tier3_cut_percent;
        $user->can_capture_rentals = isset($defaults['can_capture_rentals']) && $defaults['can_capture_rentals'] == '1' ? 1 : 0;

          $user->counts_for_branch_split = isset($defaults['counts_for_branch_split']) && $defaults['counts_for_branch_split'] == '1' ? 1 : 0;

        // ---- Contact fields ----
        $contact = $request->validate([
            'phone' => ['nullable','string','max:50'],
            'cell' => ['nullable','string','max:50'],
            'fax' => ['nullable','string','max:50'],
            'ffc_number' => ['nullable','string','max:100'],
            'website' => ['nullable','string','max:255'],
        ]);
        $user->phone = $contact['phone'] ?? null;
        $user->cell = $contact['cell'] ?? null;
        $user->fax = $contact['fax'] ?? null;
        $user->ffc_number = $contact['ffc_number'] ?? null;
        $user->website = $contact['website'] ?? null;

        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        // Safety: prevent editing your own role/branch by mistake
        if ($user->id === auth()->id()) {
            return back()->withErrors('For safety, you cannot change your own role/branch here.');
        }

        $data = $request->validate([
            'role' => ['required', Rule::in(Role::roleNames())],
            'designation' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ]);

        $role = (string)$data['role'];
        $branchId = $data['branch_id'] ?? null;

        if ($branchId !== null && (int)$branchId <= 0) {
            $branchId = null;
        }

        // If BM/Agent, a branch is required (predictable behavior)
        if ($role !== 'admin' && $branchId === null) {
            return back()->withErrors('Branch is required for Agent and Branch Manager.');
        }

        // Ensure branch exists if provided
        if ($branchId !== null) {
            $exists = \Illuminate\Support\Facades\DB::table('branches')->where('id', (int)$branchId)->exists();
            if (!$exists) return back()->withErrors('Selected branch does not exist.');
        }

        $user->role = $role;
        $user->is_admin = in_array($role, ['admin', 'super_admin']) ? 1 : 0;
        $user->branch_id = $branchId;

        // Designation (blank => NULL)
        $designation = trim((string)($data['designation'] ?? ''));
        $user->designation = ($designation !== '') ? $designation : null;

        $user->is_active = 1;
        if (!$user->email_verified_at) $user->email_verified_at = now();
        $user->save();

        // Keep branch_assignments in sync for any older logic that relies on it
        if ($branchId !== null) {
            \Illuminate\Support\Facades\DB::table('branch_assignments')
                ->updateOrInsert(
                    ['user_id' => $user->id],
                    ['branch_id' => (int)$branchId, 'updated_at' => now(), 'created_at' => now()]
                );
        }

        // ---- Agent file uploads ----
        $request->validate([
            'agent_photo'     => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'ffc_certificate' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ]);

        if ($request->hasFile('agent_photo')) {
            if ($user->agent_photo_path) {
                Storage::disk('public')->delete($user->agent_photo_path);
            }
            $ext = $request->file('agent_photo')->getClientOriginalExtension();
            $path = $request->file('agent_photo')->storeAs(
                "agents/{$user->id}", "photo.{$ext}", 'public'
            );
            $user->update(['agent_photo_path' => $path]);
        }

        if ($request->hasFile('ffc_certificate')) {
            if ($user->ffc_certificate_path) {
                Storage::disk('public')->delete($user->ffc_certificate_path);
            }
            $ext = $request->file('ffc_certificate')->getClientOriginalExtension();
            $path = $request->file('ffc_certificate')->storeAs(
                "agents/{$user->id}", "ffc.{$ext}", 'public'
            );
            $user->update(['ffc_certificate_path' => $path]);
        }

        return back()->with('status', "Updated role/branch for {$user->name}.");
    }

    /**
     * Remove an agent file (photo or FFC certificate).
     */
    public function removeAgentFile(Request $request, User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        $field = $request->input('field');

        if ($field === 'agent_photo' && $user->agent_photo_path) {
            Storage::disk('public')->delete($user->agent_photo_path);
            $user->update(['agent_photo_path' => null]);
            return back()->with('status', "Agent photo removed for {$user->name}.");
        }

        if ($field === 'ffc_certificate' && $user->ffc_certificate_path) {
            Storage::disk('public')->delete($user->ffc_certificate_path);
            $user->update(['ffc_certificate_path' => null]);
            return back()->with('status', "FFC certificate removed for {$user->name}.");
        }

        return back();
    }

    public function toggle(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->id === auth()->id()) {
            return back()->withErrors('You cannot deactivate yourself.');
        }

        $user->update([
            'is_active' => !$user->is_active
        ]);

        return back();
    }

    public function delete(User $user)
    {
        abort_unless(auth()->user()?->hasPermission('manage_users'), 403);

        if ($user->id === auth()->id()) {
            return back()->withErrors('You cannot delete yourself.');
        }

        $hasDeals = DB::table('deal_user')->where('user_id', $user->id)->exists();
        if ($hasDeals) return back()->withErrors('User has deals and cannot be deleted.');

        DB::table('branch_assignments')->where('user_id', $user->id)->delete();

        $user->update(['is_active' => false]);

        return back()->with('success', 'User deactivated.');
    }
}

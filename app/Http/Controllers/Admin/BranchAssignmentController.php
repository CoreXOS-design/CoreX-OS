<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Branch;
use App\Models\BranchSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class BranchAssignmentController extends Controller
{
    public function index()
    {
        $this->authorizeAdmin();

        $users = User::agencyMembers()->orderBy('name')->get();
        $branches = Branch::orderBy('name')->get();
        $assigned = DB::table('branch_assignments')->pluck('branch_id', 'user_id')->toArray();
        $branchesInUse = DB::table('branch_assignments')
            ->select('branch_id', DB::raw('count(*) as cnt'))
            ->groupBy('branch_id')
            ->pluck('cnt', 'branch_id')
            ->toArray();

        // Branch settings (key/value) for admin UI
        $branchSettingsByBranch = BranchSetting::query()
            ->get(['branch_id','key','value'])
            ->groupBy('branch_id')
            ->map(fn($rows) => $rows->pluck('value','key')->toArray())
            ->toArray();
        return view('admin.branch-assignments.index', compact('users', 'branches', 'assigned', 'branchesInUse', 'branchSettingsByBranch'));
    }

    public function update(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'branch_id' => ['nullable', 'exists:branches,id'],
        ]);

        DB::table('branch_assignments')->where('user_id', $data['user_id'])->delete();

        if (!empty($data['branch_id'])) {
            DB::table('branch_assignments')->insert([
                'user_id' => $data['user_id'],
                'branch_id' => $data['branch_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return redirect()->back();
    }

    public function createBranch(Request $request)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:255'],
            'code'      => ['required', 'string', 'max:50'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
        ]);

        // Owners may target any agency; non-owners are pinned to their own.
        $user = auth()->user();
        if (!empty($data['agency_id']) && $user && !$user->isOwnerRole()) {
            if ((int) $data['agency_id'] !== (int) $user->effectiveAgencyId()) {
                abort(403, 'You can only add branches to your own agency.');
            }
        }
        if (empty($data['agency_id'])) {
            unset($data['agency_id']);
        }

        Branch::create($data);

        return redirect()->back();
    }

    public function deleteBranch(Request $request, Branch $branch)
    {
        $this->authorizeAdmin();

        // Who is still attached to this branch?
        $assignedUserIds = User::where('branch_id', $branch->id)->pluck('id')->all();
        $legacyPivotUserIds = DB::table('branch_assignments')->where('branch_id', $branch->id)->pluck('user_id')->all();
        $allAttachedUserIds = array_unique(array_merge($assignedUserIds, $legacyPivotUserIds));

        // Spec §9: if any users are attached we require a reassignment map
        // (user_id => target_branch_id). Without it, refuse and let the UI
        // open the reassignment modal.
        if (!empty($allAttachedUserIds)) {
            $reassignments = $request->input('reassignments', []);

            if (empty($reassignments) || !is_array($reassignments)) {
                return back()->withErrors([
                    'branch' => 'This branch has ' . count($allAttachedUserIds) . ' user(s) assigned. Reassign them before archiving.',
                ])->withInput(['reassign_for_branch' => $branch->id]);
            }

            // Validate targets: each target branch must be in the same agency
            // and not the branch we're about to archive.
            $validTargetIds = Branch::where('agency_id', $branch->agency_id)
                ->where('id', '!=', $branch->id)
                ->pluck('id')
                ->flip();

            foreach ($reassignments as $userId => $targetBranchId) {
                if (!in_array((int) $userId, $allAttachedUserIds, true)) {
                    return back()->withErrors(['branch' => "User {$userId} is not assigned to this branch."]);
                }
                if (!$validTargetIds->has((int) $targetBranchId)) {
                    return back()->withErrors(['branch' => "Invalid target branch for user {$userId}."]);
                }
            }

            // All or nothing — every attached user must have a target
            $unaddressed = array_diff($allAttachedUserIds, array_keys($reassignments));
            if (!empty($unaddressed)) {
                return back()->withErrors(['branch' => 'All attached users must be reassigned before archiving.']);
            }

            DB::transaction(function () use ($reassignments, $branch) {
                foreach ($reassignments as $userId => $targetBranchId) {
                    User::where('id', (int) $userId)->update(['branch_id' => (int) $targetBranchId]);
                    DB::table('branch_assignments')
                        ->where('user_id', (int) $userId)
                        ->update(['branch_id' => (int) $targetBranchId, 'updated_at' => now()]);
                }
                $branch->delete();
            });

            return redirect()->route('admin.branch-assignments')->with('success', "Reassigned " . count($reassignments) . " user(s) and archived {$branch->name}.");
        }

        $branch->delete();
        return redirect()->route('admin.branch-assignments')->with('success', "Archived branch {$branch->name}.");
    }

    private function authorizeAdmin()
    {
        abort_unless(auth()->user()?->hasPermission('access_branch_assignments'), 403);
    }

    public function updateBranchSettings(Request $request, Branch $branch)
    {
        $this->authorizeAdmin();

        $data = $request->validate([
            'trading_name' => ['nullable', 'string', 'max:255'],
            'tagline'      => ['nullable', 'string', 'max:255'],
            'address'      => ['nullable', 'string', 'max:500'],
            'phone'            => ['nullable', 'string', 'max:255'],
            'phone_secondary'  => ['nullable', 'string', 'max:255'],
            'fax'              => ['nullable', 'string', 'max:255'],
            'email'        => ['nullable', 'string', 'max:255'],
            'reg_no'       => ['nullable', 'string', 'max:255'],
            'vat_no'       => ['nullable', 'string', 'max:255'],
            'ffc_no'       => ['nullable', 'string', 'max:255'],
            'ppra_number'  => ['nullable', 'string', 'max:32'],
            'fic_no'       => ['nullable', 'string', 'max:255'],
            // Phase 9c-3 rebuild — per-branch privacy policy override.
            'privacy_policy_markdown' => ['nullable', 'string', 'max:200000'],
            'privacy_policy_action'   => ['nullable', 'string', 'in:publish,unpublish'],
            'p24_agency_id' => ['nullable', 'string', 'max:32'],
            'logo'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1048576'],
            'remove_logo'  => ['nullable', 'boolean'],
        ]);

        // Empty-string → null so "blank means inherit from agency" semantics hold.
        if (array_key_exists('p24_agency_id', $data) && $data['p24_agency_id'] === '') {
            $data['p24_agency_id'] = null;
        }

        // Privacy policy override: same draft/publish gesture as agency level.
        // Token lazily generated and persists across edits.
        $privacyAction = $data['privacy_policy_action'] ?? null;
        unset($data['privacy_policy_action']);
        if (array_key_exists('privacy_policy_markdown', $data)) {
            $hasContent = !empty($data['privacy_policy_markdown']);
            if ($hasContent && empty($branch->privacy_policy_token)) {
                // Reuse agency's token generator so the unique check spans both
                // tables (privacy policy tokens are unique across all agencies
                // + all branches — controller resolves the route by querying
                // both tables).
                $data['privacy_policy_token'] = $branch->agency
                    ? $branch->agency->generatePrivacyPolicyToken()
                    : \Illuminate\Support\Str::random(48);
            }
            if (!$hasContent) {
                $data['privacy_policy_published_at'] = null;
            }
        }
        if ($privacyAction === 'publish') {
            $data['privacy_policy_published_at'] = $branch->privacy_policy_published_at ?: now();
        } elseif ($privacyAction === 'unpublish') {
            $data['privacy_policy_published_at'] = null;
        }

        $removeLogo = $data['remove_logo'] ?? false;
        unset($data['logo'], $data['remove_logo']);

        if ($removeLogo) {
            if ($branch->logo_path) {
                Storage::disk('public')->delete($branch->logo_path);
            }
            $data['logo_path'] = null;
        } elseif ($request->hasFile('logo')) {
            if ($branch->logo_path) {
                Storage::disk('public')->delete($branch->logo_path);
            }
            $ext = $request->file('logo')->getClientOriginalExtension();
            $path = $request->file('logo')->storeAs(
                "branches/{$branch->id}", "logo.{$ext}", 'public'
            );
            $data['logo_path'] = $path;
        }

        $branch->update($data);

        return redirect()->back()->with('success', 'Branch contact details updated.');
    }

    // ── Restore soft-deleted branch ──

    public function restoreBranch($id)
    {
        abort_unless(auth()->user()->hasPermission('manage_system'), 403);
        $record = Branch::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }
}

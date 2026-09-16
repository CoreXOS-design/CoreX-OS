<?php

namespace App\Http\Controllers\Admin;

use App\Events\Agent\AgentBranchAssigned;
use App\Events\Branch\BranchArchived;
use App\Events\Branch\BranchRestored;
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

        // Archive wizard + Archived branches panel
        // (spec: branch-archive-reassignment.md §6–§7, AT-420)
        $branchUsers      = Branch::attachedUsersGrouped($branches->pluck('id'));
        $archivedBranches = Branch::onlyTrashed()->orderByDesc('deleted_at')->get();
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
        return view('admin.branch-assignments.index', compact(
            'users', 'branches', 'assigned', 'branchesInUse', 'branchSettingsByBranch', 'branchUsers', 'archivedBranches'
        ));
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

        // This route is shared by THREE callers: the standalone Branch
        // Assignments admin page (no agency_id posted — its own hash-free
        // page, so a bare back() is fine), the Agency edit page's "branches"
        // tab (agency_id posted, no from_company_settings), and Company
        // Settings' own Branches tab (posts BOTH agency_id, so the branch
        // lands under the correct agency for an owner managing a different
        // one, AND from_company_settings for the redirect target — check
        // this FIRST, or it would fall through to the agency_id branch below
        // and redirect to the wrong page). The latter two's tab state lives
        // only in the URL fragment, which back() would silently drop,
        // bouncing the admin to the Company/Company-Settings-default tab.
        if ($request->filled('from_company_settings')) {
            return redirect()->route('admin.company-settings', ['agency' => $request->input('from_company_settings')])
                ->withFragment('branches');
        }
        if (!empty($data['agency_id'])) {
            return redirect()->route('agencies.edit', $data['agency_id'])->withFragment('branches');
        }

        return redirect()->back();
    }

    /**
     * Redirect back to wherever this branch action was submitted from.
     *
     * These branch-mutation routes are shared by THREE callers: the standalone
     * Branch Assignments admin page, the Company Settings page's own Branches
     * tab (resources/views/admin/company-settings/index.blade.php), and the
     * Agency edit page's "branches" tab. The latter two's tab state lives only
     * in a URL fragment that a plain back() would silently drop — so each
     * flags itself with a hidden field (from_agency_edit / from_company_settings).
     * The standalone page sends neither and keeps the original back()
     * behaviour, which is correct for it since it simply returns to wherever
     * the request came from.
     */
    private function branchContextRedirect(Request $request, Branch $branch)
    {
        if ($request->boolean('from_agency_edit')) {
            return redirect()->route('agencies.edit', $branch->agency_id)->withFragment('branches');
        }
        if ($request->filled('from_company_settings')) {
            return redirect()->route('admin.company-settings', ['agency' => $request->input('from_company_settings')])
                ->withFragment('branches');
        }

        return back();
    }

    /**
     * Archive a branch (soft delete), moving every attached person to another
     * active branch in the SAME transaction — all or nothing.
     * Spec: .ai/specs/branch-archive-reassignment.md §7 (AT-420).
     *
     * Business rule (Andre, 2026-09-15): deals carry on as loaded. Nothing
     * historical is re-attributed — every deal, property, contact, document,
     * target and activity keeps the branch stamped on it. The move only
     * changes where each person's FUTURE work lands, and it is dated
     * (user_branch_history, written by UserObserver on the Eloquent save) so
     * activity reports can still credit the old branch for the old days.
     */
    public function deleteBranch(Request $request, Branch $branch)
    {
        $this->authorizeAdmin();
        $actor = auth()->user();

        $attachedUserIds = $branch->attachedUserIds();

        // reassignments[user_id] = target_branch_id. A blank is "not chosen yet".
        $raw = $request->input('reassignments', []);
        $reassignments = collect(is_array($raw) ? $raw : [])
            ->mapWithKeys(fn ($target, $userId) => [(int) $userId => (int) $target])
            ->filter(fn ($target, $userId) => $userId > 0 && $target > 0);

        if (empty($attachedUserIds)) {
            $reassignments = collect();
        } else {
            $count  = count($attachedUserIds);
            $people = $count === 1 ? '1 person still works' : "{$count} people still work";

            // Only an ACTIVE branch in the same agency (never the one being archived)
            // may receive people.
            $validTargetIds = Branch::selectable()
                ->where('agency_id', $branch->agency_id)
                ->where('id', '!=', $branch->id)
                ->pluck('id')
                ->flip();

            if ($validTargetIds->isEmpty()) {
                return $this->branchContextRedirect($request, $branch)->withErrors([
                    'branch' => "{$branch->name} cannot be archived yet: {$people} from it and there is no other active branch to move them to. Add a branch first.",
                ]);
            }

            // All or nothing — every attached person must have a target.
            $unaddressed = array_diff($attachedUserIds, $reassignments->keys()->all());
            if (!empty($unaddressed)) {
                $n = count($unaddressed);
                return $this->branchContextRedirect($request, $branch)->withErrors([
                    'branch' => "Choose a new branch for everyone before archiving {$branch->name} — {$n} "
                        . ($n === 1 ? 'person still needs one.' : 'people still need one.'),
                ])->withInput(['reassign_for_branch' => $branch->id]);
            }

            foreach ($reassignments as $userId => $targetBranchId) {
                if (!in_array($userId, $attachedUserIds, true)) {
                    return $this->branchContextRedirect($request, $branch)->withErrors([
                        'branch' => "One of the people in the list no longer works from {$branch->name}. Reload the page and try again.",
                    ]);
                }
                if (!$validTargetIds->has($targetBranchId)) {
                    return $this->branchContextRedirect($request, $branch)->withErrors([
                        'branch' => 'One of the chosen branches is not an active branch in this agency. Reload the page and try again.',
                    ]);
                }
            }
        }

        try {
            $moved = DB::transaction(function () use ($branch, $reassignments, $actor) {
                $moved = [];

                foreach ($reassignments as $userId => $targetBranchId) {
                    $user = User::find($userId);
                    if (!$user) {
                        continue; // vanished between page load and submit — nothing to move
                    }
                    $target = Branch::selectable()->findOrFail($targetBranchId);
                    $from   = $user->branch_id ? (int) $user->branch_id : null;

                    // Eloquent save — NOT a query-builder update — so UserObserver
                    // writes the dated user_branch_history row (AT-366) that the
                    // Performance & ROI report attributes activity with.
                    $user->branch_id = $targetBranchId;
                    $user->save();

                    DB::table('branch_assignments')
                        ->where('user_id', $userId)
                        ->update(['branch_id' => $targetBranchId, 'updated_at' => now()]);

                    $this->retargetManagedBranches($userId, (int) $branch->id, $targetBranchId);

                    event(new AgentBranchAssigned($user, $target, $actor?->id, null, 'branch_archived', $from));
                    $moved[] = $userId;
                }

                // A principal who manages this branch from another home branch still
                // holds a managed-branch row for it — drop it and re-point their default.
                $remainingManagerIds = DB::table('user_managed_branches')
                    ->where('branch_id', $branch->id)
                    ->pluck('user_id');
                foreach ($remainingManagerIds as $managerId) {
                    $this->retargetManagedBranches((int) $managerId, (int) $branch->id, null);
                }

                $branch->delete();
                event(new BranchArchived($branch, $actor?->id, $moved));

                return $moved;
            });
        } catch (\Throwable $e) {
            report($e);
            return $this->branchContextRedirect($request, $branch)->withErrors([
                'branch' => "{$branch->name} was not archived — nothing changed. Please try again; if it keeps failing, contact support.",
            ]);
        }

        $n = count($moved);
        $message = $n === 0
            ? "{$branch->name} archived."
            : "{$branch->name} archived. {$n} " . ($n === 1 ? 'person moved.' : 'people moved.');

        return $this->branchContextRedirect($request, $branch)->with('success', $message);
    }

    /**
     * Drop the archived branch from a user's managed-branch set. If it was their
     * login default, the default becomes $preferredBranchId when they manage it,
     * else the first remaining (active) managed branch, else none.
     * Spec: branch-archive-reassignment.md §7.3.
     */
    private function retargetManagedBranches(int $userId, int $archivedBranchId, ?int $preferredBranchId): void
    {
        $wasDefault = DB::table('user_managed_branches')
            ->where('user_id', $userId)
            ->where('branch_id', $archivedBranchId)
            ->where('is_default', true)
            ->exists();

        DB::table('user_managed_branches')
            ->where('user_id', $userId)
            ->where('branch_id', $archivedBranchId)
            ->delete();

        if (!$wasDefault) {
            return;
        }

        $remaining = DB::table('user_managed_branches')
            ->join('branches', 'branches.id', '=', 'user_managed_branches.branch_id')
            ->whereNull('branches.deleted_at')
            ->where('user_managed_branches.user_id', $userId)
            ->orderBy('branches.name')
            ->pluck('user_managed_branches.branch_id')
            ->map(fn ($id) => (int) $id);

        if ($remaining->isEmpty()) {
            return;
        }

        $newDefault = ($preferredBranchId && $remaining->contains($preferredBranchId))
            ? $preferredBranchId
            : $remaining->first();

        DB::table('user_managed_branches')->where('user_id', $userId)->update(['is_default' => false]);
        DB::table('user_managed_branches')
            ->where('user_id', $userId)
            ->where('branch_id', $newDefault)
            ->update(['is_default' => true, 'updated_at' => now()]);
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
            'logo'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
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

        return $this->branchContextRedirect($request, $branch)->with('success', 'Branch contact details updated.');
    }

    // ── Restore an archived branch (spec: branch-archive-reassignment.md §7.5, AT-420) ──

    /**
     * Restore returns the branch to active with all its historical records.
     * No one is moved: the people the archive wizard relocated stay where they
     * are now — move them back from Branch Assignments if needed.
     */
    public function restoreBranch(Request $request, $id)
    {
        $this->authorizeAdmin();

        $branch = Branch::onlyTrashed()->findOrFail($id);
        $branch->restore();
        event(new BranchRestored($branch, auth()->id()));

        return $this->branchContextRedirect($request, $branch)->with(
            'success',
            "{$branch->name} restored. Agents stay where they are now — move them back from Branch Assignments if needed."
        );
    }
}

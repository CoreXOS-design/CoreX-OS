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
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50'],
        ]);

        Branch::create($data);

        return redirect()->back();
    }

    public function deleteBranch(Branch $branch)
    {
        $this->authorizeAdmin();

        $inUse = DB::table('branch_assignments')->where('branch_id', $branch->id)->exists();

        if ($inUse) {
            return back()->withErrors('Cannot delete branch: users are still assigned.');
        }

        $branch->delete();

        return redirect()->back();
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
            'fic_no'       => ['nullable', 'string', 'max:255'],
            'logo'         => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo'  => ['nullable', 'boolean'],
        ]);

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

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingSnapshot;
use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ListingSnapshotController extends Controller
{
    private function isAdmin(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        $role = strtolower(trim((string)($u->role ?? '')));
        return ($role === 'admin') || (bool)($u->is_admin ?? 0) === true;
    }

    private function isBranchManager(): bool
    {
        $u = auth()->user();
        if (!$u) return false;
        $role = strtolower(trim((string)($u->role ?? '')));
        return ($role === 'branch_manager');
    }

    public function index(Request $request)
    {
        abort_unless($this->isAdmin() || $this->isBranchManager(), 403);

        $auth = auth()->user();
        abort_unless((int)($auth->is_active ?? 0) === 1, 403);

        $period = (string)($request->get('period') ?: Carbon::now()->format('Y-m'));
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) $period = Carbon::now()->format('Y-m');

        $branchId = (int)($request->get('branch_id') ?: 0);
        if ($this->isBranchManager()) {
            $branchId = (int)($auth->branch_id ?? 0);
        }

        $branchNames = Branch::query()->pluck('name','id')->all();

        $users = User::query()
            ->where('role','agent')
            ->when($branchId > 0, fn($q)=>$q->where('branch_id',$branchId))
            ->orderBy('name')
            ->get();

        $snap = ListingSnapshot::query()
            ->where('period',$period)
            ->whereIn('user_id', $users->pluck('id'))
            ->get()
            ->keyBy('user_id');

        return view('admin.listings.snapshot', [
            'period' => $period,
            'branchId' => $branchId,
            'branchNames' => $branchNames,
            'users' => $users,
            'snapshots' => $snap,
            'isAdmin' => $this->isAdmin(),
            'isBM' => $this->isBranchManager(),
        ]);
    }

    public function save(Request $request)
    {
        abort_unless($this->isAdmin() || $this->isBranchManager(), 403);

        $auth = auth()->user();
        abort_unless((int)($auth->is_active ?? 0) === 1, 403);

        $period = (string)$request->input('period');
        if (!preg_match('/^\d{4}\-\d{2}$/', $period)) {
            return back()->withErrors('Invalid period.')->withInput();
        }

        $rows = $request->input('rows', []);
        if (!is_array($rows)) $rows = [];

        DB::transaction(function () use ($rows, $period, $auth) {
            foreach ($rows as $userId => $r) {
                $userId = (int)$userId;
                if ($userId <= 0) continue;
                if (!is_array($r)) $r = [];

                $u = User::find($userId);
                if (!$u) continue;

                // BM can only save their own branch
                $role = strtolower(trim((string)($auth->role ?? '')));
                if ($role === 'branch_manager') {
                    if ((int)($u->branch_id ?? 0) !== (int)($auth->branch_id ?? 0)) continue;
                }

                $count = isset($r['listing_count']) ? (int)$r['listing_count'] : 0;
                if ($count < 0) $count = 0;

                $avg = isset($r['avg_listing_price']) ? (float)$r['avg_listing_price'] : 0;
                if ($avg < 0) $avg = 0;

                ListingSnapshot::updateOrCreate(
                    ['period'=>$period,'user_id'=>$userId],
                    [
                        'branch_id' => $u->branch_id,
                        'listing_count' => $count,
                        'avg_listing_price' => $avg,
                        'updated_by' => $auth->id,
                    ] + (ListingSnapshot::where('period',$period)->where('user_id',$userId)->exists() ? [] : ['created_by'=>$auth->id])
                );
            }
        });

        return back()->with('status', 'Listing snapshot saved.');
    }
}

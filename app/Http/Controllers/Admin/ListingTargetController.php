<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ListingTarget;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ListingTargetController extends Controller
{
    public function index(Request $request)
    {
        $period = $request->query('period', now()->format('Y-m'));

        $viewer = Auth::user();

        // Base: staff we can target (exclude admins)
        $query = User::whereIn('role', ['agent', 'branch_manager']);

        // Branch Manager: only their branch
        if ($viewer->isEffectiveBranchManager()) {
            $query->where('branch_id', $viewer->branch_id);
        }

        $agents = $query->orderBy('email')->get();

        $targets = ListingTarget::where('period', $period)
            ->get()
            ->keyBy('user_id');

        return view('admin.listing-targets', [
            'period' => $period,
            'agents' => $agents,
            'targets' => $targets,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'string', 'max:7', 'regex:/^\d{4}-\d{2}$/'],
            'targets' => ['required', 'array'],
            'targets.*' => ['nullable', 'integer', 'min:0'],
        ]);

        $period = $data['period'];
        $viewer = Auth::user();

        // Determine which user IDs the viewer is allowed to edit
        $allowedUserIdsQuery = User::whereIn('role', ['agent', 'branch_manager']);

        if ($viewer->isEffectiveBranchManager()) {
            $allowedUserIdsQuery->where('branch_id', $viewer->branch_id);
        }

        $allowedUserIds = $allowedUserIdsQuery->pluck('id')->map(fn ($id) => (string)$id)->all();
        $allowedLookup = array_flip($allowedUserIds);

        foreach ($data['targets'] as $userId => $targetListings) {
            // Only save for allowed users
            if (!isset($allowedLookup[(string)$userId])) {
                continue;
            }

            ListingTarget::updateOrCreate(
                ['user_id' => (int) $userId, 'period' => $period],
                ['target_listings' => (int) ($targetListings ?? 0)]
            );
        }

        return redirect()
            ->route('admin.listing-targets', ['period' => $period])
            ->with('status', 'Targets saved for ' . $period);
    }
}

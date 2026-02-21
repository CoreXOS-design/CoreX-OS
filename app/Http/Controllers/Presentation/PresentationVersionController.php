<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\PresentationVersion;
use Illuminate\Http\Request;

/**
 * Presentation version history (P17).
 *
 * index() — admin / branch_manager: all versions with filters
 * mine()  — agent: own compiled versions only
 */
class PresentationVersionController extends Controller
{
    /**
     * Admin / BM: list compiled versions with optional filters.
     * Admin sees all; BM is restricted to their branch's presentations.
     */
    public function index(Request $request)
    {
        $isAdmin = auth()->user()->isEffectiveAdmin();

        $query = PresentationVersion::with(['presentation', 'compiledBy'])
            ->orderBy('compiled_at', 'desc');

        // Branch scope for non-admins (branch managers)
        if (!$isAdmin) {
            $branchId = auth()->user()->effectiveBranchId();
            $query->whereHas('presentation', fn ($q) => $q->where('branch_id', $branchId));
        }

        // Filters (admin only: branch_id, user_id)
        if ($isAdmin && $branchFilter = $request->integer('branch_id')) {
            $query->whereHas('presentation', fn ($q) => $q->where('branch_id', $branchFilter));
        }

        if ($isAdmin && $userFilter = $request->integer('user_id')) {
            $query->where('compiled_by', $userFilter);
        }

        if ($presentationFilter = $request->integer('presentation_id')) {
            $query->where('presentation_id', $presentationFilter);
        }

        if ($period = $request->string('period')) {
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                [$year, $month] = explode('-', (string) $period);
                $query->whereYear('compiled_at', $year)->whereMonth('compiled_at', $month);
            }
        }

        $versions = $query->paginate(25)->withQueryString();
        $branches = $isAdmin ? Branch::orderBy('name')->get() : collect();

        return view('presentations.versions.index', [
            'versions'  => $versions,
            'branches'  => $branches,
            'isAdmin'   => $isAdmin,
            'filters'   => $request->only(['branch_id', 'user_id', 'presentation_id', 'period']),
            'pageTitle' => 'Compiled Pack Versions',
        ]);
    }

    /**
     * Agent: list only their own compiled versions.
     */
    public function mine(Request $request)
    {
        $query = PresentationVersion::with(['presentation', 'compiledBy'])
            ->where('compiled_by', auth()->id())
            ->orderBy('compiled_at', 'desc');

        if ($presentationFilter = $request->integer('presentation_id')) {
            $query->where('presentation_id', $presentationFilter);
        }

        if ($period = $request->string('period')) {
            if (preg_match('/^\d{4}-\d{2}$/', $period)) {
                [$year, $month] = explode('-', (string) $period);
                $query->whereYear('compiled_at', $year)->whereMonth('compiled_at', $month);
            }
        }

        $versions = $query->paginate(25)->withQueryString();

        return view('presentations.versions.index', [
            'versions'  => $versions,
            'branches'  => collect(),
            'isAdmin'   => false,
            'filters'   => $request->only(['presentation_id', 'period']),
            'pageTitle' => 'My Compiled Versions',
        ]);
    }
}

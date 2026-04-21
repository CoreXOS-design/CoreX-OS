<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Deal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Phase-2 branch-isolation: cross-branch deal register per spec §11.
 *
 * Endpoints let a deal's writer attach / detach additional branches so
 * they also see the deal in their Deal Register via the deal_branches
 * pivot. The originator row (matching deals.branch_id) is protected —
 * it mirrors the direct column and moves only when the deal's branch_id
 * is reassigned.
 */
class DealBranchController extends Controller
{
    public function attach(Request $request, Deal $deal)
    {
        $this->authorize('writeDeal', $deal);

        $data = $request->validate([
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ]);

        $branch = Branch::findOrFail($data['branch_id']);

        // Guardrail: co-branch must belong to the same agency as the deal
        if ((int) $branch->agency_id !== (int) $deal->agency_id) {
            return back()->with('error', 'Branch is not in the same agency as the deal.');
        }

        // Never re-attach the originator row as a co-branch
        if ((int) $branch->id === (int) $deal->branch_id) {
            return back()->with('status', 'That branch is already the deal originator.');
        }

        $deal->attachCoBranch($branch->id);

        return back()->with('success', "Attached {$branch->name} as co-branch.");
    }

    public function detach(Request $request, Deal $deal, Branch $branch)
    {
        $this->authorize('writeDeal', $deal);

        if ((int) $branch->id === (int) $deal->branch_id) {
            return back()->with('error', 'Cannot detach the originator branch. Reassign the deal\'s primary branch first.');
        }

        $deal->detachCoBranch($branch->id);

        return back()->with('success', "Detached {$branch->name} from this deal.");
    }

    /**
     * Lightweight authorisation: user must be allowed to edit the deal
     * (we lean on existing permission gates; if your app uses a Policy
     * class for Deal later, swap this for Gate::authorize).
     */
    private function authorize(string $ability, Deal $deal): void
    {
        $user = Auth::user();
        if (!$user) {
            abort(403);
        }

        // Must be in the deal's agency AND have create_deals or settle_deals
        $inAgency = (int) $user->effectiveAgencyId() === (int) $deal->agency_id
            || $user->isOwnerRole();

        if (!$inAgency) {
            abort(403, 'This deal does not belong to your agency.');
        }

        if (!$user->hasAnyPermission(['create_deals', 'settle_deals', 'deals.edit'])) {
            abort(403, 'You do not have permission to modify deals.');
        }
    }
}

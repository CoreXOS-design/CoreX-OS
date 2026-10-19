<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\Lease;
use App\Models\RentalInspection;
use App\Services\Rentals\RentalDepositDeductionsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Audit 2026-10-19 B1 / B3 / B4 - the lease's "Deposit deductions" summary: what the tenancy's Out inspection recorded as charged to the
 * tenant, with amounts, and the way on to the comparison. Johan's ruling: the money stays in the agency's accounting system - CoreX only
 * records. Read-only apart from "Mark reviewed", which clears the lease hub's next step.
 *
 * Scoping: the bound lease through the shared guard (own / branch / agency) - the Out and every finding are read from that lease, never from
 * a posted id.
 */
class LeaseDepositDeductionsController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalDepositDeductionsService $deductions)
    {
    }

    public function show(Request $request, Lease $lease): View
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);
        $lease->loadMissing('property', 'tenants.contact');

        $summary = $this->deductions->summaryFor($lease);
        abort_if($summary === null, 404, 'This tenancy has no move-out inspection yet.');

        return view('corex.leases.deposit-deductions', ['lease' => $lease, 'summary' => $summary]);
    }

    public function markReviewed(Request $request, Lease $lease): RedirectResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $out = $this->deductions->outFor($lease);
        abort_if($out === null || $out->status !== RentalInspection::STATUS_COMPLETED, 409, 'The move-out inspection has to be completed before its deductions are reviewed.');
        $this->deductions->markReviewed($out, $request->user());

        return redirect()->route('corex.leases.deposit-deductions', $lease)->with('success', 'Deposit deductions marked as reviewed.');
    }
}

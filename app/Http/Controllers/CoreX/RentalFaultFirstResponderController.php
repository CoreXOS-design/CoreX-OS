<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalFaultReport;
use App\Services\Rentals\FirstResponderDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rentals-faults-work-orders.md section 21.3 - the two buttons in the fault page's "First to look at this" block. Both land here and go through the
 * ONE seam {@see FirstResponderDispatcher}; this controller decides nothing about what is sent (the fault flow does) and never changes status or approval.
 */
class RentalFaultFirstResponderController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __invoke(Request $request, RentalFaultReport $rentalFaultReport, FirstResponderDispatcher $dispatcher): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        $data = $request->validate([
            'choice' => ['required', 'in:' . FirstResponderDispatcher::CHOICE_INSPECT . ',' . FirstResponderDispatcher::CHOICE_OWNER_AUTHORISE],
            'supplier_id' => ['required', 'integer'],
        ]);

        // only a supplier that really is a first responder for THIS fault can be chosen (never an id typed into the request)
        $responder = $rentalFaultReport->property
            ? $rentalFaultReport->property->firstRespondersFor($rentalFaultReport->rental_fault_type_id)->firstWhere('supplier_id', (int) $data['supplier_id'])
            : null;
        abort_if($responder === null, 422, 'That is not one of the people set up to look at this fault first.');

        $result = $dispatcher->send($rentalFaultReport, $responder, $request->user(), $data['choice']);

        return redirect()->route('corex.rental-fault-reports.show', $rentalFaultReport)->with('success', $result['message']);
    }
}

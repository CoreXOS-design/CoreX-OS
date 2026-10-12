<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalFaultReport;
use App\Models\RentalRecordedOwnerDecision;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalOwnerDecisionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rentals-faults-work-orders.md §20 (AT-452 round 3) - "Record the owner's decision" (authority C) on the fault page and the work
 * order page, and "This is an emergency" (authority A's emergency limit) on the work order page. The rules live in RentalOwnerDecisionService;
 * this only checks the request, the agency scope and shows plain messages.
 */
class RentalOwnerDecisionController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function storeForFault(Request $request, RentalOwnerDecisionService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);

        return $this->store($request, $service, $rentalFaultReport, route('corex.rental-fault-reports.show', $rentalFaultReport));
    }

    public function storeForWorkOrder(Request $request, RentalOwnerDecisionService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        return $this->store($request, $service, $rentalWorkOrder, route('corex.rental-work-orders.show', $rentalWorkOrder));
    }

    private function store(Request $request, RentalOwnerDecisionService $service, RentalFaultReport|RentalWorkOrder $subject, string $back): RedirectResponse
    {
        $validated = $request->validate([
            'owner_contact_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in($service->optionsFor($subject))],
            'approved_up_to' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'told_via' => ['required', Rule::in(RentalRecordedOwnerDecision::VIAS)],
            'told_at' => ['nullable', 'date'],
            'note' => ['required', 'string', 'max:4000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:20480'],
            'handled_by' => ['nullable', Rule::in(['own', 'list', 'agency'])],
            'contractor_name' => ['nullable', 'string', 'max:191'],
            'contractor_phone' => ['nullable', 'string', 'max:40'],
            'agency_service_provider_id' => ['nullable', 'integer'],
        ], [
            'owner_contact_id.required' => 'Pick which owner told you.',
            'decision.required' => 'Choose what the owner decided.',
            'told_via.required' => 'Say how the owner told you: email, WhatsApp or phone call.',
            'note.required' => 'Write down what the owner said - it is required.',
            'proof.mimes' => 'The proof must be a picture (a screenshot of the WhatsApp or email).',
        ]);

        try {
            $service->record($request->user(), $subject, $validated, $request->file('proof'));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect($back)->withErrors(['owner_decision' => $e->getMessage()])->withInput();
        }

        return redirect($back)->with('success', "The owner's decision is recorded. The owner has been emailed what you wrote down.");
    }

    public function declareEmergency(Request $request, RentalOwnerDecisionService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        $validated = $request->validate(['emergency_reason' => ['required', 'string', 'max:2000']], ['emergency_reason.required' => 'Say why this is an emergency - the reason is required.']);

        try {
            $service->declareEmergency($rentalWorkOrder, $request->user(), $validated['emergency_reason']);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->withErrors(['owner_decision' => $e->getMessage()])->withInput();
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Marked as an emergency. The emergency limit now applies to this job.');
    }
}

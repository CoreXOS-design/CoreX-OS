<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalAuthorisation;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalAuthorisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.6 - Decision 2 on the work order: "Authorise R___ within the repair limit" (or the emergency limit) and
 * "Record the owner's decision". The rules live in RentalAuthorisationService; this only checks the request and the agency scope and answers in plain words.
 */
class RentalWorkOrderCostController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function authorise(Request $request, RentalAuthorisationService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        $v = $request->validate([
            'typed_amount' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if (! empty($v['emergency']) && ! $request->user()->hasPermission('rental_work_orders.record_emergency_approval')) {
            return back()->withErrors(['cost_decision' => 'You do not have permission to authorise an emergency.']);
        }

        try {
            $row = $service->authoriseCost($rentalWorkOrder, $request->user(), $v);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['cost_decision' => $e->getMessage()])->withInput();
        }

        return back()
            ->with('success', 'Cost authorised up to R' . number_format((float) $row->amount_up_to, 2) . '. The owner has been told.');
    }

    public function recordOwnerDecision(Request $request, RentalAuthorisationService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        $v = $request->validate([
            'owner_contact_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['approved', 'declined'])],
            'approved_up_to' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'told_via' => ['required', Rule::in(RentalAuthorisation::VIAS)],
            'told_at' => ['nullable', 'date'],
            'note' => ['required', 'string', 'max:4000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ], [
            'owner_contact_id.required' => 'Pick which owner told you.',
            'decision.required' => 'Choose what the owner decided.',
            'told_via.required' => 'Say how the owner told you: phone call, WhatsApp or email.',
            'note.required' => 'Write down what the owner said - it is required.',
            'proof.mimes' => 'The proof must be a picture (a screenshot of the WhatsApp or email).',
        ]);

        try {
            $service->recordOwnerCostDecision($request->user(), $rentalWorkOrder, $v, $request->file('proof'));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['cost_owner_decision' => $e->getMessage()])->withInput();
        }

        return back()->with('success', "The owner's decision is recorded. The owner has been emailed what you wrote down.");
    }

    /** Section 28.3 - "Use it": the owner approved the cost on the same call as the go-ahead; a quote at or below that figure is in. One press, the same words. */
    public function useSameCall(Request $request, RentalAuthorisationService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        try {
            $row = $service->useSameCallApproval($request->user(), $rentalWorkOrder);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['cost_decision' => $e->getMessage()]);
        }

        return back()->with('success', 'Cost authorised up to R' . number_format((float) $row->amount_up_to, 2) . ' on what the owner said on the call. The owner has been emailed.');
    }

    /** Section 31.4 - "Owner authorised by phone/WhatsApp": one click, the agent's own record of the owner's yes (who, when, how), the owner is told and sees it on their link. */
    public function ownerAuthorised(Request $request, RentalAuthorisationService $service, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        try {
            $row = $service->recordOwnerAuthorisedQuick($request->user(), $rentalWorkOrder);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['cost_decision' => $e->getMessage()]);
        }

        return back()->with('success', 'Owner authorised up to R' . number_format((float) $row->amount_up_to, 2) . ' by phone/WhatsApp. It is recorded under your name and the owner has been told.');
    }

    /** Section 31.4 - "Send to owner for approval": the selected quote goes to the owner (again) for their decision on their link. */
    public function sendToOwner(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        $quote = $rentalWorkOrder->quotes()->where('is_selected', true)->whereNull('declined_at')->first();
        if (! $quote) {
            return back()->withErrors(['cost_decision' => 'Choose a quote first - there is nothing to send the owner yet.']);
        }
        if ($rentalWorkOrder->owner_approval_status !== RentalWorkOrder::APPROVAL_PENDING) {
            return back()->withErrors(['cost_decision' => 'This quote does not need the owner: it is inside the owner\'s repair limit or already decided.']);
        }
        $sent = app(\App\Services\Rentals\RentalWorkOrderService::class)->sendOwnerQuote($rentalWorkOrder, $quote, $request->user(), needsDecision: true);
        if ($sent === 0) {
            return back()->withErrors(['cost_decision' => 'No owner has an email address on file, so nothing could be sent. Phone them, then press "Owner authorised by phone/WhatsApp".']);
        }

        return back()->with('success', 'Sent to the owner for approval.');
    }
}

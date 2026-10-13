<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalEmergencyApproval;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalApprovalGateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-work-orders.md §17.8 - emergency agreements recorded BEFORE rentals-faults-work-orders.md section 22.6. New emergencies are authorised in
 * "Approve the cost" (RentalWorkOrderCostController); the old record form is gone. What stays: void with a reason (a mistake is voided, never edited or deleted)
 * and download the attachment (private disk, scoped like the work order itself).
 */
class RentalEmergencyApprovalController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function void(Request $request, RentalApprovalGateService $gate, RentalWorkOrder $rentalWorkOrder, RentalEmergencyApproval $approval): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($approval->rental_work_order_id === $rentalWorkOrder->id, 404);

        $validated = $request->validate(['void_reason' => ['required', 'string', 'max:1000']], [
            'void_reason.required' => 'Say why the emergency approval is being voided.',
        ]);

        try {
            $warning = $gate->voidEmergency($approval, $validated['void_reason'], $request->user());
        } catch (\InvalidArgumentException|\LogicException $e) {
            return back()->withErrors(['emergency' => $e->getMessage()]);
        }

        $redirect = redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Emergency approval voided.');

        return $warning ? $redirect->with('warning', $warning) : $redirect;
    }

    /** Private disk, gated — re-checks the approval genuinely belongs to THIS work order on every request. */
    public function attachment(Request $request, RentalWorkOrder $rentalWorkOrder, RentalEmergencyApproval $approval)
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($approval->rental_work_order_id === $rentalWorkOrder->id, 404);
        abort_unless($approval->attachment_path && Storage::disk('local')->exists($approval->attachment_path), 404);

        return Storage::disk('local')->download($approval->attachment_path);
    }
}

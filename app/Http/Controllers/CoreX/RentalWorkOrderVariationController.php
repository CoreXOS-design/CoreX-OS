<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalApproval;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Services\Rentals\RentalApprovalGateService;
use App\Services\Rentals\RentalWorkOrderService;
use App\Services\Rentals\StaleVariationRevision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.7.4 — the office side of a variation (extra work beyond the owner's agreed terms):
 * "Resend request" to the owner, and "Record variation decision" when the owner replied by WhatsApp / email / phone rather than
 * in the portal. Both routes end in RentalApprovalGateService::recordVariationDecision(), the same writer the portal uses.
 */
class RentalWorkOrderVariationController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function resend(Request $request, RentalWorkOrderService $service, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderVariation $variation): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($variation->rental_work_order_id === $rentalWorkOrder->id, 404);

        if (! $variation->isAwaitingOwner()) {
            return back()->withErrors(['variation' => 'This request is not waiting for the owner any more.']);
        }

        $sent = $service->sendOwnerVariation($variation, $request->user());

        return back()->with($sent > 0 ? 'success' : 'warning', $sent > 0
            ? 'The request was sent to the owner again.'
            : 'The owner has no email address on file, so nothing was sent — record their answer here once they reply, or ask them to use the portal.');
    }

    public function decide(Request $request, RentalApprovalGateService $gate, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderVariation $variation): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($variation->rental_work_order_id === $rentalWorkOrder->id, 404);

        $validated = $request->validate([
            'decision' => ['required', 'in:approve,decline'],
            'evidence_type' => ['required', 'in:' . implode(',', [RentalApproval::EVIDENCE_WHATSAPP, RentalApproval::EVIDENCE_EMAIL, RentalApproval::EVIDENCE_VERBAL_NOTE])],
            'evidence_text' => ['required', 'string', 'max:2000'],
            'decided_at' => ['nullable', 'date'],
            'revision' => ['nullable', 'integer'],
        ], [
            'evidence_text.required' => 'Write down what the owner said.',
        ]);

        try {
            $gate->recordVariationDecision($variation, $validated['decision'], [
                'via' => RentalWorkOrderVariation::VIA_AGENT_CAPTURE,
                'evidence_type' => $validated['evidence_type'],
                'evidence_text' => $validated['evidence_text'],
                'decided_at' => $validated['decided_at'] ?? null,
                'revision' => $validated['revision'] ?? null,
                'note' => $validated['evidence_text'],
            ], ['user' => $request->user()]);
        } catch (StaleVariationRevision $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return back()->withErrors(['variation' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)
            ->with('success', $validated['decision'] === 'approve' ? 'The owner\'s approval of the extra work was recorded.' : 'The owner\'s decline of the extra work was recorded.');
    }
}

<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\EnforcesRecordVisibility;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-work-orders.md §3.4c — full CRUD + archive/restore on
 * quotes, per BUILD_STANDARD §1a. No sidebar entry — reachable from the
 * work order's own show screen, same as photos/updates/approvals.
 */
class RentalWorkOrderQuoteController extends Controller
{
    use EnforcesRecordVisibility;

    /**
     * Johan: "an upload or attach of the quote, or punching in the
     * details is whats needed" — neither path mandatory over the other,
     * but at least one is required (never let a bare amount+date reach
     * the DB with no evidence at all).
     */
    public function store(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $validated = $request->validate([
            'agency_service_provider_id' => ['required', \App\Models\DealV2\AgencyServiceProvider::maintenanceExistsRule((int) $request->user()->effectiveAgencyId())],
            'amount' => ['required', 'numeric', 'min:0'],
            'quote_date' => ['required', 'date'],
            'detail_text' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
            'is_selected' => ['nullable', 'boolean'],
        ]);

        if (empty($validated['detail_text']) && !$request->hasFile('document')) {
            return back()->withErrors(['quote' => 'Attach the quote document, or enter its details — at least one is required.'])->withInput();
        }

        if ($request->hasFile('document')) {
            $validated['document_storage_path'] = $request->file('document')
                ->store("rental-work-order-quotes/{$rentalWorkOrder->id}", 'local');
        }
        unset($validated['document']);
        // Johan, 9 Oct 2026 (WO 65: a R4,120 quote was captured but never selected, so the owner was never asked): a quote that is the ONLY
        // one on the work order is selected at once - selecting is what puts it through the no-approval limit / to the owner. With two or more
        // the agent chooses (the page says loudly while none is chosen); "use this one instead" only exists once one is selected.
        $explicitlySelected = (bool) ($validated['is_selected'] ?? false);
        unset($validated['is_selected']);

        $quote = $rentalWorkOrder->recordQuote($validated, $request->user());

        // Only quote on the work order (live ones; archived are out): there is nothing to choose between, so it IS the quote.
        $onlyQuote = $rentalWorkOrder->quotes()->whereNull('declined_at')->count() === 1;
        $selected = false;
        if ($explicitlySelected || $onlyQuote) {
            try {
                $rentalWorkOrder->selectQuote($quote, $request->user());
                $selected = true;
            } catch (\LogicException $e) {
                if ($explicitlySelected) {
                    throw $e;
                }

                return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote captured, but not selected: ' . $e->getMessage());
            }
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', $this->capturedMessage($rentalWorkOrder->fresh(), $selected));
    }

    /** What the capture did, in one plain sentence: who was asked, or that it was approved on the spot, or that nothing was chosen. */
    private function capturedMessage(RentalWorkOrder $workOrder, bool $selected): string
    {
        if (! $selected) {
            // Q1 (Johan, 9 Oct 2026): say what is REALLY true. Another quote may still be the chosen one, with the owner.
            $chosen = $workOrder->quotes()->where('is_selected', true)->first();
            if ($chosen) {
                $name = ($chosen->supplier?->name ?? 'The earlier quote') . ' (R' . number_format($chosen->ownerFacingAmount(), 2) . ')';
                $where = match ($workOrder->owner_approval_status) {
                    RentalWorkOrder::APPROVAL_PENDING => 'is with the owner',
                    RentalWorkOrder::APPROVAL_APPROVED => 'is approved by the owner',
                    default => 'is approved (within the owner\'s no-approval limit)',
                };

                return "Quote captured. {$name} is still the chosen quote and {$where}. Use Select on the new quote to put that one to the owner instead.";
            }

            return 'Quote captured. No quote is chosen yet - the owner has not been asked. Choose one with its Select button.';
        }
        if ($workOrder->owner_approval_status === RentalWorkOrder::APPROVAL_PENDING) {
            $names = app(\App\Services\Rentals\RentalWorkOrderService::class)->ownerNames($workOrder);

            $who = $names !== '' ? $names : 'the owner';
            if (! \App\Models\RentalPortalSetting::notifyLandlordOnDecisionNeededFor($workOrder->agency_id)) {
                return "Quote captured. It is above the property's no-approval limit, so {$who} must approve it on the portal (the approval email is switched off for this agency, so tell them).";
            }

            return "Quote captured and sent to {$who} for approval. The work order can be sent to the contractor once they approve.";
        }
        if ($workOrder->approval_basis === RentalWorkOrder::BASIS_NO_APPROVAL_LIMIT) {
            return 'Quote captured and approved automatically - it is within the property\'s no-approval limit. The work order can be sent to the contractor.';
        }

        return 'Quote captured and selected.';
    }

    /** Editable while the work order is still open — the reportable facts, not the lifecycle. */
    public function update(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);
        abort_if(in_array($rentalWorkOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true), 409, 'This work order has closed and its quotes can no longer be edited.');

        $validated = $request->validate([
            'agency_service_provider_id' => ['required', \App\Models\DealV2\AgencyServiceProvider::maintenanceExistsRule((int) $request->user()->effectiveAgencyId(), (int) $quote->agency_service_provider_id)],
            'amount' => ['required', 'numeric', 'min:0'],
            'quote_date' => ['required', 'date'],
            'detail_text' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ]);

        if (empty($validated['detail_text']) && !$request->hasFile('document') && !$quote->document_storage_path) {
            return back()->withErrors(['quote' => 'Attach the quote document, or enter its details — at least one is required.'])->withInput();
        }

        if ($request->hasFile('document')) {
            $validated['document_storage_path'] = $request->file('document')
                ->store("rental-work-order-quotes/{$rentalWorkOrder->id}", 'local');
        }
        unset($validated['document']);

        // BUILD 2 (§17.9.1a) — the agency's fee on an outside contractor's quote is recomputed when the amount changes (the estimate
        // wording snapshotted when the quote was captured stays as it was). The gate sees the OWNER-FACING amount, so a fee change counts as a price change.
        $before = [$quote->ownerFacingAmount(), (int) $quote->agency_service_provider_id];
        $fee = $quote->rental_job_card_id ? [] : RentalWorkOrderQuote::feeAttributes($rentalWorkOrder, (float) $validated['amount'], false);
        if ($quote->term_text) {
            unset($fee['term_text']);
        }
        $quote->update($validated + $fee);
        $priceOrSupplierChanged = $before !== [$quote->ownerFacingAmount(), (int) $quote->agency_service_provider_id];

        // The threshold gate is only as fresh as the amount it last saw —
        // an edit to the SELECTED quote's amount must re-evaluate the gate,
        // not leave owner_approval_status stale against a number nobody
        // approved (BUILD_STANDARD §2, input-space rule).
        // Re-derive ONLY when the price or the supplier actually changed:
        // that is the one deliberate case where a recorded owner approval is
        // dropped and re-approval is required (the owner approved a
        // different price/supplier). A note/date/document-only edit must not
        // silently wipe an approval the owner already gave (audit M1).
        if ($quote->is_selected && $priceOrSupplierChanged) {
            $rentalWorkOrder->selectQuote($quote->fresh(), $request->user());
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote updated.');
    }

    public function select(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);

        // A quote that is already the selected one (a first quote is selected on capture) is not selected a second time: that would put it
        // through the limit again and mail the owner a second time. The page has no Select button on it either.
        if ($quote->is_selected) {
            return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'That quote is already the selected one.');
        }

        try {
            $rentalWorkOrder->selectQuote($quote, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote selected.');
    }

    public function destroy(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);

        try {
            $rentalWorkOrder->archiveQuote($quote, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote archived.');
    }

    public function restore(Request $request, RentalWorkOrder $rentalWorkOrder, int $quote): RedirectResponse
    {
        $this->assertVisible($request, $rentalWorkOrder);
        $quoteModel = RentalWorkOrderQuote::withTrashed()->findOrFail($quote);
        abort_unless($quoteModel->rental_work_order_id === $rentalWorkOrder->id, 404);

        try {
            $rentalWorkOrder->restoreQuote($quoteModel, $request->user());
        } catch (\LogicException $e) {
            return back()->withErrors(['quote' => $e->getMessage()]);
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote restored.');
    }

    /**
     * §3.4c — private disk, gated: re-checks the quote genuinely belongs to
     * THIS work order (both already scoped to the caller's agency by
     * AgencyScope via route-model-binding) on every request regardless of
     * how the URL was reached, same discipline as
     * PropertyFileController::download().
     */
    public function download(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote)
    {
        $this->assertVisible($request, $rentalWorkOrder);
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);
        abort_unless($quote->document_storage_path, 404);
        abort_unless(Storage::disk('local')->exists($quote->document_storage_path), 404);

        return Storage::disk('local')->download($quote->document_storage_path);
    }
}

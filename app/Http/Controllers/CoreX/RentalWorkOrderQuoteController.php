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
        $wantsSelected = (bool) ($validated['is_selected'] ?? false);
        unset($validated['is_selected']);

        $quote = $rentalWorkOrder->recordQuote($validated, $request->user());

        if ($wantsSelected) {
            $rentalWorkOrder->selectQuote($quote, $request->user());
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote captured.');
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

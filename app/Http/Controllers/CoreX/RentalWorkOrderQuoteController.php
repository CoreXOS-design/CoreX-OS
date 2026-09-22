<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderQuote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * .ai/specs/rental-work-orders.md §3.4c — full CRUD + archive/restore on
 * quotes, per BUILD_STANDARD §1a. No sidebar entry — reachable from the
 * work order's own show screen, same as photos/updates/approvals.
 */
class RentalWorkOrderQuoteController extends Controller
{
    /**
     * Johan: "an upload or attach of the quote, or punching in the
     * details is whats needed" — neither path mandatory over the other,
     * but at least one is required (never let a bare amount+date reach
     * the DB with no evidence at all).
     */
    public function store(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $validated = $request->validate([
            'agency_service_provider_id' => ['required', 'exists:agency_service_providers,id'],
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
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);
        abort_if(in_array($rentalWorkOrder->status, [RentalWorkOrder::STATUS_COMPLETED, RentalWorkOrder::STATUS_CANCELLED], true), 409, 'This work order has closed and its quotes can no longer be edited.');

        $validated = $request->validate([
            'agency_service_provider_id' => ['required', 'exists:agency_service_providers,id'],
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

        $quote->update($validated);

        // The threshold gate is only as fresh as the amount it last saw —
        // an edit to the SELECTED quote's amount must re-evaluate the gate,
        // not leave owner_approval_status stale against a number nobody
        // approved (BUILD_STANDARD §2, input-space rule).
        if ($quote->is_selected) {
            $rentalWorkOrder->selectQuote($quote->fresh(), $request->user());
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote updated.');
    }

    public function select(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote): RedirectResponse
    {
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
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);

        $rentalWorkOrder->archiveQuote($quote, $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote archived.');
    }

    public function restore(Request $request, RentalWorkOrder $rentalWorkOrder, int $quote): RedirectResponse
    {
        $quoteModel = RentalWorkOrderQuote::withTrashed()->findOrFail($quote);
        abort_unless($quoteModel->rental_work_order_id === $rentalWorkOrder->id, 404);

        $rentalWorkOrder->restoreQuote($quoteModel, $request->user());

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)->with('success', 'Quote restored.');
    }

    /**
     * §3.4c — private disk, gated: re-checks the quote genuinely belongs to
     * THIS work order (both already scoped to the caller's agency by
     * AgencyScope via route-model-binding) on every request regardless of
     * how the URL was reached, same discipline as
     * PropertyFileController::download().
     */
    public function download(RentalWorkOrder $rentalWorkOrder, RentalWorkOrderQuote $quote)
    {
        abort_unless($quote->rental_work_order_id === $rentalWorkOrder->id, 404);
        abort_unless($quote->document_storage_path, 404);
        abort_unless(Storage::disk('local')->exists($quote->document_storage_path), 404);

        return Storage::disk('local')->download($quote->document_storage_path);
    }
}

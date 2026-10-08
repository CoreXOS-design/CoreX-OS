<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderInvoice;
use App\Services\Rentals\RentalWorkOrderInvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rental-work-orders.md §17.31 — the supplier's invoice documents on a work order: file, list (on the work order's
 * own screen), change / replace the file, share with the owner, archive, restore, download. No sidebar entry — it lives on the
 * work order, like quotes and photos. Every route is behind `rental_work_orders.manage_invoices` AND the work order's own
 * OWN/BRANCH/AGENCY record scope (the same guard as its detail page), and every invoice is re-checked as belonging to THIS work
 * order, so a direct URL to another work order's invoice is a 404. All writes go through {@see RentalWorkOrderInvoiceService}.
 */
class RentalWorkOrderInvoiceController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalWorkOrderInvoiceService $invoices)
    {
    }

    public function store(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guard($rentalWorkOrder);
        $data = $request->validate($this->rules($rentalWorkOrder, true));

        try {
            $invoice = $this->invoices->upload($rentalWorkOrder, $this->facts($request, $data), $request->file('document'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()])->withInput();
        }

        return $this->done($rentalWorkOrder, 'Invoice ' . $invoice->invoice_number . ' filed.');
    }

    /** Change the facts, and/or replace the file (send a new `document`), and/or change who it is shared with. */
    public function update(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderInvoice $invoice): RedirectResponse
    {
        $this->guard($rentalWorkOrder);
        abort_unless($invoice->rental_work_order_id === $rentalWorkOrder->id, 404);
        $data = $request->validate($this->rules($rentalWorkOrder, false));

        try {
            $this->invoices->update($invoice, $this->facts($request, $data), $request->file('document'), $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()])->withInput();
        }

        return $this->done($rentalWorkOrder, 'Invoice updated.');
    }

    /** The "Share with owner" tick on its own (one click on the list, no form). */
    public function share(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderInvoice $invoice): RedirectResponse
    {
        $this->guard($rentalWorkOrder);
        abort_unless($invoice->rental_work_order_id === $rentalWorkOrder->id, 404);
        $share = $request->boolean('share_with_owner');
        $this->invoices->setSharing($invoice, $share, $request->user());

        return $this->done($rentalWorkOrder, $share ? 'Invoice is now visible to the owner.' : 'Invoice is no longer visible to the owner.');
    }

    public function destroy(Request $request, RentalWorkOrder $rentalWorkOrder, RentalWorkOrderInvoice $invoice): RedirectResponse
    {
        $this->guard($rentalWorkOrder);
        abort_unless($invoice->rental_work_order_id === $rentalWorkOrder->id, 404);
        $this->invoices->archive($invoice, $request->user());

        return $this->done($rentalWorkOrder, 'Invoice archived.');
    }

    public function restore(Request $request, RentalWorkOrder $rentalWorkOrder, int $invoice): RedirectResponse
    {
        $this->guard($rentalWorkOrder);
        $model = RentalWorkOrderInvoice::withTrashed()->findOrFail($invoice);
        abort_unless($model->rental_work_order_id === $rentalWorkOrder->id, 404);

        try {
            $this->invoices->restore($model, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['invoice' => $e->getMessage()]);
        }

        return $this->done($rentalWorkOrder, 'Invoice restored.');
    }

    /** Private disk, gated: the invoice must belong to THIS work order, which the caller may open. An archived invoice stays viewable here. */
    public function download(Request $request, RentalWorkOrder $rentalWorkOrder, int $invoice)
    {
        $this->guard($rentalWorkOrder);
        $model = RentalWorkOrderInvoice::withTrashed()->findOrFail($invoice);
        abort_unless($model->rental_work_order_id === $rentalWorkOrder->id, 404);

        $response = $this->invoices->response($model, $request->boolean('download'));
        abort_unless($response, 404);

        return $response;
    }

    private function guard(RentalWorkOrder $workOrder): void
    {
        $this->guardRentalRecordScope($workOrder, 'rental_work_orders', $workOrder->property?->branch_id);
    }

    /** @return array<string, mixed> */
    private function rules(RentalWorkOrder $workOrder, bool $creating): array
    {
        return [
            'invoice_number' => [$creating ? 'required' : 'sometimes', 'string', 'max:100'],
            'invoice_date' => [$creating ? 'required' : 'sometimes', 'date', 'after_or_equal:2000-01-01', 'before_or_equal:today'],
            'amount' => [$creating ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'agency_service_provider_id' => ['nullable', \App\Models\DealV2\AgencyServiceProvider::maintenanceExistsRule((int) $workOrder->agency_id)],
            'supplier_name' => ['nullable', 'string', 'max:191'],
            'share_with_owner' => ['nullable', 'boolean'],
            'document' => $this->invoices->fileRules($workOrder->agency_id, $creating),
        ];
    }

    /**
     * The facts to hand the service. `share_with_owner` is passed only when the form actually carried the tick (the hidden "0"
     * companion), so a partial post can never un-share an invoice by omission.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function facts(Request $request, array $data): array
    {
        unset($data['document']);
        if ($request->has('share_with_owner')) {
            $data['share_with_owner'] = $request->boolean('share_with_owner');
        } else {
            unset($data['share_with_owner']);
        }

        return $data;
    }

    private function done(RentalWorkOrder $workOrder, string $message): RedirectResponse
    {
        return redirect()->to(route('corex.rental-work-orders.show', $workOrder) . '#invoices')->with('success', $message);
    }
}

<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalPayerRuling;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalWhoPaysService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22.13.3 - the agent's side of "Who pays" on the work order: record / correct what was found and the
 * cause, make the ruling, open its evidence. Permission `rental_work_orders.manage_completion`; own/branch/agency scope as every work-order write.
 */
class RentalWhoPaysController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private RentalWhoPaysService $service)
    {
    }

    public function findings(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($this->service->isFaultJob($rentalWorkOrder), 404);

        $data = $request->validate([
            'found_text' => ['nullable', 'string', 'max:2000'],
            'cause_id' => ['nullable', 'integer'],
            'cause_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $changed = $this->service->recordFindings($rentalWorkOrder, $data['found_text'] ?? null, ($data['cause_id'] ?? null) ? (int) $data['cause_id'] : null, $data['cause_note'] ?? null, $request->user()->name . ' (agent)', $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['who_pays' => $e->getMessage()])->withInput();
        }

        return redirect()->to(route('corex.rental-work-orders.show', $rentalWorkOrder) . '#who-pays')->with('success', $changed ? 'What was found and the cause are saved.' : 'Nothing changed.');
    }

    public function rule(Request $request, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        abort_unless($this->service->isFaultJob($rentalWorkOrder), 404);

        $data = $request->validate([
            'payer' => ['required', Rule::in(array_keys(RentalPayerRuling::PAYERS))],
            'tenant_percent' => ['nullable', 'integer', 'min:1', 'max:99'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'evidence' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:' . RentalPayerRuling::EVIDENCE_MAX_KB],
        ], [
            'payer.required' => 'Pick who pays.',
            'reason.required' => 'Give the reason for the ruling.',
            'tenant_percent.min' => 'For a shared cost, say what percentage the tenant pays (1 to 99).',
            'tenant_percent.max' => 'For a shared cost, say what percentage the tenant pays (1 to 99).',
            'evidence.mimes' => 'The evidence must be a JPG, PNG, WEBP or PDF.',
            'evidence.max' => 'The evidence file is too large (10 MB at most).',
        ]);

        try {
            $this->service->rule($rentalWorkOrder, $request->user(), $data['payer'], isset($data['tenant_percent']) ? (int) $data['tenant_percent'] : null, $data['reason'], $request->file('evidence'));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return back()->withErrors(['who_pays' => $e->getMessage()])->withInput();
        }

        return redirect()->to(route('corex.rental-work-orders.show', $rentalWorkOrder) . '#who-pays')->with('success', 'Ruling saved. The owner and the tenant have been told. Nothing was charged.');
    }

    public function evidence(Request $request, RentalWorkOrder $rentalWorkOrder, int $ruling): StreamedResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);
        $row = RentalPayerRuling::withoutGlobalScopes()->where('rental_work_order_id', $rentalWorkOrder->id)->where('agency_id', $rentalWorkOrder->agency_id)->find($ruling);
        abort_unless($row && $row->evidence_path && Storage::disk('local')->exists($row->evidence_path), 404);

        return Storage::disk('local')->download($row->evidence_path, $row->evidence_name ?: 'evidence');
    }
}

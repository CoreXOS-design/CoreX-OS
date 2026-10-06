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
 * .ai/specs/rental-work-orders.md §17.8 — emergency work: the owner ALWAYS agrees and the office captures it. There is no
 * override anywhere; this form IS the owner's agreement, recorded with no cost attached (there is no amount field on purpose).
 * Record (who at the owner's end, how, when, why, optional attachment), void with a reason (a mistake is voided and
 * re-recorded, never edited or deleted), and download the attachment (private disk, scoped like the work order itself).
 */
class RentalEmergencyApprovalController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function store(Request $request, RentalApprovalGateService $gate, RentalWorkOrder $rentalWorkOrder): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalWorkOrder, 'rental_work_orders', $rentalWorkOrder->property?->branch_id);

        $validated = $request->validate([
            'approved_by_name' => ['required', 'string', 'max:191'],
            'owner_contact_id' => ['nullable', 'integer'],
            'approved_via' => ['required', Rule::in(RentalEmergencyApproval::VIAS)],
            'approved_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:2000'],
            'reported_by_crew_name' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif,pdf', 'max:10240'],
        ], [
            'approved_by_name.required' => 'Say who at the owner\'s end agreed to the work.',
            'approved_via.required' => 'Say how the owner agreed.',
            'approved_at.required' => 'Say when the owner agreed.',
            'reason.required' => 'Say why this is emergency work.',
        ]);

        $tz = $rentalWorkOrder->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        $validated['approved_at'] = Carbon::parse($validated['approved_at'], $tz)->setTimezone(config('app.timezone'));

        $path = null;
        if ($request->hasFile('attachment')) {
            $path = $request->file('attachment')->store("rental-emergency-approvals/{$rentalWorkOrder->id}", 'local');
            $validated['attachment_path'] = $path;
        }
        unset($validated['attachment']);

        try {
            $gate->recordEmergency($rentalWorkOrder, $validated, $request->user());
        } catch (\InvalidArgumentException|\LogicException $e) {
            if ($path) {
                Storage::disk('local')->delete($path); // nothing recorded points at it
            }

            return back()->withErrors(['emergency' => $e->getMessage()])->withInput();
        }

        return redirect()->route('corex.rental-work-orders.show', $rentalWorkOrder)
            ->with('success', 'Emergency approval recorded — the work can go ahead. Costs are captured and settled afterwards.');
    }

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

<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Services\Rentals\RentalInspectionCopiesService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inspections.md §45.6 (Build I-4) — the per-recipient Resend behind the inspection page's "Copies sent"
 * panel. A separate controller on purpose: the recording controller is busy with signing and capture, and this is one
 * narrow concern. The recipient is resolved on the SERVER from a delivery-log row of THIS inspection — an address
 * posted by the browser is never trusted.
 */
class RentalInspectionCopiesController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function resendRecipient(Request $request, RentalInspection $rentalInspection, RentalInspectionCopiesService $copies): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);
        $back = redirect()->route('corex.rental-inspections.show', $rentalInspection);

        if ($rentalInspection->status !== RentalInspection::STATUS_COMPLETED) {
            return $back->withErrors(['copies' => 'Copies can only be re-sent once the inspection is completed.']);
        }

        $validated = $request->validate(['log_id' => ['required', 'integer']]);

        $row = $copies->logRowFor($rentalInspection, (int) $validated['log_id']);
        if (! $row) {
            return $back->withErrors(['copies' => 'That copy was not found on this inspection.']);
        }

        $address = $copies->currentAddressFor($rentalInspection, $row);
        if ($address === null) {
            return $back->withErrors(['copies' => 'That person has no email address on file (or is no longer a recipient). Add an address to their contact, then try again.']);
        }

        $results = $copies->fileAndSend($rentalInspection, autoOnly: false, triggeredBy: $request->user(), onlyEmails: [$address]);

        $sent = collect($results)->contains(fn ($r) => $r['status'] === 'sent');

        return $sent
            ? $back->with('success', 'Copy sent.')
            : $back->withErrors(['copies' => 'The copy could not be sent: ' . (collect($results)->pluck('error')->filter()->first() ?: 'unknown problem') . '. The inspector has been alerted.']);
    }
}

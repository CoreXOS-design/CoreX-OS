<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\FaultInspectionService;
use App\Services\Rentals\RentalAuthorisationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * .ai/specs/rentals-faults-work-orders.md section 22 - the fault page's Decision 1 actions: "Send <name> to inspect", "Send to owner to authorise",
 * "Record the owner's decision", and "Confirmed with the tenant". The rules live in FaultInspectionService / RentalAuthorisationService; this only
 * checks the request and the agency scope and answers in plain words.
 */
class RentalFaultAuthorityController extends Controller
{
    use AuthorizesRentalRecordScope;

    private function back(RentalFaultReport $fault): string
    {
        return route('corex.rental-fault-reports.show', $fault);
    }

    private function choice(Request $request): array
    {
        $v = $request->validate([
            'assignment_type' => ['required', Rule::in([RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, RentalWorkOrder::ASSIGNMENT_INTERNAL])],
            'agency_service_provider_id' => ['nullable', 'integer'],
            'rental_crew_id' => ['nullable', 'integer'],
        ], ['assignment_type.required' => 'Choose who goes to look first.']);

        return $v;
    }

    /** "Send <name> to inspect". */
    public function inspect(Request $request, FaultInspectionService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $choice = $this->choice($request);

        try {
            $made = $service->sendToInspect($rentalFaultReport, $request->user(), $choice);
        } catch (\LogicException $e) {
            return redirect($this->back($rentalFaultReport))->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        $told = ' The owner has been told.';
        if ($made['job_card']) {
            return redirect()->route('corex.rental-job-cards.show', $made['job_card'])->with('success', 'Sent - job card ready for the crew.' . $told);
        }

        return redirect()->route('corex.rental-work-orders.show', $made['work_order'])->with('success', 'Sent to inspect - the contractor has the job and their link.' . $told);
    }

    /** "Send to owner to authorise". */
    public function sendToOwner(Request $request, FaultInspectionService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $v = $request->validate([
            'assignment_type' => ['nullable', Rule::in([RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, RentalWorkOrder::ASSIGNMENT_INTERNAL])],
            'agency_service_provider_id' => ['nullable', 'integer'],
            'rental_crew_id' => ['nullable', 'integer'],
        ]);

        try {
            $service->sendToOwnerToAuthorise($rentalFaultReport, $request->user(), $v);
        } catch (\LogicException $e) {
            return redirect($this->back($rentalFaultReport))->withErrors(['rental_fault_report' => $e->getMessage()]);
        }

        return redirect($this->back($rentalFaultReport))->with('success', 'Sent to the owner to authorise. They can now see it and choose.');
    }

    /** "Record the owner's decision" (Decision 1). */
    public function recordOwnerDecision(Request $request, RentalAuthorisationService $service, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $v = $request->validate([
            'owner_contact_id' => ['required', 'integer'],
            'decision' => ['required', Rule::in(['authorise', 'declined'])],
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
            $service->recordOwnerDecision($request->user(), $rentalFaultReport, $v, $request->file('proof'));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect($this->back($rentalFaultReport))->withErrors(['owner_decision' => $e->getMessage()])->withInput();
        }

        return redirect($this->back($rentalFaultReport))->with('success', "The owner's decision is recorded. The owner has been emailed what you wrote down.");
    }

    /** Section 22.7 - Resume a job the owner stopped: needs a fresh authority, so it is the owner's decision recorded by the agent. */
    public function resume(Request $request, \App\Services\Rentals\RentalOwnerHoldService $holds, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $v = $request->validate([
            'owner_contact_id' => ['required', 'integer'],
            'told_via' => ['required', Rule::in(RentalAuthorisation::VIAS)],
            'told_at' => ['nullable', 'date'],
            'note' => ['required', 'string', 'max:4000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:20480'],
        ], [
            'owner_contact_id.required' => 'Pick which owner told you.',
            'told_via.required' => 'Say how the owner told you: phone call, WhatsApp or email.',
            'note.required' => 'Write down what the owner said - it is required.',
            'proof.mimes' => 'The proof must be a picture (a screenshot of the WhatsApp or email).',
        ]);

        try {
            $holds->resumeByAgent($request->user(), $rentalFaultReport, $v, $request->file('proof'));
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect($this->back($rentalFaultReport))->withErrors(['owner_decision' => $e->getMessage()])->withInput();
        }

        return redirect($this->back($rentalFaultReport))->with('success', "Resumed on the owner's go-ahead. The owner has been emailed what you wrote down. If the contractor or crew were told to wait, send the job again.");
    }

    /** Section 22.7 - the agent's answer to the owner's "Query this" question (saved under their name, never edited). */
    public function answerQuery(Request $request, \App\Services\Rentals\RentalOwnerHoldService $holds, RentalFaultReport $rentalFaultReport, int $query): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $row = $holds->queriesFor($rentalFaultReport)->firstWhere('id', $query);
        abort_unless($row, 404);
        $v = $request->validate(['answer' => ['required', 'string', 'max:2000']], ['answer.required' => 'Write your answer first.']);

        try {
            $holds->answer($request->user(), $row, $v['answer']);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return redirect($this->back($rentalFaultReport))->withErrors(['owner_query' => $e->getMessage()]);
        }

        return redirect($this->back($rentalFaultReport))->with('success', 'Your answer is saved and the owner has been emailed it.');
    }

    /** "Confirmed with the tenant": how, when, a note. A later one is added; every one stays in the history. */
    public function confirmWithTenant(Request $request, RentalFaultReport $rentalFaultReport): RedirectResponse
    {
        $this->guardRentalRecordScope($rentalFaultReport, 'rental_fault_reports', $rentalFaultReport->property?->branch_id);
        $v = $request->validate([
            'via' => ['required', Rule::in(RentalFaultReport::CONFIRMED_VIAS)],
            'at' => ['required', 'date'],
            'note' => ['required', 'string', 'max:2000'],
        ], [
            'via.required' => 'Say how you confirmed it with the tenant.',
            'at.required' => 'Say when you confirmed it.',
            'note.required' => 'Write down what the tenant said.',
        ]);
        if (in_array($rentalFaultReport->status, [RentalFaultReport::STATUS_CANCELLED], true)) {
            return redirect($this->back($rentalFaultReport))->withErrors(['tenant_confirmation' => 'This fault was cancelled.']);
        }
        $tz = $rentalFaultReport->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
        try {
            $at = \Illuminate\Support\Carbon::parse($v['at'], $tz)->utc();
        } catch (\Throwable) {
            return redirect($this->back($rentalFaultReport))->withErrors(['tenant_confirmation' => 'That date and time is not valid.'])->withInput();
        }
        if ($at->gt(now()->addMinutes(5))) {
            return redirect($this->back($rentalFaultReport))->withErrors(['tenant_confirmation' => 'The time cannot be in the future.'])->withInput();
        }

        $rentalFaultReport->forceFill([
            'tenant_confirmed_via' => $v['via'], 'tenant_confirmed_at' => $at, 'tenant_confirmed_note' => trim($v['note']),
            'tenant_confirmed_by_user_id' => $request->user()->id, 'tenant_confirmed_recorded_at' => now(),
        ])->save();
        $rentalFaultReport->recordHistory(RentalFaultReportUpdate::TYPE_TENANT_CONFIRMED, $request->user(),
            'Confirmed with the tenant by ' . RentalFaultReport::confirmedViaLabel($v['via']) . ' on ' . $at->copy()->setTimezone($tz)->format('j M Y H:i') . ': "' . trim($v['note']) . '"');

        return redirect($this->back($rentalFaultReport))->with('success', 'Saved: confirmed with the tenant.');
    }
}
